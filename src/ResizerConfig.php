<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nelson\Resizer\DI\ResizerConfigDTO;
use Nette\SmartObject;

final class ResizerConfig
{

	use SmartObject;

	public function __construct(
		private ResizerConfigDTO $config,
	)
	{
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


	/**
	 * @return array{
	 * 	avif_quality: int<0, 100>,
	 * 	webp_quality: int<0, 100>,
	 * 	jpeg_quality: int<0, 100>,
	 * 	png_compression_level: int<0, 9>
	 * }
	 */
	public function getOptions(): array
	{
		return [
			'avif_quality' => $this->getQualityAvif(),
			'webp_quality' => $this->getQualityWebp(),
			'jpeg_quality' => $this->getQualityJpeg(),
			'png_compression_level' => $this->getCompressionPng(),
		];
	}

}