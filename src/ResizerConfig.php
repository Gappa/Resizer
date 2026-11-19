<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Exception;
use Nelson\Resizer\DI\ResizerConfigDTO;
use Nette\Utils\FileSystem;

final class ResizerConfig
{
	/** @var array<int, string> */
	private array $supportedFormats = [
		'jpeg',
		'jpg',
		'gif',
		'png',
	];

	public const int MIN_SECRET_LENGTH = 32;
	public const string SENDFILE_X_SENDFILE = 'x-sendfile';
	public const string SENDFILE_X_ACCEL_REDIRECT = 'x-accel-redirect';

	/** Public cache directory relative to wwwDir, with a trailing slash; null when static mode is off */
	private ?string $publicCacheUrlPath = null;


	public function __construct(
		private readonly ResizerConfigDTO $config,
	)
	{
		if ($config->publicCacheDir !== null) {
			$this->publicCacheUrlPath = $this->resolvePublicCacheUrlPath($config->wwwDir, $config->publicCacheDir);
		}

		if ($config->secret !== null && strlen($config->secret) < self::MIN_SECRET_LENGTH) {
			throw new Exception(sprintf('Resizer: secret must be at least %d characters long.', self::MIN_SECRET_LENGTH));
		}

		if (!in_array($config->sendfile, [null, self::SENDFILE_X_SENDFILE, self::SENDFILE_X_ACCEL_REDIRECT], true)) {
			throw new Exception(sprintf("Resizer: sendfile must be null, '%s' or '%s'.", self::SENDFILE_X_SENDFILE, self::SENDFILE_X_ACCEL_REDIRECT));
		}

		if (
			$config->sendfile === self::SENDFILE_X_ACCEL_REDIRECT
			&& !str_starts_with((string) $config->sendfileInternalUri, '/')
		) {
			throw new Exception("Resizer: sendfile 'x-accel-redirect' needs sendfileInternalUri, an internal nginx location starting with '/', e.g. '/_resizer/'.");
		}

		if ($config->isWebpSupportedByServer) {
			$this->supportedFormats[] = Resizer::FORMAT_SUFFIX_WEBP;
		}

		if ($config->isAvifSupportedByServer) {
			$this->supportedFormats[] = Resizer::FORMAT_SUFFIX_AVIF;
		}

		if ($config->isJxlSupportedByServer) {
			$this->supportedFormats[] = Resizer::FORMAT_SUFFIX_JXL;
		}
	}


	/** @return string 'Gd'|'Imagick'|'Gmagick' */
	public function getLibrary(): string
	{
		return $this->config->library;
	}


	public function isInterlace(): bool
	{
		return $this->config->interlace;
	}


	public function getWwwDir(): string
	{
		return $this->config->wwwDir;
	}


	public function getTempDir(): string
	{
		return $this->config->tempDir;
	}


	public function getCache(): string
	{
		return $this->config->cache;
	}


	/**
	 * Directory of the thumbnails (the public one in static mode), with a trailing slash.
	 */
	public function getCacheDir(): string
	{
		return $this->getPublicCacheDir() ?? $this->getProtectedCacheDir();
	}


	/**
	 * Directory of the thumbnails of protected images (see AccessPolicy), with a trailing slash.
	 * Always the non-public one (tempDir + cache), the same as getCacheDir() when static mode is off.
	 */
	public function getProtectedCacheDir(): string
	{
		return $this->config->tempDir . $this->config->cache;
	}


	/**
	 * How long browsers may cache an allowed protected image (seconds), 0 = revalidate every time.
	 * @return int<0, max>
	 */
	public function getProtectedMaxAge(): int
	{
		return $this->config->protectedMaxAge;
	}


	/** One of the SENDFILE_* constants, null when PHP sends the file */
	public function getSendfile(): ?string
	{
		return $this->config->sendfile;
	}


	/**
	 * Internal nginx URI of the cache directory for X-Accel-Redirect, with a trailing slash.
	 */
	public function getSendfileInternalUri(): ?string
	{
		return $this->config->sendfileInternalUri === null ? null : rtrim($this->config->sendfileInternalUri, '/') . '/';
	}


	/**
	 * Static mode - thumbnails are stored in a public directory and linked directly.
	 */
	public function isStatic(): bool
	{
		return $this->publicCacheUrlPath !== null;
	}


	/**
	 * Absolute filesystem path to the public cache directory, with a trailing slash.
	 */
	public function getPublicCacheDir(): ?string
	{
		if ($this->config->publicCacheDir === null) {
			return null;
		}

		return rtrim(FileSystem::normalizePath($this->config->publicCacheDir), '/') . '/';
	}


	/**
	 * Public cache directory relative to wwwDir (e.g. "cache/ir/"), with a trailing slash.
	 */
	public function getPublicCacheUrlPath(): ?string
	{
		return $this->publicCacheUrlPath;
	}


	public function canUpgradeJpg2Webp(): bool
	{
		return $this->config->upgradeJpg2Webp;
	}


	public function canUpgradePng2Avif(): bool
	{
		return $this->config->upgradePng2Avif;
	}


	public function canUpgradeJpg2Avif(): bool
	{
		return $this->config->upgradeJpg2Avif;
	}


	public function canUpgradePng2Webp(): bool
	{
		return $this->config->upgradePng2Webp;
	}


	public function isWebpSupportedByServer(): bool
	{
		return $this->config->isWebpSupportedByServer;
	}


	public function isAvifSupportedByServer(): bool
	{
		return $this->config->isAvifSupportedByServer;
	}


	public function isJxlSupportedByServer(): bool
	{
		return $this->config->isJxlSupportedByServer;
	}


	public function canUpgradeJpg2Jxl(): bool
	{
		return $this->config->upgradeJpg2Jxl;
	}


	public function canUpgradePng2Jxl(): bool
	{
		return $this->config->upgradePng2Jxl;
	}


	public function canUpgradeGif2Webp(): bool
	{
		return $this->config->upgradeGif2Webp;
	}


	/** @return positive-int */
	public function getMaxAnimationPixels(): int
	{
		return $this->config->maxAnimationPixels;
	}


	/**
	 * The server can write the format (JPEG, PNG and GIF always).
	 */
	public function isFormatSupportedByServer(string $format): bool
	{
		return in_array($format, $this->supportedFormats, true);
	}


	/**
	 * Upgrade of a JPEG/PNG/GIF source to a modern format is enabled.
	 */
	public function canUpgrade(string $sourceFormat, string $targetFormat): bool
	{
		return match ([$sourceFormat, $targetFormat]) {
			[Resizer::FORMAT_SUFFIX_JPG, Resizer::FORMAT_SUFFIX_WEBP] => $this->canUpgradeJpg2Webp(),
			[Resizer::FORMAT_SUFFIX_JPG, Resizer::FORMAT_SUFFIX_AVIF] => $this->canUpgradeJpg2Avif(),
			[Resizer::FORMAT_SUFFIX_JPG, Resizer::FORMAT_SUFFIX_JXL] => $this->canUpgradeJpg2Jxl(),
			[Resizer::FORMAT_SUFFIX_PNG, Resizer::FORMAT_SUFFIX_WEBP] => $this->canUpgradePng2Webp(),
			[Resizer::FORMAT_SUFFIX_PNG, Resizer::FORMAT_SUFFIX_AVIF] => $this->canUpgradePng2Avif(),
			[Resizer::FORMAT_SUFFIX_PNG, Resizer::FORMAT_SUFFIX_JXL] => $this->canUpgradePng2Jxl(),
			// AVIF/JXL animations are not supported by the encoders here, WEBP only
			[Resizer::FORMAT_SUFFIX_GIF, Resizer::FORMAT_SUFFIX_WEBP] => $this->canUpgradeGif2Webp(),
			default => false,
		};
	}


	/**
	 * Order of modern formats for upgrades and fallbacks, see ResizerConfigDTO::$formatPriority.
	 * @return list<string>
	 */
	public function getFormatPriority(): array
	{
		return $this->config->formatPriority;
	}


	public function isStrip(): bool
	{
		return $this->config->strip;
	}


	/** @return int<0, 100> */
	public function getQualityAvif(): int
	{
		return $this->config->qualityAvif;
	}


	/** @return int<0, 100> */
	public function getQualityWebp(): int
	{
		return $this->config->qualityWebp;
	}


	/** @return int<0, 100> */
	public function getQualityJpeg(): int
	{
		return $this->config->qualityJpeg;
	}


	/** @return int<0, 100> */
	public function getQualityJxl(): int
	{
		return $this->config->qualityJxl;
	}


	/** @return int<0, 9> */
	public function getCompressionPng(): int
	{
		return $this->config->compressionPng;
	}


	/**
	 * ImageMagick options for the encoder of the format (speed/effort), empty for the library defaults.
	 * @return array<string, string>
	 */
	public function getImagickEncoderOptions(string $format): array
	{
		$options = match ($format) {
			Resizer::FORMAT_SUFFIX_AVIF => ['heic:speed' => $this->config->avifSpeed],
			Resizer::FORMAT_SUFFIX_JXL => ['jxl:effort' => $this->config->jxlEffort],
			Resizer::FORMAT_SUFFIX_WEBP => ['webp:method' => $this->config->webpMethod],
			default => [],
		};

		return array_map(strval(...), array_filter($options, static fn(?int $value): bool => $value !== null));
	}


	/** @return positive-int */
	public function getMaxWidth(): int
	{
		return $this->config->maxWidth;
	}


	/** @return positive-int */
	public function getMaxHeight(): int
	{
		return $this->config->maxHeight;
	}


	/** @return int<0, max> */
	public function getPlaceholderBudget(): int
	{
		return $this->config->placeholderBudget;
	}


	public function isUpscaleAllowed(): bool
	{
		return $this->config->allowUpscale;
	}


	/** Secret for signing URLs, null when signing is disabled */
	public function getSecret(): ?string
	{
		return $this->config->secret;
	}


	/**
	 * @param int<0,100>|null $quality
	 * @return array{
	 * 	avif_quality: int<0, 100>,
	 * 	webp_quality: int<0, 100>,
	 * 	jpeg_quality: int<0, 100>,
	 * 	jxl_quality: int<0, 100>,
	 * 	jxl_lossless: bool,
	 * 	png_compression_level: int<0, 9>
	 * }
	 */
	public function getOptions(?int $quality = null): array
	{
		if ($quality === null) {
			return [
				'avif_quality' => $this->getQualityAvif(),
				'webp_quality' => $this->getQualityWebp(),
				'jpeg_quality' => $this->getQualityJpeg(),
				'jxl_quality' => $this->getQualityJxl(),
				'jxl_lossless' => false,
				'png_compression_level' => $this->getCompressionPng(),
			];
		}

		/** @var int<0, 9> $compressionPng PNG is lossless, quality 0-100 maps to compression level 0-9 */
		$compressionPng = (int) round($quality * 9 / 100);

		return [
			'avif_quality' => $quality,
			'webp_quality' => $quality,
			'jpeg_quality' => $quality,
			'jxl_quality' => $quality,
			// Imagine caps jxl_quality at 99, lossless has its own option
			'jxl_lossless' => $quality === 100,
			'png_compression_level' => $compressionPng,
		];
	}


	/** @return array<int, string> */
	public function getSupportedFormats(): array
	{
		return $this->supportedFormats;
	}


	private function resolvePublicCacheUrlPath(string $wwwDir, string $publicCacheDir): string
	{
		$www = rtrim(FileSystem::normalizePath($wwwDir), '/') . '/';
		$public = rtrim(FileSystem::normalizePath($publicCacheDir), '/') . '/';

		if (!str_starts_with($public, $www) || $public === $www) {
			throw new Exception(sprintf(
				"Resizer: publicCacheDir '%s' must be a subdirectory of wwwDir '%s'.",
				$publicCacheDir,
				$wwwDir,
			));
		}

		return substr($public, strlen($www));
	}

}
