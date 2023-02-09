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
		$this->cacheDir = $config->getTempDir() . $config->getCache();
		FileSystem::createDir($this->cacheDir);

		/** @var AbstractImagine $library */
		$library = implode('\\', ['Imagine', $config->getLibrary(), 'Imagine']);
		$this->imagine = new $library;
	}


	public function process(
		string $path,
		?string $params,
		?string $format = null
	): string {
		$params = $this->normalizeParams($params);
		$sourceImagePath = $this->getSourceImagePath($path);

		$thumbnailFileName = $params . '.' . $this->outputFormat->getOutputFormat($path, $format);
		$thumbnailPath = $this->getThumbnailDir($path) . $thumbnailFileName;

		$geometry = new Geometry($params);

		if (!$this->thumbnailExists($thumbnailPath)) {
			try {
				$thumbnail = $this->processImage($sourceImagePath, $geometry);
			} catch (RuntimeException $e) {
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

			$thumbnail->save($thumbnailPath, $this->config->getOptions());
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


	private function getThumbnailDir(string $path): string
	{
		$dir = $this->cacheDir . $path . DIRECTORY_SEPARATOR;
		FileSystem::createDir($dir);
		return $dir;
	}


	private function normalizeParams(?string $params): string
	{
		// skippable argument defaults "hack" & backwards compat
		if ($params === null || $params === 'auto') {
			return 'x';
		}

		return $params;
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


	private function thumbnailExists(string $path): bool
	{
		return is_file($path) && (bool) filesize($path);
	}
}
