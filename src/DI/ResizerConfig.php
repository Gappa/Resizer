<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

final class ResizerConfig
{
	public string $library;
	public bool $interlace = true;
	public string $wwwDir;
	public string $tempDir;
	public string $cache = '/resizer/';
	public bool $upgradeJpg2Webp = true;
	public bool $strip = true;

	/** @var int<0, 100> */
	public int $qualityWebp;

	/** @var int<0, 100> */
	public int $qualityJpeg;

	/** @var int<0, 9> */
	public int $compressionPng;
}
