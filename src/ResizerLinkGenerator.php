<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nelson\Resizer\DI\ResizerExtension;
use Nelson\Resizer\Placeholder\Placeholder;
use Nelson\Resizer\Placeholder\PlaceholderGenerator;
use Nette\Application\LinkGenerator;
use Nette\Application\UI\Component;
use Nette\Http\IRequest;
use Nette\InvalidArgumentException;
use Throwable;

/**
 * Static mode helper: resolves links to thumbnails stored in the public cache directory,
 * so existing thumbnails are served by the web server without running PHP.
 */
final class ResizerLinkGenerator
{
	/** Default value of the params route parameter */
	public const string PARAMS_AUTO = 'auto';


	public function __construct(
		private readonly ResizerConfig $config,
		private readonly OutputFormat $outputFormat,
		private readonly IResizer $resizer,
		private readonly IRequest $httpRequest,
		private readonly UrlSigner $urlSigner,
		private readonly ?PlaceholderGenerator $placeholders = null,
	) {
	}


	public function isStatic(): bool
	{
		return $this->config->isStatic();
	}


	/** Dynamic links are signed (a secret is configured) */
	public function isSigned(): bool
	{
		return $this->urlSigner->isEnabled();
	}


	/**
	 * Public cache directory relative to the web root (e.g. "cache/ir/"), null when static mode is off.
	 */
	public function getPublicCacheUrlPath(): ?string
	{
		return $this->config->getPublicCacheUrlPath();
	}


	/**
	 * Link to a thumbnail for use in PHP code: the static URL when the thumbnail exists,
	 * otherwise a link to the resize presenter (with the format filled in in static mode).
	 * The static URL uses the base URL of the current HTTP request (in CLI the one set by the console),
	 * the same one the LinkGenerator service uses by default.
	 * @param Component|LinkGenerator $linker presenter/control (relative or absolute link) or LinkGenerator (always absolute)
	 */
	public function link(
		Component|LinkGenerator $linker,
		string $file,
		?string $params = null,
		?string $format = null,
		bool $absolute = false,
	): string {
		$isLinkGenerator = $linker instanceof LinkGenerator;
		$absolute = $absolute || $isLinkGenerator;

		$staticUrl = $this->getStaticUrl($file, $params, $format, $absolute);

		if ($staticUrl !== null) {
			return $staticUrl;
		}

		$destination = ResizerExtension::getResizerLink(!$isLinkGenerator);

		if ($absolute && !$isLinkGenerator) {
			$destination = '//' . $destination;
		}

		$args = [$file, $params, $this->resolveFormat($file, $format)];
		$signature = $this->urlSigner->sign($file, $params);

		if ($signature !== null) {
			$args[] = $signature; // positional: file, params, format, s
		}

		return (string) $linker->link($destination, $args);
	}


	/**
	 * Dimensions of the thumbnail (for width/height attributes), null when they can't be determined.
	 */
	public function getDimensions(string $file, ?string $params = null): ?Dimensions
	{
		return $this->resizer->getOutputDimensions($file, $params);
	}


	/**
	 * Value of the srcset attribute: a link with the width descriptor for each params. The widths are the real
	 * ones of the thumbnails (e.g. ifresize on a small image), entries with an already used or unknown width
	 * are skipped.
	 * @param list<string> $paramsList
	 */
	public function srcset(
		Component|LinkGenerator $linker,
		string $file,
		array $paramsList,
		?string $format = null,
		bool $absolute = false,
	): string {
		$entries = [];

		foreach ($paramsList as $params) {
			$width = $this->getDimensions($file, $params)?->getWidth();

			if ($width !== null && !isset($entries[$width])) {
				$entries[$width] = $this->link($linker, $file, $params, $format, $absolute) . ' ' . $width . 'w';
			}
		}

		return implode(', ', $entries);
	}


	/**
	 * HTML of a <picture> element: a <source> for each modern format (formatPriority order) the server
	 * supports and the upgrade of the source format is enabled for (WEBP/AVIF/JXL sources: their own format
	 * first), and an <img> in the original format (JPEG for WEBP/AVIF/JXL sources) with width and height.
	 * Works in static mode too, the format of each source is explicit.
	 * @param list<string> $paramsList the last one is used for the src of the <img>
	 * @param array<string, scalar|null> $attributes of the <img> ("sizes" goes to the sources too);
	 *     true = attribute without a value, false/null = omitted
	 */
	public function picture(
		Component|LinkGenerator $linker,
		string $file,
		array $paramsList,
		array $attributes = [],
		bool $absolute = false,
	): string {
		$sourceFormat = $this->outputFormat->getSourceFormat($file);
		$fallbackFormat = in_array($sourceFormat, Resizer::MODERN_FORMATS, true) ? Resizer::FORMAT_SUFFIX_JPG : $sourceFormat;
		$sizes = $attributes['sizes'] ?? null;
		$html = '<picture>';

		foreach ($this->getPictureFormats($sourceFormat) as $format) {
			$srcset = $this->srcset($linker, $file, $paramsList, $format, $absolute);

			if ($srcset !== '') {
				$html .= '<source' . self::formatAttributes([
					'type' => Resizer::MIME_TYPES[$format],
					'srcset' => $srcset,
					'sizes' => $sizes,
				]) . '>';
			}
		}

		$default = $paramsList === [] ? null : $paramsList[array_key_last($paramsList)];
		$dimensions = $this->getDimensions($file, $default);
		$srcset = $this->srcset($linker, $file, $paramsList, $fallbackFormat, $absolute);
		$attributes = $this->applyPlaceholder($attributes, $file, $default);

		// the given attributes override the defaults (except src and srcset), the order of the defaults is kept
		$html .= '<img' . self::formatAttributes(array_replace([
			'src' => $this->link($linker, $file, $default, $fallbackFormat, $absolute),
			'srcset' => $srcset === '' ? null : $srcset,
			'width' => $dimensions?->getWidth(),
			'height' => $dimensions?->getHeight(),
			'alt' => '',
		], array_diff_key($attributes, ['src' => true, 'srcset' => true]))) . '>';

		return $html . '</picture>';
	}


	/**
	 * Inline style of a placeholder shown while the thumbnail loads, an empty string when there is none
	 * (transparent image, not generated yet in this request because of the budget, no PlaceholderGenerator).
	 * @param string $mode Placeholder::COLOR or Placeholder::BLUR
	 */
	public function getPlaceholderStyle(string $file, ?string $params = null, string $mode = Placeholder::COLOR): string
	{
		if (!in_array($mode, Placeholder::MODES, true)) {
			throw new InvalidArgumentException(sprintf("Placeholder mode must be '%s'.", implode("' or '", Placeholder::MODES)));
		}

		return $this->placeholders?->get($file, $params)?->getStyle($mode) ?? '';
	}


	/**
	 * The "placeholder" attribute of picture(): 'color', 'blur' or true (= color) - turned into a style.
	 * @param array<string, scalar|null> $attributes
	 * @return array<string, scalar|null>
	 */
	private function applyPlaceholder(array $attributes, string $file, ?string $params): array
	{
		$mode = $attributes['placeholder'] ?? null;
		unset($attributes['placeholder']);

		if ($mode === null || $mode === false) {
			return $attributes;
		}

		if ($mode === true) {
			$mode = Placeholder::COLOR;
		}

		if (!is_string($mode) || !in_array($mode, Placeholder::MODES, true)) {
			throw new InvalidArgumentException(sprintf("Placeholder must be '%s' or true.", implode("', '", Placeholder::MODES)));
		}

		$style = $this->getPlaceholderStyle($file, $params, $mode);

		if ($style !== '') {
			$existing = $attributes['style'] ?? null;
			$attributes['style'] = $style . (is_string($existing) && $existing !== '' ? ';' . $existing : '');
		}

		return $attributes;
	}


	/**
	 * Modern formats for <source> elements of a <picture>, in the order of preference.
	 * @return list<string>
	 */
	private function getPictureFormats(string $sourceFormat): array
	{
		$formats = [];
		$isModernSource = in_array($sourceFormat, Resizer::MODERN_FORMATS, true);

		if ($isModernSource && $this->config->isFormatSupportedByServer($sourceFormat)) {
			$formats[] = $sourceFormat;
		}

		foreach ($this->config->getFormatPriority() as $format) {
			if (
				!in_array($format, $formats, true)
				&& $this->config->isFormatSupportedByServer($format)
				&& ($isModernSource || $this->config->canUpgrade($sourceFormat, $format))
			) {
				$formats[] = $format;
			}
		}

		return $formats;
	}


	/**
	 * @param array<string, scalar|null> $attributes
	 */
	private static function formatAttributes(array $attributes): string
	{
		$html = '';

		foreach ($attributes as $name => $value) {
			if ($value === null || $value === false) {
				continue;
			}

			$html .= ' ' . htmlspecialchars($name, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');

			if ($value !== true) {
				$html .= '="' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8') . '"';
			}
		}

		return $html;
	}


	/**
	 * Rewrites dynamic resizer URLs in HTML (e.g. rich text content with stored "/ir/..." links):
	 * an existing thumbnail gets its static URL, a missing one gets the format filled in,
	 * so the thumbnail it generates is found next time. With signing enabled (in static mode or not)
	 * the signature is added or replaced, so stored unsigned URLs keep working. Only root-relative URLs
	 * and absolute URLs of the current host in attribute values are rewritten; URLs with invalid params
	 * or other query parameters are left as they are.
	 * @param string $routePrefix path of the resizer route without the base path
	 * @param bool $fillFormat add the format to URLs of missing thumbnails (off for edited content, so it stays as stored)
	 */
	public function rewriteHtml(
		string $html,
		string $routePrefix = ResizerParamsParser::PATTERN_IR,
		bool $fillFormat = true,
	): string {
		if ((!$this->isStatic() && !$this->isSigned()) || !str_contains($html, $routePrefix)) {
			return $html;
		}

		$url = $this->httpRequest->getUrl();
		$basePath = rtrim($url->getBasePath(), '/');
		$baseUrl = rtrim($url->getBaseUrl(), '/');

		// attribute values only: after a quote or "=", or after a comma in srcset
		$pattern = '~(?<=["\'=,]|, )'
			. '(?<url>(?<base>' . preg_quote($baseUrl, '~') . '|' . preg_quote($basePath, '~') . ')/' . preg_quote($routePrefix, '~')
			. '(?<params>[^/"\'\s?#<>]+)/(?<file>[^"\'\s?#<>,)]+))'
			. '(?:\?format=(?<format>[a-z]+))?'
			. '(?:(?:\?|&amp;|&)' . UrlSigner::PARAMETER . '=(?<signature>[A-Za-z0-9_-]+))?'
			. '(?=["\'\s,)<>]|$)~i';

		return (string) preg_replace_callback($pattern, function (array $m) use ($fillFormat): string {
			$decode = static fn(string $value): string => rawurldecode(html_entity_decode($value, ENT_QUOTES | ENT_HTML5));
			$file = $decode($m['file']);
			$params = $decode($m['params']);
			$format = ($m['format'] ?? '') !== '' ? strtolower($m['format']) : null;
			$signature = ($m['signature'] ?? '') !== '' ? $m['signature'] : null;

			if (!preg_match(ResizerParamsParser::PATTERN, $params)) {
				return $m[0];
			}

			$staticPath = $this->getStaticPath($file, $params, $format);

			if ($staticPath !== null) {
				return $m['base'] . '/' . $staticPath;
			}

			$newFormat = $format === null && $fillFormat ? $this->resolveFormat($file) : $format;
			$newSignature = $this->urlSigner->sign($file, $params) ?? $signature;

			if ($newFormat === $format && $newSignature === $signature) {
				return $m[0];
			}

			return $m['url'] . $this->buildQuery($newFormat, $newSignature);
		}, $html);
	}


	/**
	 * Reverse of rewriteHtml(): turns static thumbnail URLs back into dynamic resizer URLs
	 * ("/cache/ir/<file>/<params>.<format>" -> "/ir/<params>/<file>"). Use it before storing
	 * edited HTML, so the content does not depend on the public cache (it can be purged).
	 * Only root-relative URLs and absolute URLs of the current host in attribute values are rewritten.
	 * @param string $routePrefix path of the resizer route without the base path
	 */
	public function restoreDynamicUrls(string $html, string $routePrefix = ResizerParamsParser::PATTERN_IR): string
	{
		$urlPath = $this->config->getPublicCacheUrlPath();

		if ($urlPath === null || !str_contains($html, $urlPath)) {
			return $html;
		}

		$url = $this->httpRequest->getUrl();
		$basePath = rtrim($url->getBasePath(), '/');
		$baseUrl = rtrim($url->getBaseUrl(), '/');

		// attribute values only: after a quote or "=", or after a comma in srcset
		$pattern = '~(?<=["\'=,]|, )'
			. '(?<base>' . preg_quote($baseUrl, '~') . '|' . preg_quote($basePath, '~') . ')/' . preg_quote($urlPath, '~')
			. '(?<file>[^"\'\s?#<>]+)/(?<params>[^/"\'\s?#<>]+)\.(?<format>[a-z]+)'
			. '(?=["\'\s,)<>]|$)~i';

		return (string) preg_replace_callback($pattern, function (array $m) use ($routePrefix): string {
			$params = rawurldecode($m['params']);

			if (!preg_match(ResizerParamsParser::PATTERN, $params)) {
				return $m[0];
			}

			$signature = $this->urlSigner->sign(rawurldecode($m['file']), $params);

			return $m['base'] . '/' . $routePrefix . $params . '/' . $m['file'] . $this->buildQuery(null, $signature);
		}, $html);
	}


	/**
	 * Query string of a dynamic URL in HTML (the separator is escaped, it is used in attribute values).
	 */
	private function buildQuery(?string $format, ?string $signature): string
	{
		$query = [];

		if ($format !== null) {
			$query[] = 'format=' . rawurlencode($format);
		}

		if ($signature !== null) {
			$query[] = UrlSigner::PARAMETER . '=' . $signature;
		}

		return $query === [] ? '' : '?' . implode('&amp;', $query);
	}


	/**
	 * Output format to use in a dynamic (/ir/) link. In static mode the format is resolved
	 * independently of the browser, so the generated thumbnail has the same name the static
	 * lookup expects. Otherwise the given format is returned as is.
	 */
	public function resolveFormat(string $file, ?string $format = null): ?string
	{
		if (!$this->isStatic()) {
			return $format;
		}

		try {
			return $this->outputFormat->getStaticOutputFormat($file, $format);
		} catch (Throwable) {
			return $format;
		}
	}


	/**
	 * URL of an already generated thumbnail in the public cache directory,
	 * or null when static mode is off or the thumbnail does not exist yet.
	 */
	public function getStaticUrl(
		string $file,
		?string $params = null,
		?string $format = null,
		bool $absolute = false,
	): ?string {
		$publicPath = $this->getStaticPath($file, $params, $format);

		if ($publicPath === null) {
			return null;
		}

		$url = $this->httpRequest->getUrl();
		return ($absolute ? $url->getBaseUrl() : $url->getBasePath()) . $publicPath;
	}


	/**
	 * Path of an already generated thumbnail relative to the web root (URL encoded),
	 * or null when static mode is off or the thumbnail does not exist yet.
	 */
	public function getStaticPath(
		string $file,
		?string $params = null,
		?string $format = null,
	): ?string {
		$urlPath = $this->config->getPublicCacheUrlPath();

		if ($urlPath === null) {
			return null;
		}

		$params ??= self::PARAMS_AUTO;

		// Invalid input falls back to the dynamic link, which reports the error
		if (
			str_contains($file, '..')
			|| str_contains($file, "\0")
			|| str_contains($file, '\\')
			|| !preg_match(ResizerParamsParser::PATTERN, $params)
		) {
			return null;
		}

		$file = ltrim($file, '/');

		if ($file === '') {
			return null;
		}

		try {
			$format = $this->outputFormat->getStaticOutputFormat($file, $format);
			$thumbnailPath = $this->resizer->getThumbnailPath($file, $params, $format);
		} catch (Throwable) {
			return null;
		}

		if (!$this->resizer->thumbnailExists($thumbnailPath)) {
			return null;
		}

		$segments = explode('/', $file);
		$segments[] = $params . '.' . $format;

		return $urlPath . implode('/', array_map(rawurlencode(...), $segments));
	}
}
