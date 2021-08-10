<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Exception;
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Nelson\Resizer\DI\ResizerConfig;
use Nette\SmartObject;
use Nette\Utils\FileSystem;

final class Resizer implements IResizer
{
	use SmartObject;

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

	private ResizerConfig $config;
	private AbstractImagine $imagine;
	private string $cacheDir;
	private array $options = [];
	private bool $isWebpSupportedByServer;


	public function __construct(ResizerConfig $config, bool $isWebpSupportedByServer)
	{
		$this->config = $config;
		$this->isWebpSupportedByServer = $isWebpSupportedByServer;
		$this->cacheDir = $config->tempDir . $config->cache;
		FileSystem::createDir($this->cacheDir);

		$this->options = [
			'webp_quality' => $config->qualityWebp,
			'jpeg_quality' => $config->qualityJpeg,
			'png_compression_level' => $config->compressionPng,
		];

		$library = implode('\\', ['Imagine', $config->library, 'Imagine']);
		$this->imagine = new $library;
	}


	public function process(
		string $path,
		?string $params,
		?string $format = null
	): ?string {
		$params = $this->normalizeParams($params);
		$sourceImagePath = $this->getSourceImagePath($path);

		$extension = pathinfo($path, PATHINFO_EXTENSION) ?? '.unknown';

		$thumbnailFileName = $params . '.' . $this->getOutputFormat($extension, $format);
		$thumbnailPath = $this->getThumbnailDir($path) . $thumbnailFileName;

		$geometry = Geometry::parseGeometry($params);

		if (!$this->thumbnailExists($thumbnailPath)) {
			try {
				$thumbnail = $this->processImage($sourceImagePath, $geometry);
			} catch (RuntimeException $e) {
				throw new Exception('Unable to open image - wrong permissions, empty or corrupted.');
			}

			if ($this->config->strip) {
				// remove all comments & metadata
				$thumbnail->strip();
			}

			// use progressive/interlace mode?
			if ($this->config->interlace) {
				$thumbnail->interlace(ImageInterface::INTERLACE_LINE);
			}

			$thumbnail->save($thumbnailPath, $this->options);
		}

		return $thumbnailPath;
	}


	public function getSourceImagePath(string $path): string
	{
		$fullPath = (string) realpath($this->config->wwwDir . DIRECTORY_SEPARATOR . $path);

		if (!is_file($fullPath)) {
			throw new Exception('Source image not found or not readable.');
		}

		// wonky, but better than nothing
		if (strpos($path, '../') !== false) {
			throw new Exception('Attempt to access files outside permitted path.');
		}

		return $fullPath;
	}


	public function canUpgradeJpg2Webp(): bool
	{
		return $this->config->upgradeJpg2Webp;
	}


	public function isWebpSupportedByServer(): bool
	{
		return $this->isWebpSupportedByServer;
	}


	private function getThumbnailDir(string $path): string
	{
		$dir = $this->cacheDir . $path . DIRECTORY_SEPARATOR;
		FileSystem::createDir($dir);
		return $dir;
	}


	private function getOutputFormat(string $extension, ?string $format = null): string
	{
		if (!empty($format) && $this->isFormatSupported($format)) {
			return $format;
		}
		return $extension;
	}


	private function isFormatSupported(string $format): bool
	{
		return in_array(strtolower($format), self::SUPPORTED_FORMATS, true);
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
		array $geometry
	): ImageInterface {
		$image = $this->imagine->open($imagePathFull);
		$imageCurSize = $image->getSize();
		$imageOutputSize = Geometry::calculateNewSize(
			[
				'width' => $imageCurSize->getWidth(),
				'height' => $imageCurSize->getHeight(),
			],
			$geometry,
		);

		$image->resize(new Box($imageOutputSize['width'], $imageOutputSize['height']));
		if (Geometry::isCrop($geometry)) {
			$image->crop(
				Geometry::getCropPoint($geometry, $imageOutputSize),
				new Box($geometry['width'], $geometry['height']),
			);
		}

		return $image;
	}


	private function thumbnailExists(string $path): bool
	{
		return is_file($path) && (bool) filesize($path);
	}
}
