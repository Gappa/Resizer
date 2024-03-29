<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Exception;
use Nette\Http\Request;
use Nette\SmartObject;

final class OutputFormat
{
	use SmartObject;

	private bool $browserSupportsWebp;
	private bool $browserSupportsAvif;


	public function __construct(
		private readonly Request $request,
		private readonly ResizerConfig $config,
	)
	{
		$this->browserSupportsAvif = $this->browserSupports(Resizer::MIME_TYPE_AVIF);
		$this->browserSupportsWebp = $this->browserSupports(Resizer::MIME_TYPE_WEBP);
	}


	public function getOutputFormat(string $file, ?string $format = null): ?string
	{
		if ($format === null) {
			$suffix = $this->getFileFormat($file);

			$format = match ($suffix) {
				Resizer::FORMAT_SUFFIX_JPG => $this->getOutputFormatForJpg(),
				Resizer::FORMAT_SUFFIX_PNG => $this->getOutputFormatForPng(),
				Resizer::FORMAT_SUFFIX_WEBP => $this->getOutputFormatForWebp(),
				Resizer::FORMAT_SUFFIX_AVIF => $this->getOutputFormatForAvif(),
				default => $suffix,
			};
		}

		$this->isFormatSupported($format);
		return $format;
	}


	private function getOutputFormatForWebp(): string
	{
		return match (true) {
			$this->canServeWebp() => Resizer::FORMAT_SUFFIX_WEBP,
			$this->canServeAvif() => Resizer::FORMAT_SUFFIX_AVIF,
			default => Resizer::FORMAT_SUFFIX_JPG,
		};
	}


	private function getOutputFormatForAvif(): string
	{
		return match (true) {
			$this->canServeAvif() => Resizer::FORMAT_SUFFIX_AVIF,
			$this->canServeWebp() => Resizer::FORMAT_SUFFIX_WEBP,
			default => Resizer::FORMAT_SUFFIX_JPG,
		};
	}


	private function getOutputFormatForJpg(): string
	{
		return match (true) {
			$this->canServeAvif() && $this->config->canUpgradeJpg2Avif() => Resizer::FORMAT_SUFFIX_AVIF,
			$this->canServeWebp() && $this->config->canUpgradeJpg2Webp() => Resizer::FORMAT_SUFFIX_WEBP,
			default => Resizer::FORMAT_SUFFIX_JPG,
		};
	}


	private function getOutputFormatForPng(): string
	{
		return match (true) {
			$this->canServeAvif() && $this->config->canUpgradePng2Avif() => Resizer::FORMAT_SUFFIX_AVIF,
			$this->canServeWebp() && $this->config->canUpgradePng2Webp() => Resizer::FORMAT_SUFFIX_WEBP,
			default => Resizer::FORMAT_SUFFIX_PNG,
		};
	}


	private function canServeWebp(): bool
	{
		return $this->config->isWebpSupportedByServer() && $this->browserSupportsWebp;
	}


	private function canServeAvif(): bool
	{
		return $this->config->isAvifSupportedByServer() && $this->browserSupportsAvif;
	}


	private function isFormatSupported(string $format): void
	{
		if (!in_array(strtolower($format), $this->config->getSupportedFormats(), true)) {
			throw new Exception(sprintf(
				"Format '%s' not supported (%s).",
				$format, implode(', ', $this->config->getSupportedFormats()),
			));
		}
	}


	private function browserSupports(string $format): bool
	{
		$accept = (string) $this->request->getHeader('accept');
		return str_contains($accept, $format);
	}


	/** @param string|array<int, string> $suffixes */
	private function isFileOfFormat(string $path, string|array $suffixes): bool
	{
		if (is_string($suffixes)) {
			$suffixes = [$suffixes];
		}

		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		return in_array($ext, $suffixes, true);
	}


	private function isFileJpg(string $path): bool
	{
		return $this->isFileOfFormat($path, Resizer::FORMAT_SUFFIXES_JPG);
	}


	private function getFileFormat(string $file): string
	{
		return match (true) {
			$this->isFileJpg($file) => Resizer::FORMAT_SUFFIX_JPG,
			default => pathinfo($file, PATHINFO_EXTENSION),
		};
	}

}
