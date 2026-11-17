<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Imagine\Exception\RuntimeException as ImagineRuntimeException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Imagick\Image as ImagickImage;
use Imagick;
use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nelson\Resizer\Exceptions\DimensionsTooLargeException;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\SecurityException;
use Nette\IOException;
use Nette\Utils\FileSystem;
use RuntimeException;

final class Resizer implements IResizer
{
	public const string FORMAT_SUFFIX_JPG = 'jpg';
	public const string FORMAT_SUFFIX_WEBP = 'webp';
	public const string FORMAT_SUFFIX_AVIF = 'avif';
	public const string FORMAT_SUFFIX_PNG = 'png';
	public const string FORMAT_SUFFIX_JXL = 'jxl';

	public const array FORMAT_SUFFIXES_JPG = [
		'jpg',
		'jpeg',
		'jfif',
	];

	/** Formats the output can be negotiated to (by the Accept header and the config) */
	public const array MODERN_FORMATS = [self::FORMAT_SUFFIX_WEBP, self::FORMAT_SUFFIX_AVIF, self::FORMAT_SUFFIX_JXL];

	public const string MIME_TYPE_WEBP = 'image/webp';
	public const string MIME_TYPE_AVIF = 'image/avif';
	public const string MIME_TYPE_JXL = 'image/jxl';

	/** MIME types of the output formats (libmagic may not know newer formats) */
	public const array MIME_TYPES = [
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png' => 'image/png',
		'gif' => 'image/gif',
		self::FORMAT_SUFFIX_WEBP => self::MIME_TYPE_WEBP,
		self::FORMAT_SUFFIX_AVIF => self::MIME_TYPE_AVIF,
		self::FORMAT_SUFFIX_JXL => self::MIME_TYPE_JXL,
	];

	/**
	 * Source image types the resizer opens. Anything else is refused, so ImageMagick never
	 * parses SVG, MVG, MSL, PDF and other formats with a history of vulnerabilities.
	 * JXL sources only when the server supports JXL.
	 */
	public const array SOURCE_SUFFIXES = ['jpg', 'jpeg', 'jfif', 'png', 'gif', 'webp', 'avif', 'jxl'];
	public const array SOURCE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/jxl'];

	private AbstractImagine $imagine;
	private string $cacheDir;


	public function __construct(
		private readonly ResizerConfig $config,
		private readonly OutputFormat $outputFormat,
	) {
		$this->cacheDir = $this->config->getPublicCacheDir()
			?? $this->config->getTempDir() . $this->config->getCache();
		FileSystem::createDir($this->cacheDir);

		/** @var class-string<AbstractImagine> $library */
		$library = 'Imagine\\' . $this->config->getLibrary() . '\\Imagine';
		$this->imagine = new $library;
	}


	public function process(
		string $path,
		?string $params,
		?string $format = null,
	): string {
		// validate everything before touching the filesystem
		$geometry = new Geometry($params);
		$this->assertRequestedDimensionsAllowed($geometry->getResizerParams());
		$sourceImagePath = $this->getSourceImagePath($path);
		$thumbnailPath = $this->getThumbnailPath($path, $params, $format);

		if ($this->isThumbnailFresh($thumbnailPath, $sourceImagePath)) {
			return $thumbnailPath;
		}

		// Concurrent requests for the same thumbnail (a page full of new images, CDN fetching in parallel)
		// would all encode it - only one does, the others wait and use its result.
		$lock = $this->acquireLock($thumbnailPath);

		try {
			clearstatcache(true, $thumbnailPath);

			if (!$this->isThumbnailFresh($thumbnailPath, $sourceImagePath)) {
				$this->generate($sourceImagePath, $thumbnailPath, $geometry);
			}
		} finally {
			$this->releaseLock($lock);
		}

		return $thumbnailPath;
	}


	private function generate(string $sourceImagePath, string $thumbnailPath, Geometry $geometry): void
	{
		try {
			$thumbnail = $this->processImage($sourceImagePath, $geometry);
		} catch (ImagineRuntimeException) {
			throw new ImageNotFoundOrReadableException('Unable to open image - wrong permissions, empty or corrupted.');
		}

		if ($this->config->isStrip()) {
			// remove all comments & metadata
			$thumbnail->strip();
		}

		$format = strtolower(pathinfo($thumbnailPath, PATHINFO_EXTENSION));

		// progressive JPEG; PNG interlacing (Adam7) makes files bigger, WEBP/AVIF/JXL ignore it
		if ($this->config->isInterlace() && in_array($format, self::FORMAT_SUFFIXES_JPG, true)) {
			$thumbnail->interlace(ImageInterface::INTERLACE_LINE);
		}

		// encoder speed/effort, Imagine has no options for it
		if ($thumbnail instanceof ImagickImage) {
			foreach ($this->config->getImagickEncoderOptions($format) as $option => $value) {
				$thumbnail->getImagick()->setOption($option, $value);
			}
		}

		FileSystem::createDir(dirname($thumbnailPath));

		// Write to a temporary file first and rename it, so a partially written thumbnail is never served
		// (in static mode the file may be served directly by the web server as soon as it exists).
		// The temporary name keeps the extension, Imagine derives the output format from it.
		$tempPath = dirname($thumbnailPath) . DIRECTORY_SEPARATOR
			. '.tmp-' . bin2hex(random_bytes(6)) . '-' . basename($thumbnailPath);

		try {
			$thumbnail->save($tempPath, $this->config->getOptions($geometry->getResizerParams()->getQuality()));
			// plain rename() is atomic and replaces the target (FileSystem::rename() deletes it first)
			if (!@rename($tempPath, $thumbnailPath)) {
				throw new RuntimeException(sprintf("Unable to write thumbnail '%s'.", $thumbnailPath));
			}
		} finally {
			if (is_file($tempPath)) {
				@unlink($tempPath);
			}
		}
	}


	/**
	 * Exclusive lock of the thumbnail, in tempDir (the cache dir may be public). Null when the lock file
	 * can't be created - the thumbnail is generated without it then (the write is atomic anyway).
	 * @return array{resource, string}|null
	 */
	private function acquireLock(string $thumbnailPath): ?array
	{
		$dir = rtrim($this->config->getTempDir(), '/\\') . '/resizer-locks';
		$path = $dir . '/' . md5($thumbnailPath) . '.lock';

		try {
			FileSystem::createDir($dir);
		} catch (IOException) {
			return null;
		}

		$handle = @fopen($path, 'c');

		if ($handle === false) {
			return null;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			return null;
		}

		return [$handle, $path];
	}


	/** @param array{resource, string}|null $lock */
	private function releaseLock(?array $lock): void
	{
		if ($lock === null) {
			return;
		}

		[$handle, $path] = $lock;
		// removed while still locked: the waiting requests hold their handles and find the thumbnail done,
		// a race with a new request can at worst encode the thumbnail twice, never corrupt it
		@unlink($path);
		flock($handle, LOCK_UN);
		fclose($handle);
	}


	public function getSourceImagePath(string $path): string
	{
		$this->assertSafePath($path);

		$suffix = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		if (
			!in_array($suffix, self::SOURCE_SUFFIXES, true)
			|| ($suffix === self::FORMAT_SUFFIX_JXL && !$this->config->isJxlSupportedByServer())
		) {
			throw new ImageNotFoundOrReadableException('Source image type not supported.');
		}

		$fullPath = realpath($this->config->getWwwDir() . DIRECTORY_SEPARATOR . ltrim($path, '/'));

		if ($fullPath === false || !is_file($fullPath) || !is_readable($fullPath)) {
			throw new ImageNotFoundOrReadableException('Source image not found or not readable.');
		}

		$mimeType = @mime_content_type($fullPath);

		if (!in_array($mimeType, self::SOURCE_MIME_TYPES, true)) {
			throw new ImageNotFoundOrReadableException('Source image type not supported.');
		}

		return $fullPath;
	}


	/**
	 * Path of the thumbnail in the cache directory. Does not check whether it exists.
	 */
	public function getThumbnailPath(
		string $path,
		?string $params,
		?string $format = null,
	): string {
		$this->assertSafePath($path);

		if ($params === null || !preg_match(ResizerParamsParser::PATTERN, $params)) {
			throw new CouldNotParseResizerParamsException('Wrong params format.');
		}

		return $this->cacheDir . ltrim($path, '/') . DIRECTORY_SEPARATOR
			. $params . '.' . $this->outputFormat->getOutputFormat($path, $format);
	}


	public function thumbnailExists(string $path): bool
	{
		return is_file($path) && (bool) filesize($path);
	}


	/**
	 * The thumbnail exists and is not older than the source image (a changed source is regenerated).
	 */
	private function isThumbnailFresh(string $thumbnailPath, string $sourceImagePath): bool
	{
		return $this->thumbnailExists($thumbnailPath)
			&& filemtime($thumbnailPath) >= filemtime($sourceImagePath);
	}


	/**
	 * Refuses traversal ("..") segments, NUL bytes and backslashes (a path separator on Windows).
	 * Symlinks inside wwwDir are intentionally allowed (e.g. shared storage in deployments).
	 */
	private function assertSafePath(string $path): void
	{
		if (
			$path === ''
			|| str_contains($path, "\0")
			|| str_contains($path, '\\')
			|| in_array('..', explode('/', $path), true)
		) {
			throw new SecurityException('Attempt to access files outside permitted path.');
		}
	}


	private function assertRequestedDimensionsAllowed(ResizerParams $params): void
	{
		if (
			($params->getWidth() ?? 0) > $this->config->getMaxWidth()
			|| ($params->getHeight() ?? 0) > $this->config->getMaxHeight()
		) {
			throw new DimensionsTooLargeException(sprintf(
				'Requested dimensions exceed the limit of %dx%d.',
				$this->config->getMaxWidth(),
				$this->config->getMaxHeight(),
			));
		}
	}


	private function assertOutputDimensionsAllowed(Dimensions $dimensions): void
	{
		// a crop may need an intermediate image larger in one dimension, so only the area is limited
		if ($dimensions->getWidth() * $dimensions->getHeight() > $this->config->getMaxWidth() * $this->config->getMaxHeight()) {
			throw new DimensionsTooLargeException('Output dimensions exceed the limit.');
		}
	}


	private function processImage(
		string $imagePathFull,
		Geometry $geometry,
	): ImageInterface {
		$image = $this->imagine->open($imagePathFull);

		// before the size is read - a rotation by 90° swaps the dimensions
		$this->autoOrient($image);

		$imageCurSize = $image->getSize();

		$sourceDimensions = new Dimensions(
			Helpers::getPositiveInt($imageCurSize->getWidth()),
			Helpers::getPositiveInt($imageCurSize->getHeight()),
		);

		$imageOutputSize = $geometry->calculateNewSize($sourceDimensions);
		$this->assertOutputDimensionsAllowed($imageOutputSize);

		$image->resize(new Box($imageOutputSize->getWidth(), $imageOutputSize->getHeight()));

		if ($geometry->getResizerParams()->isCrop()) {
			$image->crop(
				$geometry->getCropPoint($imageOutputSize),
				new Box(
					Helpers::getPositiveInt($geometry->getResizerParams()->getWidth()),
					Helpers::getPositiveInt($geometry->getResizerParams()->getHeight()),
				),
			);
		}

		return $image;
	}


	/**
	 * Rotates/flips the image by its EXIF orientation (photos from phones and cameras), the tag itself
	 * is removed by strip() or reset, so the image is never rotated twice. The orientation is read
	 * from the EXIF metadata (needs ext-exif), Imagick reads it on its own as a fallback.
	 * Same transformations as Imagine\Filter\Basic\Autorotate.
	 */
	private function autoOrient(ImageInterface $image): void
	{
		$orientation = $image->metadata()['ifd0.Orientation'] ?? null;
		$imagick = $image instanceof ImagickImage ? $image->getImagick() : null;

		if ($orientation === null && $imagick !== null) {
			$orientation = $imagick->getImageOrientation();
		}

		[$flip, $angle] = match (is_numeric($orientation) ? (int) $orientation : 0) {
			2 => [true, 0], // top-right
			3 => [false, 180], // bottom-right
			4 => [true, 180], // bottom-left
			5 => [true, -90], // left-top
			6 => [false, 90], // right-top
			7 => [true, 90], // right-bottom
			8 => [false, -90], // left-bottom
			default => [false, 0], // top-left or unknown
		};

		if ($flip) {
			$image->flipHorizontally();
		}

		if ($angle !== 0) {
			$image->rotate($angle);
		}

		// the pixels are upright now, metadata kept with strip: false must not rotate the image again
		$imagick?->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
	}
}
