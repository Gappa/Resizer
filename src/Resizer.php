<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Imagine\Exception\RuntimeException;
use Imagine\Image\AbstractImagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
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


	public function setup(array $config): void
	{
		$config = \Nette\Utils\ArrayHash::from($config);

		$this->wwwDir = $config->paths->wwwDir;
		$this->storageDir = $config->paths->storage;
		$this->assetsDir = $config->paths->assets;
		$this->cacheDir = $config->paths->cache;
		$this->options = $config->options;

		$url = $this->httpRequest->getUrl();
		$this->basePath = !empty($config->absoluteUrls) ? $url->getBaseUrl() : $url->getBasePath();
		$this->interlace = (bool) $config->interlace;

		$this->testStorageDir();
		$this->testCacheDir();

		$this->cache = new Cache($this->cacheStorage, $config->cacheNS);

		$library = implode('\\', ['Imagine', $config->library, 'Imagine']);

		$this->imagine = new $library;
	}


	protected function process(string $imagePath, ?string $params, bool $useAssets = false): ?array
	{
		// skippable argument defaults "hack" & backwards compat
		if ($params === null or $params === 'auto') {
			$params = 'x';
		}

		// filepath isn't even specified
		if (empty($imagePath)) {
			return null;
		}

		$imagePathFull = ($useAssets ? $this->assetsDir : $this->storageDir) . $imagePath;

		$filename = pathinfo($imagePath, PATHINFO_FILENAME);
		$extension = pathinfo($imagePath, PATHINFO_EXTENSION) ?? '.unknown';

		$cacheFileName = $params . '.' . $extension;
		$imageOutputFilePath = $this->getImageOutputDir($imagePathFull) . $cacheFileName;

		// file doesn't exist
		if (!is_file($imagePathFull)) {
			$imageOutputFileUrl = $this->getImageOutputUrl($imagePathFull);
			$imageOutputSize = ['width' => null, 'height' => null];
			$imageExists = false;
		} else {
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
		}

		// build the output
		return [
			'name' => $filename . '.' . $params . '.' . $extension,
			'imageInputFilePath' => $imagePathFull,
			'imageOutputFilePath' => $imageOutputFilePath,
			'imageOutputFileUrl' => $imageOutputFileUrl,
			'imageOutputSize' => $imageOutputSize,
			'imageExists' => $imageExists,
		];
	}


	public function resize(
		string $imagePath,
		string $params = null,
		string $alt = null,
		string $title = null,
		string $id = null,
		string $class = null,
		bool $useAssets = false
	): Html {
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


	public function send(string $imagePath, ?string $params, bool $useAssets): ?array
	{
		return $this->process($imagePath, $params, $useAssets);
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


	private function getImageOutputUrl(string $path): string
	{
		return $this->basePath . preg_replace('#^' . $this->wwwDir . '\/#', '', $path);
	}


	/**
	 * @throws InvalidStateException
	 */
	private function testCacheDir(): void
	{
		if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
			throw new InvalidStateException("Thumbnail path '$this->cacheDir' does not exists or is not writable.");
		}
	}


	/**
	 * @throws InvalidStateException
	 */
	private function testStorageDir(): void
	{
		if (!is_dir($this->storageDir) || !is_writable($this->storageDir)) {
			throw new InvalidStateException("Storage path '$this->storageDir' does not exists or is not writable.");
		}
	}
}
