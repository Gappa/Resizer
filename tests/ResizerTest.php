<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\DI\ResizerConfig;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\SecurityException;
use Nelson\Resizer\Resizer;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

class ResizerTest extends TestCase
{
	private static Resizer $resizer;
	private static string $image;
	private static ResizerConfig $config;
	private static ?string $thumbnail = null;


	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		static::$config = new ResizerConfig;
		static::$config->tempDir = __DIR__ . '/../temp';
		static::$config->wwwDir = __DIR__ . '/../tests';
		static::$config->qualityJpeg = 65;
		static::$config->qualityWebp = 65;
		static::$config->compressionPng = 9;
		static::$config->library = 'Gd';

		static::$resizer = new Resizer(static::$config, false);
		static::$image = 'fixtures/test.png';
	}


	public function testImageNotFound(): void
	{
		$this->expectException(ImageNotFoundOrReadableException::class);
		static::$resizer->getSourceImagePath('does_not_exist.jpg');
	}


	public function testSecurityException(): void
	{
		$this->expectException(SecurityException::class);
		static::$resizer->getSourceImagePath('../../haxxor.png');
	}


	public function testImageFound(): void
	{
		$this->assertEquals(
			__DIR__ . '/fixtures/test.png',
			static::$resizer->getSourceImagePath(static::$image),
		);
	}


	public function testProcess(): void
	{
		$thumbnail = FileSystem::normalizePath(static::$resizer->process(static::$image, 'c200xc200'));

		$this->assertSame(
			FileSystem::normalizePath(__DIR__ . '/../temp/resizer/fixtures/test.png/c200xc200.png'),
			$thumbnail,
		);
	}


	public function testGeneratedImage(): void
	{
		$thumbnail = FileSystem::normalizePath(static::$resizer->process(static::$image, 'c200xc200'));

		$size = getimagesize($thumbnail);

		$this->assertSame(
			[200, 200],
			[$size[0], $size[1]],
		);
	}


	public static function tearDownAfterClass(): void
	{
		parent::tearDownAfterClass();
		FileSystem::delete(static::$config->tempDir . static::$config->cache);
	}
}
