<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nette\Http\Helpers;
use Nette\Http\IResponse;

/**
 * HTTP response recording headers in memory - Nette\Http\Response reads them back
 * from headers_list(), which is always empty in CLI.
 */
final class RecordingHttpResponse implements IResponse
{
	private int $code = self::S200_OK;

	/** @var array<string, string> lowercase name => value */
	private array $headers = [];


	public function setCode(int $code, ?string $reason = null): static
	{
		$this->code = $code;
		return $this;
	}


	public function getCode(): int
	{
		return $this->code;
	}


	public function setHeader(string $name, ?string $value): static
	{
		if ($value === null) {
			unset($this->headers[strtolower($name)]);
		} else {
			$this->headers[strtolower($name)] = $value;
		}

		return $this;
	}


	public function addHeader(string $name, string $value): static
	{
		$key = strtolower($name);
		$this->headers[$key] = isset($this->headers[$key]) ? $this->headers[$key] . ', ' . $value : $value;
		return $this;
	}


	public function setContentType(string $type, ?string $charset = null): static
	{
		return $this->setHeader('Content-Type', $type . ($charset ? '; charset=' . $charset : ''));
	}


	public function redirect(string $url, int $code = self::S302_Found): void
	{
		$this->setCode($code);
		$this->setHeader('Location', $url);
	}


	public function setExpiration(?string $expire): static
	{
		if ($expire === null) {
			return $this->setHeader('Cache-Control', 's-maxage=0, max-age=0, must-revalidate')
				->setHeader('Expires', 'Mon, 23 Jan 1978 10:00:00 GMT');
		}

		$seconds = (int) Helpers::expirationToSeconds($expire);
		return $this->setHeader('Cache-Control', 'max-age=' . $seconds)
			->setHeader('Expires', Helpers::formatDate($seconds + time()));
	}


	public function isSent(): bool
	{
		return false;
	}


	public function getHeader(string $header): ?string
	{
		return $this->headers[strtolower($header)] ?? null;
	}


	/** @return array<string, string> */
	public function getHeaders(): array
	{
		return $this->headers;
	}


	public function setCookie(
		string $name,
		string $value,
		string|int|\DateTimeInterface|null $expire,
		?string $path = null,
		?string $domain = null,
		?bool $secure = null,
		?bool $httpOnly = null,
	): static {
		return $this;
	}


	public function deleteCookie(string $name, ?string $path = null, ?string $domain = null, ?bool $secure = null): void
	{
	}
}
