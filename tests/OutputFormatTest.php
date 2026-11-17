<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\Exceptions\UnsupportedFormatException;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\ResizerConfig;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OutputFormatTest extends TestCase
{
	private const string ACCEPT_ALL = 'image/jxl,image/avif,image/webp,image/png,image/*;q=0.8,*/*;q=0.5';
	private const string ACCEPT_AVIF_WEBP = 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8';
	private const array SERVER_ALL = ['webp', 'avif', 'jxl'];
	private const array SERVER_WEBP_AVIF = ['webp', 'avif'];


	/**
	 * file, Accept header, formats supported by the server, config overrides, expected format
	 * @return iterable<string, array{string, string, list<string>, array<string, mixed>, string}>
	 */
	public static function provideNegotiation(): iterable
	{
		// JPEG source
		yield 'jpg: no support' => ['test.jpg', self::ACCEPT_AVIF_WEBP, [], [], 'jpg'];
		yield 'jpeg suffix' => ['test.jpeg', '', [], [], 'jpg'];
		yield 'jfif suffix' => ['test.jfif', '', [], [], 'jpg'];
		yield 'uppercase suffix' => ['TEST.JPG', '', [], [], 'jpg'];
		yield 'jpg: browser without support' => ['test.jpg', '', self::SERVER_ALL, [], 'jpg'];
		yield 'jpg: webp before avif by default' => ['test.jpg', self::ACCEPT_AVIF_WEBP, self::SERVER_WEBP_AVIF, [], 'webp'];
		yield 'jpg: avif only' => ['test.jpg', 'image/avif', self::SERVER_WEBP_AVIF, [], 'avif'];
		yield 'jpg: jxl disabled by default' => ['test.jpg', self::ACCEPT_ALL, self::SERVER_ALL, [], 'webp'];
		yield 'jpg: jxl enabled' => ['test.jpg', self::ACCEPT_ALL, self::SERVER_ALL, ['upgradeJpg2Jxl' => true], 'jxl'];
		yield 'jpg: jxl enabled, browser without jxl' => ['test.jpg', self::ACCEPT_AVIF_WEBP, self::SERVER_ALL, ['upgradeJpg2Jxl' => true], 'webp'];
		yield 'jpg: jxl enabled, server without jxl' => ['test.jpg', 'image/jxl', self::SERVER_WEBP_AVIF, ['upgradeJpg2Jxl' => true], 'jpg'];
		yield 'jpg: webp upgrade disabled' => ['test.jpg', self::ACCEPT_AVIF_WEBP, self::SERVER_WEBP_AVIF, ['upgradeJpg2Webp' => false], 'avif'];
		yield 'jpg: legacy priority' => ['test.jpg', self::ACCEPT_AVIF_WEBP, self::SERVER_WEBP_AVIF, ['formatPriority' => ['avif', 'webp']], 'avif'];
		yield 'jpg: format missing in priority' => ['test.jpg', 'image/avif', self::SERVER_WEBP_AVIF, ['formatPriority' => ['webp']], 'jpg'];

		// PNG source
		yield 'png: no support' => ['test.png', '', [], [], 'png'];
		yield 'png: browser without support' => ['test.png', '', self::SERVER_ALL, [], 'png'];
		yield 'png: webp' => ['test.png', self::ACCEPT_ALL, self::SERVER_ALL, [], 'webp'];
		yield 'png: jxl enabled' => ['test.png', self::ACCEPT_ALL, self::SERVER_ALL, ['upgradePng2Jxl' => true], 'jxl'];
		yield 'png: jpg upgrade does not apply' => ['test.png', 'image/jxl', self::SERVER_ALL, ['upgradeJpg2Jxl' => true], 'png'];
		yield 'png: avif only' => ['test.png', 'image/avif', self::SERVER_ALL, [], 'avif'];

		// modern sources keep their format, fall back by priority, JPEG as the last resort
		yield 'webp' => ['test.webp', self::ACCEPT_ALL, self::SERVER_ALL, [], 'webp'];
		yield 'webp: to avif' => ['test.webp', 'image/avif', self::SERVER_WEBP_AVIF, [], 'avif'];
		yield 'webp: to jpg' => ['test.webp', '', self::SERVER_ALL, [], 'jpg'];
		yield 'avif' => ['test.avif', self::ACCEPT_ALL, self::SERVER_ALL, [], 'avif'];
		yield 'avif: to webp' => ['test.avif', 'image/webp', self::SERVER_ALL, [], 'webp'];
		yield 'avif: server without support' => ['test.avif', self::ACCEPT_ALL, [], [], 'jpg'];
		yield 'jxl' => ['test.jxl', self::ACCEPT_ALL, self::SERVER_ALL, [], 'jxl'];
		yield 'jxl: browser without jxl' => ['test.jxl', self::ACCEPT_AVIF_WEBP, self::SERVER_ALL, [], 'webp'];
		yield 'jxl: to jpg' => ['test.jxl', '', self::SERVER_ALL, [], 'jpg'];

		// GIF is kept
		yield 'gif' => ['test.gif', self::ACCEPT_ALL, self::SERVER_ALL, [], 'gif'];

		// Accept parsing
		yield 'q=0 is refused' => ['test.jpg', 'image/webp;q=0,image/avif', self::SERVER_WEBP_AVIF, [], 'avif'];
		yield 'wildcard is not support' => ['test.jpg', 'image/*,*/*', self::SERVER_ALL, ['upgradeJpg2Jxl' => true], 'jpg'];
		yield 'case insensitive' => ['test.jpg', 'IMAGE/WEBP', self::SERVER_WEBP_AVIF, [], 'webp'];
	}


	/**
	 * @param list<string> $server
	 * @param array<string, mixed> $config
	 */
	#[DataProvider('provideNegotiation')]
	public function testNegotiation(string $file, string $accept, array $server, array $config, string $expected): void
	{
		$this->assertSame($expected, $this->create($accept, $server, $config)->getOutputFormat($file));
	}


	/** @return iterable<string, array{string, string, list<string>, string}> */
	public static function provideExplicitFormat(): iterable
	{
		yield 'png to jpg' => ['test.png', 'jpg', self::SERVER_ALL, 'jpg'];
		yield 'png stays png' => ['test.png', 'png', self::SERVER_ALL, 'png'];
		yield 'jpg to png' => ['test.jpg', 'png', self::SERVER_ALL, 'png'];
		yield 'jpg to jxl, not accepted by the browser' => ['test.jpg', 'jxl', self::SERVER_ALL, 'jxl'];
		yield 'uppercase' => ['test.jpg', 'JXL', self::SERVER_ALL, 'jxl'];
	}


	/** @param list<string> $server */
	#[DataProvider('provideExplicitFormat')]
	public function testExplicitFormat(string $file, string $format, array $server, string $expected): void
	{
		// an explicit format does not depend on the browser
		$this->assertSame($expected, $this->create('', $server)->getOutputFormat($file, $format));
	}


	public function testExplicitFormatNotSupportedByServer(): void
	{
		$this->expectException(UnsupportedFormatException::class);
		$this->create(self::ACCEPT_ALL, self::SERVER_WEBP_AVIF)->getOutputFormat('test.jpg', 'jxl');
	}


	public function testStaticFormatDoesNotAssumeJxl(): void
	{
		$outputFormat = $this->create('', self::SERVER_ALL, ['upgradeJpg2Jxl' => true]);

		// WEBP/AVIF are assumed, JXL is not
		$this->assertSame('webp', $outputFormat->getStaticOutputFormat('test.jpg'));
		$this->assertSame('webp', $outputFormat->getStaticOutputFormat('test.jxl'));
		// explicit format, e.g. <source type="image/jxl">
		$this->assertSame('jxl', $outputFormat->getStaticOutputFormat('test.jpg', 'jxl'));
	}


	public function testStaticFormatWithLegacyPriority(): void
	{
		$outputFormat = $this->create('', self::SERVER_ALL, ['formatPriority' => ['avif', 'webp']]);
		$this->assertSame('avif', $outputFormat->getStaticOutputFormat('test.png'));
	}


	/**
	 * @param list<string> $server formats supported by the server
	 * @param array<string, mixed> $overrides ResizerConfigDTO properties
	 */
	private function create(string $accept, array $server, array $overrides = []): OutputFormat
	{
		$config = new ResizerConfigDTO;
		$config->tempDir = __DIR__ . '/../temp';
		$config->wwwDir = __DIR__;
		$config->library = 'Gd';
		$config->isWebpSupportedByServer = in_array('webp', $server, true);
		$config->isAvifSupportedByServer = in_array('avif', $server, true);
		$config->isJxlSupportedByServer = in_array('jxl', $server, true);

		foreach ($overrides as $property => $value) {
			$config->$property = $value;
		}

		$headers = $accept === '' ? [] : ['Accept' => $accept];

		return new OutputFormat(new Request(new UrlScript, headers: $headers), new ResizerConfig($config));
	}
}
