<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Imagine\Exception\RuntimeException as ImagineRuntimeException;
use Imagine\Image\AbstractImagine;
use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nelson\Resizer\Exceptions\DimensionsTooLargeException;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\ResizerException;
use Nelson\Resizer\Exceptions\SecurityException;
use Nelson\Resizer\Image\ImageLoader;
use Nelson\Resizer\Image\ImageProcessor;
use Nelson\Resizer\Image\SourceDimensionsReader;
use Nelson\Resizer\Image\ThumbnailWriter;
use Nette\Utils\FileSystem;

/**
 * Validates requests (paths, params, limits) and generates thumbnails into the cache directory.
 * The image work is done by the classes in Nelson\Resizer\Image.
 */
final class Resizer implements IResizer
{
	public const string FORMAT_SUFFIX_JPG = 'jpg';
	public const string FORMAT_SUFFIX_WEBP = 'webp';
	public const string FORMAT_SUFFIX_AVIF = 'avif';
	public const string FORMAT_SUFFIX_PNG = 'png';
	public const string FORMAT_SUFFIX_GIF = 'gif';
	public const string FORMAT_SUFFIX_JXL = 'jxl';

	public const array FORMAT_SUFFIXES_JPG = [
		'jpg',
		'jpeg',
		'jfif',
	];

	/** Formats the output can be negotiated to (by the Accept header and the config) */
	public const array MODERN_FORMATS = [self::FORMAT_SUFFIX_WEBP, self::FORMAT_SUFFIX_AVIF, self::FORMAT_SUFFIX_JXL];

	/** Formats Imagick can write animations in */
	public const array ANIMATION_FORMATS = [self::FORMAT_SUFFIX_GIF, self::FORMAT_SUFFIX_WEBP];

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

	private string $cacheDir;
	private ImageProcessor $processor;
	private ThumbnailWriter $writer;
	private SourceDimensionsReader $dimensionsReader;


	public function __construct(
		private readonly ResizerConfig $config,
		private readonly OutputFormat $outputFormat,
	) {
		$this->cacheDir = $this->config->getCacheDir();
		FileSystem::createDir($this->cacheDir);

		/** @var class-string<AbstractImagine> $library */
		$library = 'Imagine\\' . $this->config->getLibrary() . '\\Imagine';
		$imagine = new $library;

		$this->processor = new ImageProcessor(new ImageLoader($imagine, $config), $config);
		$this->writer = new ThumbnailWriter($config);
		$this->dimensionsReader = new SourceDimensionsReader($imagine);
	}


	public function process(
		string $path,
		?string $params,
		?string $format = null,
	): string {
		// validate everything before touching the filesystem
		$geometry = new Geometry($params, $this->config->isUpscaleAllowed());
		$this->assertRequestedDimensionsAllowed($geometry->getResizerParams());
		$sourceImagePath = $this->getSourceImagePath($path);
		$thumbnailPath = $this->getThumbnailPath($path, $params, $format);

		if ($this->isThumbnailFresh($thumbnailPath, $sourceImagePath)) {
			return $thumbnailPath;
		}

		// Concurrent requests for the same thumbnail (a page full of new images, CDN fetching in parallel)
		// would all encode it - only one does, the others wait and use its result.
		$lock = FileLock::acquire(rtrim($this->config->getTempDir(), '/\\') . '/resizer-locks', $thumbnailPath);

		try {
			clearstatcache(true, $thumbnailPath);

			if (!$this->isThumbnailFresh($thumbnailPath, $sourceImagePath)) {
				$this->generate($sourceImagePath, $thumbnailPath, $geometry);
			}
		} finally {
			$lock?->release();
		}

		return $thumbnailPath;
	}


	public function getOutputDimensions(string $path, ?string $params): ?Dimensions
	{
		try {
			$geometry = new Geometry($params ?? ResizerLinkGenerator::PARAMS_AUTO, $this->config->isUpscaleAllowed());
			$source = $this->dimensionsReader->read($this->getSourceImagePath($path));
			return $source === null ? null : $geometry->getOutputDimensions($source);
		} catch (ResizerException) {
			return null;
		}
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


	private function generate(string $sourceImagePath, string $thumbnailPath, Geometry $geometry): void
	{
		$format = strtolower(pathinfo($thumbnailPath, PATHINFO_EXTENSION));

		try {
			$thumbnail = $this->processor->process($sourceImagePath, $geometry, $format);
		} catch (ImagineRuntimeException) {
			throw new ImageNotFoundOrReadableException('Unable to open image - wrong permissions, empty or corrupted.');
		}

		$this->writer->write($thumbnail, $thumbnailPath, $geometry->getResizerParams()->getQuality());
	}


	/**
	 * The thumbnail exists and is not older than the source image (a changed source is regenerated).
	 * Impure: another request may create the thumbnail between two calls.
	 * @phpstan-impure
	 */
	private function isThumbnailFresh(string $thumbnailPath, string $sourceImagePath): bool
	{
		return $this->thumbnailExists($thumbnailPath)
			&& filemtime($thumbnailPath) >= filemtime($sourceImagePath);
	}


	/**
	 * Refuses traversal ("..") segments, control characters (NUL, line breaks) and backslashes (a path separator on Windows).
	 * Symlinks inside wwwDir are intentionally allowed (e.g. shared storage in deployments).
	 */
	private function assertSafePath(string $path): void
	{
		if (
			$path === ''
			|| preg_match('~[\x00-\x1F\x7F]~', $path) === 1 // NUL, line breaks (the path goes to headers with sendfile)
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
}
