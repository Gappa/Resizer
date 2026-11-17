<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Exception;
use Nelson\Resizer\DI\ResizerConfigDTO;
use Nette\SmartObject;
use Nette\Utils\FileSystem;

final class ResizerConfig
{

	use SmartObject;

	/** @var array<int, string> */
	private array $supportedFormats = [
		'jpeg',
		'jpg',
		'gif',
		'png',
	];

	/** Public cache directory relative to wwwDir, with a trailing slash; null when static mode is off */
	private ?string $publicCacheUrlPath = null;


	public function __construct(
		private readonly ResizerConfigDTO $config,
	)
	{
		if ($config->publicCacheDir !== null) {
			$this->publicCacheUrlPath = $this->resolvePublicCacheUrlPath($config->wwwDir, $config->publicCacheDir);
		}

		if ($config->isWebpSupportedByServer) {
			$this->supportedFormats[] = Resizer::FORMAT_SUFFIX_WEBP;
		}

		if ($config->isAvifSupportedByServer) {
			$this->supportedFormats[] = Resizer::FORMAT_SUFFIX_AVIF;
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


	/** @return int<0, 9> */
	public function getCompressionPng(): int
	{
		return $this->config->compressionPng;
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


	/**
	 * @param int<0,100>|null $quality
	 * @return array{
	 * 	avif_quality: int<0, 100>,
	 * 	webp_quality: int<0, 100>,
	 * 	jpeg_quality: int<0, 100>,
	 * 	png_compression_level: int<0, 9>
	 * }
	 */
	public function getOptions(?int $quality = null): array
	{

		if ($quality !== null) {
			/** @var int<0, 9> $qualityPng */
			$qualityPng = (int) round($this->remapRange($quality, 0, 100, 0, 9));
		}

		return [
			'avif_quality' => $quality ?? $this->getQualityAvif() ,
			'webp_quality' => $quality ?? $this->getQualityWebp(),
			'jpeg_quality' => $quality ?? $this->getQualityJpeg(),
			'png_compression_level' => $qualityPng ?? $this->getCompressionPng(),
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


	/**
	 * @see https://stackoverflow.com/a/36244586/2458557
	 */
	private function remapRange(
		int $intValue,
		int $oMin,
		int $oMax,
		int $nMin,
		int $nMax
	): float {
		// Range check
		if ($oMin === $oMax) {
			throw new Exception('Warning: Zero input range');
		}

		if ($nMin === $nMax) {
			throw new Exception('Warning: Zero output range');
		}

		// Check reversed input range
		$bReverseInput = false;
		$intOldMin = min($oMin, $oMax);
		$intOldMax = max($oMin, $oMax);
		if ($intOldMin !== $oMin) {
			$bReverseInput = true;
		}

		// Check reversed output range
		$bReverseOutput = false;
		$intNewMin = min($nMin, $nMax);
		$intNewMax = max($nMin, $nMax);
		if ($intNewMin !== $nMin) {
			$bReverseOutput = true;
		}

		$fRatio = ($intValue - $intOldMin) * ($intNewMax - $intNewMin) / ($intOldMax - $intOldMin);
		if ($bReverseInput) {
			$fRatio = ($intOldMax - $intValue) * ($intNewMax - $intNewMin) / ($intOldMax - $intOldMin);
		}

		$fResult = $fRatio + $intNewMin;
		if ($bReverseOutput) {
			$fResult = $intNewMax - $fRatio;
		}

		return $fResult;
	}

}
