<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

final class ResizerConfigDTO
{
	/** @var string 'Gd'|'Imagick'|'Gmagick' */
	public string $library;
	public bool $interlace = true;
	public string $wwwDir;
	public string $tempDir;
	public string $cache = '/resizer/';

	public bool $upgradeJpg2Webp = true;
	public bool $upgradePng2Webp = true;
	public bool $upgradeJpg2Avif = true;
	public bool $upgradePng2Avif = true;

	public bool $isWebpSupportedByServer = false;
	public bool $isAvifSupportedByServer = false;

	public bool $strip = true;

	/** @var int<0, 100> */
	public int $qualityAvif = 70;

	/** @var int<0, 100> */
	public int $qualityWebp = 70;

	/** @var int<0, 100> */
	public int $qualityJpeg = 70;

	/** @var int<0, 9> */
	public int $compressionPng = 9;
}
