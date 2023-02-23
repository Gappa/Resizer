<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\SecurityException;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

class ResizerTest extends TestCase
{
	private static Resizer $resizer;
	private static string $image;
	private static ResizerConfig $config;


	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$config = new ResizerConfigDTO;
		$config->tempDir = __DIR__ . '/../temp';
		$config->wwwDir = __DIR__ . '/../tests';
		$config->qualityJpeg = 65;
		$config->qualityAvif = 65;
		$config->qualityWebp = 65;
		$config->compressionPng = 9;
		$config->library = 'Gd';

		$httpRequest = new Request(new UrlScript);
		self::$config = new ResizerConfig($config);

		$outputFormat = new OutputFormat($httpRequest, self::$config);

		self::$resizer = new Resizer(self::$config, $outputFormat);
		self::$image = 'fixtures/test.png';
	}


	public function testImageNotFound(): void
	{
		$this->expectException(ImageNotFoundOrReadableException::class);
		self::$resizer->getSourceImagePath('does_not_exist.jpg');
	}


	public function testSecurityException(): void
	{
		$this->expectException(SecurityException::class);
		self::$resizer->getSourceImagePath('../../haxxor.png');
	}


	public function testImageFound(): void
	{
		$this->assertSame(
			__DIR__ . '/fixtures/test.png',
			self::$resizer->getSourceImagePath(self::$image),
		);
	}


	public function testProcess(): void
	{
		$thumbnail = FileSystem::normalizePath(self::$resizer->process(self::$image, 'c200xc200'));

		$this->assertSame(
			FileSystem::normalizePath(__DIR__ . '/../temp/resizer/fixtures/test.png/c200xc200.png'),
			$thumbnail,
		);
	}


	public function testGeneratedImage(): void
	{
		$thumbnail = FileSystem::normalizePath(self::$resizer->process(self::$image, 'c200xc200'));

		$size = getimagesize($thumbnail);

		$this->assertSame(
			[200, 200],
			[$size[0] ?? 0, $size[1] ?? 0],
		);
	}


	public static function tearDownAfterClass(): void
	{
		parent::tearDownAfterClass();
		FileSystem::delete(self::$config->getTempDir() . self::$config->getCache());
	}
}
