<?php
declare(strict_types=1);

namespace Nelson\Resizer\Responses;

use Nette\Application\Response;
use Nette\Http\IRequest;
use Nette\Http\IResponse;

/**
 * The web server sends the file (X-Sendfile / X-Accel-Redirect), PHP only sends the headers.
 * Cache headers set before (Cache-Control, Expires, ETag, Last-Modified) are kept by the web server.
 */
final class SendfileResponse implements Response
{
	/**
	 * @param string $header X-Sendfile or X-Accel-Redirect
	 * @param string $value absolute path (X-Sendfile) or internal URI (X-Accel-Redirect)
	 */
	public function __construct(
		private readonly string $header,
		private readonly string $value,
		private readonly string $contentType,
		private readonly string $name,
	) {
	}


	public function getHeader(): string
	{
		return $this->header;
	}


	public function getValue(): string
	{
		return $this->value;
	}


	public function getContentType(): string
	{
		return $this->contentType;
	}


	public function getName(): string
	{
		return $this->name;
	}


	public function send(IRequest $httpRequest, IResponse $httpResponse): void
	{
		$httpResponse->setContentType($this->contentType);
		$httpResponse->setHeader(
			'Content-Disposition',
			'inline; filename="' . str_replace(['"', '\\', "\r", "\n"], '', $this->name) . '"'
				. "; filename*=utf-8''" . rawurlencode($this->name),
		);
		$httpResponse->setHeader($this->header, $this->value);
	}
}
