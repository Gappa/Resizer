<?php
declare(strict_types=1);

namespace Test;

use Nelson\Resizer\DI\ResizerConfig;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\SecurityException;
use Nelson\Resizer\Resizer;
use Nette\SmartObject;
use Nette\Utils\FileSystem;
use Tester\Assert;
use Tester\TestCase;

// $container = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/bootstrap.php';

/** @testCase */
class ResizerTest extends TestCase
{
	use SmartObject;

	private Resizer $resizer;
	private string $image;
	private ResizerConfig $config;
	private ?string $thumbnail = null;


	public function __construct()
	{
		$this->config = new ResizerConfig();
		$this->config->tempDir = __DIR__ . '/../temp';
		$this->config->wwwDir = __DIR__ . '/../tests';
		$this->config->qualityJpeg = 65;
		$this->config->qualityWebp = 65;
		$this->config->compressionPng = 9;
		$this->config->library = 'Gd';

		$this->resizer = new Resizer($this->config, false);
		$this->image = 'test.png';
	}


	// runs for every test
	// public function setUp(): void
	// {
	// }


	public function testImageNotFound(): void
	{
		Assert::exception(
			function() {$this->resizer->getSourceImagePath('does_not_exist.jpg');},
			ImageNotFoundOrReadableException::class,
		);
	}


	public function testSecurityException(): void
	{
		Assert::exception(
			function() {$this->resizer->getSourceImagePath('../../haxxor.png');},
			SecurityException::class,
		);
	}


	public function testImageFound(): void
	{
		Assert::equal(
			__DIR__ . '/test.png',
			$this->resizer->getSourceImagePath($this->image),
		);
	}


	public function testProcess(): void
	{
		$thumbnail = FileSystem::normalizePath($this->resizer->process($this->image, 'c200xc200'));

		Assert::same(
			FileSystem::normalizePath(__DIR__ . '/../temp/resizer/test.png/c200xc200.png'),
			$thumbnail,
		);
	}


	public function testGeneratedImage(): void
	{
		$thumbnail = FileSystem::normalizePath($this->resizer->process($this->image, 'c200xc200'));

		$size = getimagesize($thumbnail);

		Assert::same(
			[200, 200],
			[$size[0], $size[1]],
		);
	}


	public function tearDown()
	{
		FileSystem::delete($this->config->tempDir . $this->config->cache);
	}
}

$test = new ResizerTest;
$test->run();
