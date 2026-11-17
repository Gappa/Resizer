<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Exception;
use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\ResizerConfig;
use Nelson\Resizer\UrlSigner;
use PHPUnit\Framework\TestCase;

class UrlSignerTest extends TestCase
{
	public const string SECRET = 'test-secret-test-secret-test-secret';


	public function testDisabled(): void
	{
		$signer = $this->create(null);

		$this->assertFalse($signer->isEnabled());
		$this->assertNull($signer->sign('fixtures/test.png', 'c100xc100'));
		$this->assertTrue($signer->verify('fixtures/test.png', 'c100xc100', null));
		$this->assertTrue($signer->verify('fixtures/test.png', 'c100xc100', 'anything'));
	}


	public function testKnownSignature(): void
	{
		// changing the algorithm invalidates all URLs in the wild - this must stay stable
		$this->assertSame(
			'a23hnFsEigBkKqPIhSEs8w',
			$this->create(self::SECRET)->sign('fixtures/test.png', 'c100xc100'),
		);
	}


	public function testSignAndVerify(): void
	{
		$signer = $this->create(self::SECRET);
		$signature = (string) $signer->sign('fixtures/test.png', 'c100xc100');

		$this->assertTrue($signer->isEnabled());
		$this->assertMatchesRegularExpression('~^[A-Za-z0-9_-]{22}$~', $signature);
		$this->assertTrue($signer->verify('fixtures/test.png', 'c100xc100', $signature));

		$this->assertFalse($signer->verify('fixtures/test.png', 'c100xc100', null));
		$this->assertFalse($signer->verify('fixtures/test.png', 'c100xc100', ''));
		$this->assertFalse($signer->verify('fixtures/test.png', 'c100xc101', $signature));
		$this->assertFalse($signer->verify('fixtures/other.png', 'c100xc100', $signature));
		$this->assertFalse($signer->verify('fixtures/test.png', 'c100xc100', strtolower($signature)));
	}


	public function testDifferentSecret(): void
	{
		$signature = $this->create(self::SECRET)->sign('fixtures/test.png', 'c100xc100');
		$this->assertFalse($this->create(str_repeat('x', 32))->verify('fixtures/test.png', 'c100xc100', $signature));
	}


	public function testNormalization(): void
	{
		$signer = $this->create(self::SECRET);

		$this->assertSame($signer->sign('fixtures/test.png', 'auto'), $signer->sign('fixtures/test.png', null));
		$this->assertSame($signer->sign('fixtures/test.png', 'x100'), $signer->sign('/fixtures/test.png', 'x100'));
		// the boundary between file and params can't be shifted
		$this->assertNotSame($signer->sign("a\0b", 'x100'), $signer->sign('a', "b\0x100"));
	}


	public function testShortSecret(): void
	{
		$this->expectException(Exception::class);
		$this->create(str_repeat('x', 31));
	}


	private function create(?string $secret): UrlSigner
	{
		$config = new ResizerConfigDTO;
		$config->tempDir = __DIR__ . '/../temp';
		$config->wwwDir = __DIR__;
		$config->library = 'Gd';
		$config->secret = $secret;

		return new UrlSigner(new ResizerConfig($config));
	}
}
