<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nelson\Resizer\DI\ResizerConfigDTO;
use Nette\SmartObject;

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


	public function __construct(
		private readonly ResizerConfigDTO $config,
	)
	{
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
			$qualityPng = (int) round($quality / 10);

			if ($qualityPng > 9) {
				$qualityPng = 9;
			}
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

}
