<?php
declare(strict_types=1);

namespace Nelson\Resizer\Presenters;

use Nelson\Resizer\Access\AccessGuard;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\ResizerException;
use Nelson\Resizer\IResizer;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nelson\Resizer\Responses\SendfileResponse;
use Nelson\Resizer\UrlSigner;
use Nette\Application\Response;
use Nette\Application\Responses\FileResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\Context;
use Nette\Http\IResponse;
use Nette\InvalidStateException;

final class ResizePresenter extends Presenter
{
	public function __construct(
		private readonly IResizer $resizer,
		private readonly UrlSigner $urlSigner,
		private readonly ResizerConfig $config,
		private readonly ?AccessGuard $accessGuard = null,
	) {
		parent::__construct();
	}


	public function startup(): void
	{
		parent::startup();

		// image responses do not need the session, release its lock as soon as possible;
		// access policies may need it (the logged-in user), it is released after their decision
		if ($this->accessGuard?->hasPolicies() !== true) {
			$this->releaseSession();
		}
	}


	public function actionDefault(
		string $file,
		?string $params = null,
		?string $format = null,
		?string $s = null, // signature, see UrlSigner::PARAMETER
	): void {
		if (!$this->urlSigner->verify($file, $params, $s)) {
			$this->error('Invalid signature.', IResponse::S403_Forbidden);
		}

		$access = $this->accessGuard?->decide($file);
		$this->releaseSession();

		if ($access?->isDenied() === true) {
			$this->error($access->code === IResponse::S404_NotFound ? 'Image not found.' : 'Access denied.', $access->code);
		}

		// the replacement is resized with the same params, its signature is not needed
		$servedFile = $access?->replacement ?? $file;

		try {
			$image = $this->resizer->process($servedFile, $params, $format);
		} catch (ImageNotFoundOrReadableException) {
			$this->error('Image not found.');
		} catch (ResizerException) {
			// invalid params, path, format or dimensions; details are not disclosed
			$this->error('Invalid image request.', IResponse::S400_BadRequest);
		}

		$httpRequest = $this->getHttpRequest();
		$httpResponse = $this->getHttpResponse();

		// without an explicit format the output depends on the Accept header (WEBP/AVIF negotiation),
		// shared caches (CDN, proxies) must not serve one variant to all browsers
		// (added, not set - Presenter::run() has already sent "Vary: X-Requested-With")
		if ($format === null) {
			$httpResponse->addHeader('Vary', 'Accept');
		}

		if ($access === null) {
			$httpResponse->setExpiration('1 year');
		} else {
			// the response depends on the user: never stored by shared caches (CDN, proxies);
			// a replacement is revalidated every time, so the real image is shown right after a login
			$maxAge = $access->isAllowed() ? $this->config->getProtectedMaxAge() : 0;
			$httpResponse->setHeader('Cache-Control', $maxAge > 0 ? 'private, max-age=' . $maxAge : 'private, no-cache');
			$httpResponse->setHeader('Expires', null);
			$httpResponse->setHeader('Pragma', null);
		}

		$lastModified = (int) filemtime($image);
		$etag = md5($image . '|' . $lastModified . '|' . filesize($image));

		if (!(new Context($httpRequest, $httpResponse))->isModified($lastModified, $etag)) {
			// isModified() has already set the 304 status code
			$this->sendResponse(new TextResponse(''));
		}

		$name = pathinfo($servedFile, PATHINFO_FILENAME) . '.' . pathinfo($image, PATHINFO_EXTENSION);
		$this->sendResponse($this->createFileResponse($image, $name, $this->getMimeType($image)));
	}


	private function releaseSession(): void
	{
		try {
			$session = $this->getSession();
		} catch (InvalidStateException) {
			return; // the application does not use sessions
		}

		if ($session->isStarted()) {
			$session->close();
		}
	}


	/**
	 * The web server sends the file when sendfile is configured, otherwise PHP.
	 */
	private function createFileResponse(string $image, string $name, string $contentType): Response
	{
		$cacheDir = $this->config->getCacheDir();
		$internalUri = $this->config->getSendfileInternalUri();

		return match (true) {
			$this->config->getSendfile() === ResizerConfig::SENDFILE_X_SENDFILE => new SendfileResponse(
				'X-Sendfile',
				realpath($image) ?: $image, // without "..", mod_xsendfile checks the path against XSendFilePath
				$contentType,
				$name,
			),
			$this->config->getSendfile() === ResizerConfig::SENDFILE_X_ACCEL_REDIRECT
			&& $internalUri !== null
			&& str_starts_with($image, $cacheDir) => new SendfileResponse(
				'X-Accel-Redirect',
				$internalUri . implode('/', array_map(rawurlencode(...), explode('/', substr($image, strlen($cacheDir))))),
				$contentType,
				$name,
			),
			default => new FileResponse($image, $name, $contentType, forceDownload: false),
		};
	}


	/**
	 * By the extension of the thumbnail (it is always written in the format of its extension),
	 * libmagic may not know newer formats like JPEG XL.
	 */
	private function getMimeType(string $filePath): string
	{
		$mime = Resizer::MIME_TYPES[strtolower(pathinfo($filePath, PATHINFO_EXTENSION))] ?? mime_content_type($filePath);
		return $mime === false ? 'application/octet-stream' : $mime;
	}
}
