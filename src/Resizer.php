<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Exception;
use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Metadata\DefaultMetadataReader;
use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\Http\Request;
use Nette\InvalidStateException;
use Nette\SmartObject;

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

	/** @var string */
	private $storageDir;

	/** @var string */
	private $assetsDir;

	/** @var string */
	private $cacheDir;

	/** @var string */
	private $wwwDir;

	/** @var string */
	private $basePath;

	/** @var AbstractImagine */
	private $imagine;

	/** @var Cache */
	private $cache;

	/** @var IStorage */
	private $cacheStorage;

	/** @var array */
	private $options = [];

	/** @var Request */
	private $httpRequest;

	/** @var bool */
	private $interlace;


	public function __construct(
		Request $request,
		IStorage $cacheStorage
	) {
		$this->httpRequest = $request;
		$this->cacheStorage = $cacheStorage;
	}


	public function setup(stdClass $config): void
	{
		$this->wwwDir = $config->paths->wwwDir;
		$this->storageDir = $config->paths->storage;
		$this->assetsDir = $config->paths->assets;
		$this->cacheDir = $this->wwwDir . $config->paths->cache;
		$this->options = $config->options;

		$url = $this->httpRequest->getUrl();
		$this->basePath = !empty($config->absoluteUrls) ? $url->getBaseUrl() : $url->getBasePath();
		$this->interlace = (bool) $config->interlace;

		$this->cache = new Cache($this->cacheStorage, $config->cacheNS);

		$library = implode('\\', ['Imagine', $config->library, 'Imagine']);

		$this->imagine = new $library;
		$this->imagine->setMetadataReader(new DefaultMetadataReader());
	}


	public function process(
		string $imagePath,
		?string $params,
		bool $useAssets = false,
		?string $format = null
	): ?array {

		$params = $this->normalizeParams($params);

		$imagePathFull = ($useAssets ? $this->assetsDir : $this->storageDir) . $imagePath;

		$filename = pathinfo($imagePath, PATHINFO_FILENAME);
		$extension = pathinfo($imagePath, PATHINFO_EXTENSION) ?? '.unknown';

		$cacheFileName = $params . '.' . $this->getOutputFormat($extension, $format);
		$imageOutputFilePath = $this->getImageOutputDir($imagePathFull) . $cacheFileName;

		if (!is_file($imagePathFull)) {
			throw new Exception('Source image not found or not readable.');
		}

		$imageOutputFileUrl = $this->getImageOutputUrl($imageOutputFilePath);
		$geometry = Geometry::parseGeometry($params);
		$imageExists = true;

		// the file might be corrupted
		try {
			if (
				// thumbnail doesn't exist, create it
				!is_file($imageOutputFilePath)
				or
				// thumbnail exists, but for whatever reason it's empty
				(is_file($imageOutputFilePath) and !filesize($imageOutputFilePath))
			) {
				/** @var ImageInterface $image */
				$image = $this->imagine->open($imagePathFull);
				$imageCurSize = $this->cache->call([$this, 'getImageSize'], $imagePathFull);
				$imageOutputSize = Geometry::calculateNewSize($imageCurSize, $geometry);

				$image->resize(new Box($imageOutputSize['width'], $imageOutputSize['height']));
				if (Geometry::isCrop($geometry)) {
					$image->crop(
						Geometry::getCropPoint($geometry, $imageOutputSize),
						new Box($geometry['width'], $geometry['height'])
					);
				}

				// remove all comments & metadata
				$image->strip();

				// use progressive/interlace mode?
				if ($this->interlace) {
					$image->interlace(ImageInterface::INTERLACE_LINE);
				}

				$image->save($imageOutputFilePath, (array) $this->options);
			} else {
				$imageOutputSize = $this->cache->call([$this, 'getImageSize'], $imageOutputFilePath);
			}
		} catch (RuntimeException $e) {
			$imageOutputFileUrl = $this->getImageOutputUrl($imagePathFull);
			$imageOutputSize = ['width' => null, 'height' => null];
			$imageExists = false;
		}

		if (Geometry::isCrop($geometry)) {
			$imageOutputSize = $geometry;
		}

		// build the output
		$output = [
			'name' => $filename . '.' . $cacheFileName,
			'imageInputFilePath' => $imagePathFull,
			'imageOutputFilePath' => $imageOutputFilePath,
			'imageOutputFileUrl' => $imageOutputFileUrl,
			'imageOutputSize' => $imageOutputSize,
			'imageExists' => $imageExists,
		];

		return $output;
	}


	/**
	 * @deprecated
	 */
	public function resize(
		string $imagePath,
		string $params = null,
		string $alt = null,
		string $title = null,
		string $id = null,
		string $class = null,
		bool $useAssets = false
	): Html {
		trigger_error('Macro {resize} is deprecated, use {rlink}, n:rsrc or n:rhref instead.', E_USER_DEPRECATED);
		$resizedImage = $this->process($imagePath, $params, $useAssets);

		return Html::el('img')
			->src($resizedImage['imageOutputFileUrl'])
			->width($resizedImage['imageOutputSize']['width'])
			->height($resizedImage['imageOutputSize']['height'])
			->alt($alt)
			->title($title)
			->id($id)
			->class($class);
	}


	public function openImage(string $filepath): ImageInterface
	{
		return $this->imagine->open($filepath);
	}


	public function getImageSize(string $filepath): array
	{
		$imageSize = $this->openImage($filepath)->getSize();

		return [
			'width' => $imageSize->getWidth(),
			'height' => $imageSize->getHeight(),
		];
	}


	public function getImageOutputDir(string $filepath): string
	{
		$dir = $this->cacheDir . preg_replace('#^' . $this->wwwDir . '\/#', '', ($filepath)) . DIRECTORY_SEPARATOR;

		if (!is_dir($dir)) {
			$umask = umask(0);
			mkdir($dir, 0777, true);
			umask($umask);
		}

		return $dir;
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


	private function getImageOutputUrl(string $path): string
	{
		return $this->basePath . preg_replace('#^' . $this->wwwDir . '\/#', '', $path);
	}


	private function normalizeParams(string $params): string
	{
		// skippable argument defaults "hack" & backwards compat
		if ($params === null or $params === 'auto') {
			return 'x';
		}

		return $params;
	}


}
