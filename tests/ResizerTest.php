<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nelson\Resizer\Exceptions\DimensionsTooLargeException;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\SecurityException;
use Nelson\Resizer\Exceptions\UnsupportedFormatException;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResizerTest extends TestCase
{
	private const string PUBLIC_DIR = __DIR__ . '/_public';

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


	/** @return iterable<string, array{string}> */
	public static function provideUnsafePaths(): iterable
	{
		yield 'parent at start' => ['../fixtures/test.png'];
		yield 'parent in the middle' => ['fixtures/../fixtures/test.png'];
		yield 'parent at end' => ['fixtures/..'];
		yield 'backslash' => ['fixtures\\..\\..\\haxxor.png'];
		yield 'null byte' => ["fixtures/test.png\0.png"];
		yield 'empty' => [''];
	}


	#[DataProvider('provideUnsafePaths')]
	public function testUnsafePathIsRefused(string $path): void
	{
		$this->expectException(SecurityException::class);
		self::$resizer->getSourceImagePath($path);
	}


	public function testUnsafePathIsRefusedForThumbnail(): void
	{
		$this->expectException(SecurityException::class);
		self::$resizer->getThumbnailPath('../../haxxor.png', '100x');
	}


	public function testUnsupportedSourceType(): void
	{
		// exists, but is not an image type the resizer is allowed to open
		$this->expectException(ImageNotFoundOrReadableException::class);
		self::$resizer->getSourceImagePath('phpunit.xml');
	}


	public function testSourceWithFakeExtension(): void
	{
		FileSystem::write(self::PUBLIC_DIR . '/fake.png', '<svg xmlns="http://www.w3.org/2000/svg"/>');

		$this->expectException(ImageNotFoundOrReadableException::class);
		self::$resizer->getSourceImagePath('_public/fake.png');
	}


	public function testInvalidParamsDoNotTouchFilesystem(): void
	{
		try {
			self::$resizer->process(self::$image, 'evil/dir');
			$this->fail('Exception expected.');
		} catch (CouldNotParseResizerParamsException) {
		}

		$this->assertDirectoryDoesNotExist(self::$config->getTempDir() . self::$config->getCache() . 'fixtures/test.png/evil');
	}


	public function testTooLargeDimensions(): void
	{
		$this->expectException(DimensionsTooLargeException::class);
		self::$resizer->process(self::$image, '4001x');
	}


	public function testTooLargeNumberIsNotParsed(): void
	{
		$this->expectException(CouldNotParseResizerParamsException::class);
		self::$resizer->process(self::$image, '999999999999999999999x');
	}


	public function testUppercaseFormatIsNormalized(): void
	{
		$this->assertStringEndsWith(
			'/x100.png',
			FileSystem::normalizePath(self::$resizer->getThumbnailPath(self::$image, 'x100', 'PNG')),
		);
	}


	public function testUnsupportedFormat(): void
	{
		$this->expectException(UnsupportedFormatException::class);
		self::$resizer->getThumbnailPath(self::$image, 'x100', 'exe');
	}


	public function testThumbnailIsRegeneratedWhenSourceChanges(): void
	{
		FileSystem::copy(__DIR__ . '/fixtures/test.png', self::PUBLIC_DIR . '/regen.png');

		$thumbnail = self::$resizer->process('_public/regen.png', 'x100');
		touch($thumbnail, time() - 3600);
		touch(self::PUBLIC_DIR . '/regen.png', time() - 60);
		clearstatcache();

		self::$resizer->process('_public/regen.png', 'x100');
		clearstatcache();

		$this->assertGreaterThan(time() - 60, filemtime($thumbnail));
	}


	public static function tearDownAfterClass(): void
	{
		parent::tearDownAfterClass();
		FileSystem::delete(self::$config->getTempDir() . self::$config->getCache());
		FileSystem::delete(self::PUBLIC_DIR);
	}
}
