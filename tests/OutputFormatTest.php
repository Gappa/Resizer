<?php

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\ResizerConfig;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use PHPUnit\Framework\TestCase;

class OutputFormatTest extends TestCase
{

	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
	}


	public function testJpg(): void
	{
		$httpRequest = new Request(new UrlScript);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.jpg'),
			'jpg',
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.jpeg'),
			'jpg',
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.jfif'),
			'jpg',
		);
	}


	public function testAvif(): void
	{
		$httpRequest = new Request(new UrlScript, headers: ['accept' => 'image/avif,image/webp']);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(true, true),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.avif'),
			'avif',
		);
	}


	public function testAvif2Jpg(): void
	{
		$httpRequest = new Request(new UrlScript);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.avif'),
			'jpg',
		);
	}


	public function testAvif2Webp(): void
	{
		$httpRequest = new Request(new UrlScript, headers: ['accept' => 'image/webp']);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(true),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.avif'),
			'webp',
		);
	}


	public function testWebp(): void
	{
		$httpRequest = new Request(new UrlScript, headers: ['accept' => 'image/avif,image/webp']);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(true, true),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.webp'),
			'webp',
		);
	}


	public function testWebp2Jpg(): void
	{
		$httpRequest = new Request(new UrlScript);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.webp'),
			'jpg',
		);
	}


	public function testWebp2Avif(): void
	{
		$httpRequest = new Request(new UrlScript, headers: ['accept' => 'image/avif']);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(false, true),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.webp'),
			'avif',
		);
	}


	public function testJpg2Avif(): void
	{
		$httpRequest = new Request(new UrlScript, headers: ['accept' => 'image/avif,image/webp']);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(true, true),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.jpg'),
			'avif',
		);
	}


	public function testJpg2Webp(): void
	{
		$httpRequest = new Request(new UrlScript, headers: ['accept' => 'image/webp']);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(true, true),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.jpg'),
			'webp',
		);
	}


	public function testPng2Avif(): void
	{
		$httpRequest = new Request(new UrlScript, headers: ['accept' => 'image/avif,image/webp']);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(true, true),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.png'),
			'avif',
		);
	}


	public function testPng2Webp(): void
	{
		$httpRequest = new Request(new UrlScript, headers: ['accept' => 'image/webp']);
		$outputFormat = new OutputFormat(
			$httpRequest,
			$this->getConfig(true, true),
		);

		$this->assertSame(
			$outputFormat->getOutputFormat('test.png'),
			'webp',
		);
	}


	public function testPng(): void
	{
		$httpRequest = new Request(new UrlScript);
		$outputFormat = new OutputFormat($httpRequest, $this->getConfig());

		$this->assertSame(
			$outputFormat->getOutputFormat('test.png'),
			'png',
		);
	}


	private function getConfig(bool $webpSupported = false, bool $avifSupported = false): ResizerConfig
	{
		$config = new ResizerConfigDTO;

		$config->tempDir = __DIR__ . '/../temp';
		$config->wwwDir = __DIR__ . '/../tests';
		$config->qualityJpeg = 65;
		$config->qualityAvif = 65;
		$config->qualityWebp = 65;
		$config->compressionPng = 9;
		$config->library = 'Gd';

		$config->isAvifSupportedByServer = $avifSupported;
		$config->isWebpSupportedByServer = $webpSupported;

		return new ResizerConfig($config);
	}

}
