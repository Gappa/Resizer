<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\SecurityException;
use Nette\SmartObject;
use Nette\Utils\FileSystem;

final class Resizer implements IResizer
{
	use SmartObject;

	public const FORMAT_SUFFIX_JPG = 'jpg';
	public const FORMAT_SUFFIX_WEBP = 'webp';
	public const FORMAT_SUFFIX_AVIF = 'avif';
	public const FORMAT_SUFFIX_PNG = 'png';

	public const FORMAT_SUFFIXES_JPG = [
		'jpg',
		'jpeg',
		'jfif',
	];

	public const MIME_TYPE_WEBP = 'image/webp';
	public const MIME_TYPE_AVIF = 'image/avif';

	private AbstractImagine $imagine;
	private string $cacheDir;


	public function __construct(
		private readonly ResizerConfig $config,
		private readonly OutputFormat $outputFormat,
	)
	{
		$this->cacheDir = $this->config->getPublicCacheDir()
			?? $this->config->getTempDir() . $this->config->getCache();
		FileSystem::createDir($this->cacheDir);

		/** @var AbstractImagine $library */
		$library = implode('\\', ['Imagine', $this->config->getLibrary(), 'Imagine']);
		$this->imagine = new $library;
	}


	public function process(
		string $path,
		?string $params,
		?string $format = null,
	): string {
		$sourceImagePath = $this->getSourceImagePath($path);

		$thumbnailPath = $this->getThumbnailPath($path, $params, $format);
		FileSystem::createDir(dirname($thumbnailPath));

		$geometry = new Geometry($params);

		if (!$this->thumbnailExists($thumbnailPath)) {
			try {
				$thumbnail = $this->processImage($sourceImagePath, $geometry);
			} catch (RuntimeException) {
				throw new ImageNotFoundOrReadableException('Unable to open image - wrong permissions, empty or corrupted.');
			}

			if ($this->config->isStrip()) {
				// remove all comments & metadata
				$thumbnail->strip();
			}

			// use progressive/interlace mode?
			if ($this->config->isInterlace()) {
				$thumbnail->interlace(ImageInterface::INTERLACE_LINE);
			}

			// Write to a temporary file first and rename it, so a partially written thumbnail is never served
			// (in static mode the file may be served directly by the web server as soon as it exists).
			// The temporary name keeps the extension, Imagine derives the output format from it.
			$tempPath = dirname($thumbnailPath) . DIRECTORY_SEPARATOR
				. '.tmp-' . bin2hex(random_bytes(6)) . '-' . basename($thumbnailPath);

			try {
				$thumbnail->save($tempPath, $this->config->getOptions($geometry->getResizerParams()->getQuality()));
				// plain rename() is atomic and replaces the target (FileSystem::rename() deletes it first)
				if (!@rename($tempPath, $thumbnailPath)) {
					throw new ImageNotFoundOrReadableException(sprintf("Unable to write thumbnail '%s'.", $thumbnailPath));
				}
			} finally {
				if (is_file($tempPath)) {
					@unlink($tempPath);
				}
			}
		}

		return $thumbnailPath;
	}


	public function getSourceImagePath(string $path): string
	{
		$fullPath = (string) realpath($this->config->getWwwDir() . DIRECTORY_SEPARATOR . $path);

		// wonky, but better than nothing
		if (str_contains($path, '../')) {
			throw new SecurityException('Attempt to access files outside permitted path.');
		}

		if (!is_file($fullPath)) {
			throw new ImageNotFoundOrReadableException('Source image not found or not readable.');
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
		return $this->cacheDir . $path . DIRECTORY_SEPARATOR
			. $params . '.' . $this->outputFormat->getOutputFormat($path, $format);
	}


	public function thumbnailExists(string $path): bool
	{
		return is_file($path) && (bool) filesize($path);
	}


	private function processImage(
		string $imagePathFull,
		Geometry $geometry
	): ImageInterface {
		$image = $this->imagine->open($imagePathFull);

		$imageCurSize = $image->getSize();

		$sourceDimensions = new Dimensions(
			Helpers::getPositiveInt($imageCurSize->getWidth()),
			Helpers::getPositiveInt($imageCurSize->getHeight()),
		);

		$imageOutputSize = $geometry->calculateNewSize($sourceDimensions);

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
}
