<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nelson\Resizer\DI\ResizerExtension;
use Nette\Application\LinkGenerator;
use Nette\Application\UI\Component;
use Nette\Http\IRequest;
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
