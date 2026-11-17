<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nelson\Resizer\Exceptions\UnsupportedFormatException;
use Nette\Http\Helpers;
use Nette\Http\IRequest;

/**
 * Resolves the output format of a thumbnail:
 * - an explicitly requested format is used as is (if the server supports it),
 * - a WEBP/AVIF/JXL source keeps its format when the browser accepts it, otherwise the first format
 *   of formatPriority the browser accepts is used, JPEG as the last resort,
 * - a JPEG/PNG/GIF source is upgraded to the first format of formatPriority the browser accepts
 *   and the upgrade is enabled for (GIF to WEBP only, it keeps animations), otherwise it keeps its format.
 */
final class OutputFormat
{
	/**
	 * Modern formats the browser declares in the Accept header (without the q=0 ones).
	 * @var list<string>
	 */
	private array $browserFormats;


	public function __construct(
		private readonly IRequest $request,
		private readonly ResizerConfig $config,
	) {
		$this->browserFormats = $this->parseAccept((string) $this->request->getHeader('accept'));
	}


	public function getOutputFormat(string $file, ?string $format = null): string
	{
		return $this->resolve($file, $format, $this->browserFormats);
	}


	/**
	 * Output format independent of the current request's Accept header - assumes the browser supports
	 * WEBP and AVIF, so the result depends only on the server support and the config.
	 * Used for static links, where the format has to be known when the link is generated.
	 * JPEG XL is not assumed: browsers without its support are still common (Edge, older versions).
	 * Use an explicit format for it, e.g. <source type="image/jxl"> in <picture>.
	 */
	public function getStaticOutputFormat(string $file, ?string $format = null): string
	{
		return $this->resolve($file, $format, [Resizer::FORMAT_SUFFIX_WEBP, Resizer::FORMAT_SUFFIX_AVIF]);
	}


	/** @param list<string> $browserFormats */
	private function resolve(string $file, ?string $format, array $browserFormats): string
	{
		$format = strtolower($format ?? $this->negotiate($this->getSourceFormat($file), $browserFormats));
		$this->assertFormatSupported($format);
		return $format;
	}


	/** @param list<string> $browserFormats */
	private function negotiate(string $source, array $browserFormats): string
	{
		$canServe = fn(string $format): bool => in_array($format, $browserFormats, true)
			&& $this->config->isFormatSupportedByServer($format);

		if (in_array($source, Resizer::MODERN_FORMATS, true)) {
			if ($canServe($source)) {
				return $source;
			}

			foreach ($this->config->getFormatPriority() as $format) {
				if ($canServe($format)) {
					return $format;
				}
			}

			return Resizer::FORMAT_SUFFIX_JPG;
		}

		if (in_array($source, [Resizer::FORMAT_SUFFIX_JPG, Resizer::FORMAT_SUFFIX_PNG, Resizer::FORMAT_SUFFIX_GIF], true)) {
			foreach ($this->config->getFormatPriority() as $format) {
				if ($canServe($format) && $this->config->canUpgrade($source, $format)) {
					return $format;
				}
			}
		}

		return $source;
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


	/**
	 * Only explicitly listed types count, "image/*" does not mean the browser decodes every format.
	 * @return list<string>
	 */
	private function parseAccept(string $accept): array
	{
		$formats = [];

		foreach (array_keys(Helpers::parseQualityList($accept)) as $mimeType) {
			$format = array_search($mimeType, Resizer::MIME_TYPES, true);

			if (is_string($format) && in_array($format, Resizer::MODERN_FORMATS, true)) {
				$formats[] = $format;
			}
		}

		return $formats;
	}


	/**
	 * Format of the source file by its suffix (all JPEG suffixes are "jpg").
	 */
	public function getSourceFormat(string $file): string
	{
		$suffix = strtolower(pathinfo($file, PATHINFO_EXTENSION));

		return in_array($suffix, Resizer::FORMAT_SUFFIXES_JPG, true)
			? Resizer::FORMAT_SUFFIX_JPG
			: $suffix;
	}
}
