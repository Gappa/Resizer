<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Exception;
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Metadata\DefaultMetadataReader;
use Nelson\Resizer\DI\ResizerConfig;
use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\Caching\Storages\FileStorage;
use Nette\Http\Request;
use Nette\InvalidStateException;
use Nette\SmartObject;

use Nette\Utils\FileSystem;
use Nette\Utils\Html;
use stdClass;


final class Resizer implements IResizer
{
	use SmartObject;

	/** @var array */
	private const SUPPORTED_FORMATS = [
		'jpeg',
		'jpg',
		'gif',
		'png',
		'wbmp',
		'xbm',
		'webp',
		'bmp',
	];

	/** @var ResizerConfig */
	private $config;

	/** @var string */
	private $storageDir;

	/** @var string */
	private $assetsDir;

	/** @var string */
	private $cacheDir;

	/** @var AbstractImagine */
	private $imagine;

	/** @var array */
	private $options = [];


	public function __construct(ResizerConfig $config)
	{
		$this->config = $config;
		$this->storageDir = $config->storage;
		$this->assetsDir = $config->assets;

		$this->cacheDir = $config->tempDir . $config->cache;
		FileSystem::createDir($this->cacheDir);

		$this->options = [
			'webp_quality' => $config->qualityWebp,
			'jpeg_quality' => $config->qualityJpeg,
			'png_compression_level' => $config->qualityPng,
		];

		$library = implode('\\', ['Imagine', $config->library, 'Imagine']);

		$this->imagine = new $library;
		$this->imagine->setMetadataReader(new DefaultMetadataReader());
	}


	public function process(
		string $path,
		?string $params,
		bool $useAssets = false,
		?string $format = null
	): ?string
	{

		$params = $this->normalizeParams($params);
		$imagePathSource = $this->getImagePath($path, $useAssets);

		$extension = pathinfo($path, PATHINFO_EXTENSION) ?? '.unknown';

		$cacheFileName = $params . '.' . $this->getOutputFormat($extension, $format);
		$thumbnailPath = $this->getImageOutputDir($path) . $cacheFileName;

		if (!is_file($imagePathSource)) {
			throw new Exception('Source image not found or not readable.');
		}

		$geometry = Geometry::parseGeometry($params);

		if (!$this->thumbnailExists($thumbnailPath)) {
			$thumbnail = $this->processImage($imagePathSource, $geometry);

			// remove all comments & metadata
			$thumbnail->strip();

			// use progressive/interlace mode?
			if ($this->config->interlace) {
				$thumbnail->interlace(ImageInterface::INTERLACE_LINE);
			}

			$thumbnail->save($thumbnailPath, $this->options);
		}

		return $thumbnailPath;
	}


	public function getImageOutputDir(string $path): string
	{
		$dir = $this->cacheDir . preg_replace('#^' . $this->config->wwwDir . '\/#', '', ($path)) . DIRECTORY_SEPARATOR;

		FileSystem::createDir($dir);

		return $dir;
	}


	public function getImagePath(string $path, bool $useAssets): string
	{
		return ($useAssets ? $this->assetsDir : $this->storageDir) . $path;
	}


	private function getOutputFormat(string $extension, ?string $format = null): string
	{
		if (!empty($format) && $this->isFormatSupported($format)) {
			return $format;
		} else {
			return $extension;
		}
	}


	private function isFormatSupported(string $format): bool
	{
		return in_array(strtolower($format), self::SUPPORTED_FORMATS, true);
	}


	private function normalizeParams(string $params): string
	{
		// skippable argument defaults "hack" & backwards compat
		if ($params === null or $params === 'auto') {
			return 'x';
		}

		return $params;
	}


	private function processImage(
		string $imagePathFull,
		array $geometry
	): ImageInterface
	{
		/** @var ImageInterface $image */
		$image = $this->imagine->open($imagePathFull);
		$imageCurSize = $image->getSize();
		$imageOutputSize = Geometry::calculateNewSize(
			[
				'width' => $imageCurSize->getWidth(),
				'height' => $imageCurSize->getHeight()
			],
			$geometry
		);

		$image->resize(new Box($imageOutputSize['width'], $imageOutputSize['height']));
		if (Geometry::isCrop($geometry)) {
			$image->crop(
				Geometry::getCropPoint($geometry, $imageOutputSize),
				new Box($geometry['width'], $geometry['height'])
			);
		}

		return $image;
	}


	private function thumbnailExists(string $path): bool
	{
		return is_file($path) and (bool)filesize($path);
	}


}
