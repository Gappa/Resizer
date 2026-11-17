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
	public bool $upgradeJpg2Jxl = false;
	public bool $upgradePng2Jxl = false;

	/**
	 * Order in which the modern formats are tried when upgrading JPEG/PNG, or when the browser does not
	 * support the format of a WEBP/AVIF/JXL source. A format missing in the list is never used for that.
	 * AVIF is last by default, its encoding is slow.
	 * @var list<'jxl'|'webp'|'avif'>
	 */
	public array $formatPriority = ['jxl', 'webp', 'avif'];

	public bool $isWebpSupportedByServer = false;
	public bool $isAvifSupportedByServer = false;
	public bool $isJxlSupportedByServer = false;

	public bool $strip = true;

	/** @var int<0, 100> */
	public int $qualityAvif = 70;

	/** @var int<0, 100> */
	public int $qualityWebp = 70;

	/** @var int<0, 100> */
	public int $qualityJpeg = 70;

	/** @var int<0, 100> Imagine clamps it to 9-99, 100 in the params means lossless */
	public int $qualityJxl = 70;

	/** @var int<0, 9> */
	public int $compressionPng = 9;

	/**
	 * Encoder speed/effort, Imagick only (Imagine has no such options, GD uses its defaults).
	 * Null = the library default.
	 * @var int<0, 10>|null AVIF speed, higher = faster and slightly bigger (ImageMagick "heic:speed")
	 */
	public ?int $avifSpeed = null;

	/** @var int<1, 9>|null JPEG XL effort, lower = faster and slightly bigger (ImageMagick "jxl:effort", default 7) */
	public ?int $jxlEffort = null;

	/** @var int<0, 6>|null WEBP method, higher = slower and smaller (ImageMagick "webp:method", default 4) */
	public ?int $webpMethod = null;

	/**
	 * Maximum requested width/height. The output area is limited to maxWidth * maxHeight as well
	 * (a crop may need a larger intermediate image). Protects against DoS by huge thumbnails.
	 * @var positive-int
	 */
	public int $maxWidth = 4000;

	/** @var positive-int */
	public int $maxHeight = 4000;

	/**
	 * Allow thumbnails bigger than the source. When false, a smaller source is kept at its size
	 * (bigger files with no added detail are avoided) and a crop box bigger than the source is scaled down
	 * keeping its aspect ratio. Forced dimensions ("!") are always respected.
	 */
	public bool $allowUpscale = false;

	/**
	 * Secret key for signing URLs (at least 32 characters). When set, the resize presenter
	 * refuses requests without a valid signature. Null disables signing.
	 */
	public ?string $secret = null;
}
