<?php

namespace Nelson\Resizer;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Nette\Caching\Cache;
use Nette\Caching\IStorage;
use Nette\Http\Request;
use Nette\InvalidStateException;
use Nette\SmartObject;

use Nette\Utils\Html;


class Resizer implements IResizer
{
	use SmartObject;

	/** @var string */
	protected $storageDir;

	/** @var string */
	protected $assetsDir;

	/** @var string */
	protected $cacheDir;

	/** @var string */
	protected $wwwDir;

	/** @var string */
	protected $basePath;

	/** @var ImageInterface */
	protected $imagine;

	/** @var Cache */
	protected $cache;

	/** @var IStorage */
	protected $cacheStorage;

	/** @var array */
	protected $options;

	/** @var Request */
	protected $httpRequest;


	/**
	 * @param Request  $request
	 * @param IStorage $cacheStorage
	 */
	public function __construct(
		Request $request,
		IStorage $cacheStorage
	) {
		$this->httpRequest = $request;
		$this->cacheStorage = $cacheStorage;
	}


	/**
	 * @param  array  $config
	 * @return void
	 */
	public function setup(array $config)
	{
		$this->storageDir = $config['paths']['storage'];
		$this->assetsDir = $config['paths']['assets'];
		$this->cacheDir = $config['paths']['cache'];
		$this->options = $config['options'];
		$this->wwwDir = $config['paths']['wwwDir'];

		$url = $this->httpRequest->getUrl();
		$this->basePath = !empty($config['absoluteUrls']) ? $url->getBaseUrl() : $url->getBasePath();

		$this->testStorageDir();
		$this->testCacheDir();

		$this->cache = new Cache($this->cacheStorage, $config['cacheNS']);

		$library = implode('\\', ['Imagine', $config['library'], 'Imagine']);

		$this->imagine = new $library;
	}


	/**
	 * @param  string  $filePath
	 * @param  string  $dimensions
	 * @param  bool    $useAssets
	 * @return array
	 */
	protected function process($imagePath, $params, $useAssets = false)
	{
		// skippable argument defaults "hack" & backwards compat
		if ($params === null or $params === 'auto') {
			$params = 'x';
		}

		if (empty($imagePath)) {
			return null;
		}

		$imagePathFull = ($useAssets ? $this->assetsDir : $this->storageDir) . $imagePath;

		$pathinfo = pathinfo($imagePath);
		$cacheFileName = $params . '.' . $pathinfo['extension'];
		$imageOutputFilePath = $this->getImageOutputDir($imagePathFull) . $cacheFileName;

		if (!is_file($imagePathFull)) {
			$imageOutputFileUrl = $this->getImageOutputUrl($imagePathFull);
			$imageOutputSize = ['width' => null, 'height' => null];
			$imageExists = false;
		} else {
			$imageOutputFileUrl = $this->getImageOutputUrl($imageOutputFilePath);
			$geometry = Geometry::parseGeometry($params);
			$imageExists = true;

			if (!is_file($imageOutputFilePath)) {
				// $image            = $this->cache->call([$this->imagine, 'open'], $imagePathFull);
				$image = $this->imagine->open($imagePathFull);
				$imageCurSize = $this->cache->call([$this, 'getImageSize'], $imagePathFull);
				$imageOutputSize = Geometry::calculateNewSize($imageCurSize, $geometry);

				$image->resize(new Box($imageOutputSize['width'], $imageOutputSize['height']));
				if (Geometry::isCrop($geometry)) {
					$image->crop(Geometry::getCropPoint($geometry, $imageOutputSize), new Box($geometry['width'], $geometry['height']));
				}

				// remove all comments & metadata
				$image->strip();

				$image->save($imageOutputFilePath, $this->options);
			} else {
				$imageOutputSize = $this->cache->call([$this, 'getImageSize'], $imageOutputFilePath);
			}

			if (Geometry::isCrop($geometry)) {
				$imageOutputSize = $geometry;
			}
		}

		return [
			'name' => $pathinfo['filename'] . '.' . $params . '.' . $pathinfo['extension'],
			'imageInputFilePath' => $imagePathFull,
			'imageOutputFilePath' => $imageOutputFilePath,
			'imageOutputFileUrl' => $imageOutputFileUrl,
			'imageOutputSize' => $imageOutputSize,
			'imageExists' => $imageExists,
		];
	}


	/**
	 * @param  string  $filePath
	 * @param  string  $dimensions
	 * @param  string  $alt
	 * @param  string  $title
	 * @param  string  $id
	 * @param  string  $class
	 * @param  bool  $useAssets
	 * @return Html
	 */
	public function resize(
		$imagePath,
		$params = null,
		$alt = null,
		$title = null,
		$id = null,
		$class = null,
		$useAssets = false
	) {
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


	/**
	 * @param  string $imagePath
	 * @param  array|null $params
	 * @param  bool $useAssets
	 * @return Html
	 */
	public function send($imagePath, $params, $useAssets)
	{
		return $this->process($imagePath, $params, $useAssets);
	}


	/**
	 * @param  string $filepath
	 * @return ImageInterface
	 */
	public function openImage($filepath)
	{
		return $this->imagine->open($filepath);
	}


	/**
	 * @param  string $filepath
	 * @return array
	 */
	public function getImageSize($filepath)
	{
		$imageSize = $this->openImage($filepath)->getSize();

		return [
			'width' => $imageSize->getWidth(),
			'height' => $imageSize->getHeight(),
		];
	}


	/**
	 * @param  string $filepath
	 * @return string
	 */
	public function getImageOutputDir($filepath)
	{
		$dir = $this->cacheDir . preg_replace('#^' . $this->wwwDir . '\/#', '', ($filepath)) . DIRECTORY_SEPARATOR;

		if (!is_dir($dir)) {
			$umask = umask(0);
			mkdir($dir, 0777, true);
			umask($umask);
		}

		return $dir;
	}


	/**
	 * @param  string $path
	 * @return string
	 */
	private function getImageOutputUrl($path)
	{
		return $this->basePath . preg_replace('#^' . $this->wwwDir . '\/#', '', $path);
	}


	/**
	 * @return void
	 * @throws InvalidStateException
	 */
	private function testCacheDir()
	{
		if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
			throw new InvalidStateException("Thumbnail path '$this->cacheDir' does not exists or is not writable.");
		}
	}


	/**
	 * @return void
	 * @throws InvalidStateException
	 */
	private function testStorageDir()
	{
		if (!is_dir($this->storageDir) || !is_writable($this->storageDir)) {
			throw new InvalidStateException("Storage path '$this->storageDir' does not exists or is not writable.");
		}
	}
}
