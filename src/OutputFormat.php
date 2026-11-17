<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nelson\Resizer\Exceptions\UnsupportedFormatException;
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
		return $this->resolve($file, $format, $this->browserSupportsWebp, $this->browserSupportsAvif);
	}


	/**
	 * Output format independent of the current request's Accept header - assumes the browser supports
	 * both WEBP and AVIF, so the result depends only on the server support and the upgrade settings.
	 * Used for static links, where the format has to be known when the link is generated.
	 */
	public function getStaticOutputFormat(string $file, ?string $format = null): string
	{
		return $this->resolve($file, $format, true, true);
	}


	private function resolve(
		string $file,
		?string $format,
		bool $browserSupportsWebp,
		bool $browserSupportsAvif,
	): string {
		if ($format === null) {
			$suffix = $this->getFileFormat($file);
			$canServeWebp = $this->config->isWebpSupportedByServer() && $browserSupportsWebp;
			$canServeAvif = $this->config->isAvifSupportedByServer() && $browserSupportsAvif;

			$format = match ($suffix) {
				Resizer::FORMAT_SUFFIX_JPG => $this->getOutputFormatForJpg($canServeWebp, $canServeAvif),
				Resizer::FORMAT_SUFFIX_PNG => $this->getOutputFormatForPng($canServeWebp, $canServeAvif),
				Resizer::FORMAT_SUFFIX_WEBP => $this->getOutputFormatForWebp($canServeWebp, $canServeAvif),
				Resizer::FORMAT_SUFFIX_AVIF => $this->getOutputFormatForAvif($canServeWebp, $canServeAvif),
				default => $suffix,
			};
		}

		$format = strtolower($format);
		$this->assertFormatSupported($format);
		return $format;
	}


	private function getOutputFormatForWebp(bool $canServeWebp, bool $canServeAvif): string
	{
		return match (true) {
			$canServeWebp => Resizer::FORMAT_SUFFIX_WEBP,
			$canServeAvif => Resizer::FORMAT_SUFFIX_AVIF,
			default => Resizer::FORMAT_SUFFIX_JPG,
		};
	}


	private function getOutputFormatForAvif(bool $canServeWebp, bool $canServeAvif): string
	{
		return match (true) {
			$canServeAvif => Resizer::FORMAT_SUFFIX_AVIF,
			$canServeWebp => Resizer::FORMAT_SUFFIX_WEBP,
			default => Resizer::FORMAT_SUFFIX_JPG,
		};
	}


	private function getOutputFormatForJpg(bool $canServeWebp, bool $canServeAvif): string
	{
		return match (true) {
			$canServeAvif && $this->config->canUpgradeJpg2Avif() => Resizer::FORMAT_SUFFIX_AVIF,
			$canServeWebp && $this->config->canUpgradeJpg2Webp() => Resizer::FORMAT_SUFFIX_WEBP,
			default => Resizer::FORMAT_SUFFIX_JPG,
		};
	}


	private function getOutputFormatForPng(bool $canServeWebp, bool $canServeAvif): string
	{
		return match (true) {
			$canServeAvif && $this->config->canUpgradePng2Avif() => Resizer::FORMAT_SUFFIX_AVIF,
			$canServeWebp && $this->config->canUpgradePng2Webp() => Resizer::FORMAT_SUFFIX_WEBP,
			default => Resizer::FORMAT_SUFFIX_PNG,
		};
	}


	private function assertFormatSupported(string $format): void
	{
		if (!in_array($format, $this->config->getSupportedFormats(), true)) {
			throw new UnsupportedFormatException(sprintf(
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
