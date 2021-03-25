<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

final class ResizerConfig
{
	public string $library;
	public bool $absoluteUrls = false;
	public bool $interlace = true;
	public string $wwwDir;
	public string $tempDir;
	public string $cache = '/resizer/';
	public int $qualityWebp;
	public int $qualityJpeg;
	public int $compressionPng;
	public bool $upgradeJpg2Webp = true;
}
