<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\ResizerConfig;
use PHPUnit\Framework\TestCase;

class ResizerConfigTest extends TestCase
{
	public function testEncoderOptionsDefault(): void
	{
		$config = $this->create();

		foreach (['avif', 'jxl', 'webp', 'jpg', 'png'] as $format) {
			$this->assertSame([], $config->getImagickEncoderOptions($format), $format);
		}
	}


	public function testEncoderOptions(): void
	{
		$config = $this->create(avifSpeed: 8, jxlEffort: 5, webpMethod: 6);

		$this->assertSame(['heic:speed' => '8'], $config->getImagickEncoderOptions('avif'));
		$this->assertSame(['jxl:effort' => '5'], $config->getImagickEncoderOptions('jxl'));
		$this->assertSame(['webp:method' => '6'], $config->getImagickEncoderOptions('webp'));
		$this->assertSame([], $config->getImagickEncoderOptions('jpg'));
	}


	public function testZeroIsNotDefault(): void
	{
		$this->assertSame(['heic:speed' => '0'], $this->create(avifSpeed: 0)->getImagickEncoderOptions('avif'));
	}


	public function testQualityOptions(): void
	{
		$this->assertSame(
			['avif_quality' => 50, 'webp_quality' => 50, 'jpeg_quality' => 50, 'jxl_quality' => 50, 'jxl_lossless' => false, 'png_compression_level' => 5],
			$this->create()->getOptions(50),
		);

		$this->assertTrue($this->create()->getOptions(100)['jxl_lossless']);
		$this->assertSame(9, $this->create()->getOptions(100)['png_compression_level']);
	}


	/**
	 * @param int<0, 10>|null $avifSpeed
	 * @param int<1, 9>|null $jxlEffort
	 * @param int<0, 6>|null $webpMethod
	 */
	private function create(?int $avifSpeed = null, ?int $jxlEffort = null, ?int $webpMethod = null): ResizerConfig
	{
		$config = new ResizerConfigDTO;
		$config->tempDir = __DIR__ . '/../temp';
		$config->wwwDir = __DIR__;
		$config->library = 'Imagick';
		$config->avifSpeed = $avifSpeed;
		$config->jxlEffort = $jxlEffort;
		$config->webpMethod = $webpMethod;

		return new ResizerConfig($config);
	}
}
