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

	/**
	 * Absolute path to a directory inside wwwDir. When set, thumbnails are stored there
	 * and {rlink} points directly to the existing files, so they can be served statically.
	 */
	public ?string $publicCacheDir = null;

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

	/**
	 * Maximum requested width/height. The output area is limited to maxWidth * maxHeight as well
	 * (a crop may need a larger intermediate image). Protects against DoS by huge thumbnails.
	 * @var positive-int
	 */
	public int $maxWidth = 4000;

	/** @var positive-int */
	public int $maxHeight = 4000;

	/**
	 * Secret key for signing URLs (at least 32 characters). When set, the resize presenter
	 * refuses requests without a valid signature. Null disables signing.
	 */
	public ?string $secret = null;
}
