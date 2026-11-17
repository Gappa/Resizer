<?php
declare(strict_types=1);

namespace Nelson\Resizer\Presenters;

use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\ResizerException;
use Nelson\Resizer\IResizer;
use Nelson\Resizer\UrlSigner;
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
	) {
		parent::__construct();
	}


	public function startup(): void
	{
		parent::startup();

		// image responses do not need the session, release its lock as soon as possible
		try {
			$session = $this->getSession();
		} catch (InvalidStateException) {
			return; // the application does not use sessions
		}

		if ($session->isStarted()) {
			$session->close();
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

		try {
			$image = $this->resizer->process($file, $params, $format);
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

		$httpResponse->setExpiration('1 year');

		$lastModified = (int) filemtime($image);
		$etag = md5($image . '|' . $lastModified . '|' . filesize($image));

		if (!(new Context($httpRequest, $httpResponse))->isModified($lastModified, $etag)) {
			// isModified() has already set the 304 status code
			$this->sendResponse(new TextResponse(''));
		}

		$extension = pathinfo($image, PATHINFO_EXTENSION);

		$this->sendResponse(new FileResponse(
			$image,
			pathinfo($file, PATHINFO_FILENAME) . '.' . $extension,
			$this->getMimeType($image),
			forceDownload: false,
		));
	}


	private function getMimeType(string $filePath): string
	{
		$mime = mime_content_type($filePath);
		return $mime === false ? 'application/octet-stream' : $mime;
	}
}
