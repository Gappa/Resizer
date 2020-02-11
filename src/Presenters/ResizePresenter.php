<?php
declare(strict_types=1);

namespace Nelson\Resizer\Presenters;

use Exception;
use Nelson\Resizer\IResizer;
use Nette\Application\Responses\FileResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\Context;
use Nette\Http\IRequest;
use Nette\Http\IResponse;
use Nette\Http\Response;
use Nette\Utils\DateTime;

final class ResizePresenter extends Presenter
{

	/** @var IResizer */
	private $resizer;

	/** @var IRequest */
	private $request;

	/** @var IResponse */
	private $response;


	public function __construct(IResizer $resizer)
	{
		parent::__construct();
		$this->resizer = $resizer;
	}


	public function startup(): void
	{
		parent::startup();
		$this->request = $this->getHttpRequest();
		$this->response = $this->getHttpResponse();

		// Get rid of troublemaking headers
		$this->response->setHeader('Pragma', '');
		$this->response->setHeader('Cache-Control', '');
	}


	public function actionDefault(
		string $file,
		?string $params = null,
		bool $useAssets = false,
		?string $format = null
	): void {
		try {
			$image = $this->resizer->process($file, $params, $useAssets, $format);
		} catch (Exception $e) {
			$this->error($e->getMessage());
		}

		$context = new Context($this->request, $this->response);
		$etag = $this->getEtag(
			$this->resizer->getSourceImagePath($file, $useAssets),
			$image);

		if (!$context->isModified(null, $etag)) {
			$this->response->setCode(Response::S304_NOT_MODIFIED);
			$this->sendResponse(new TextResponse(''));
		} else {
			$fileResponse = new FileResponse(
				$image,
				pathinfo($file, PATHINFO_BASENAME),
				$this->getMimeType($image),
				false
			);

			$now = new DateTime;

			$this->response->setExpiration($now->modify('+1 YEAR')->format('Y-m-d H:i:s'));
			$this->sendResponse($fileResponse);
		}
	}


	private function getMimeType(string $filePath): ?string
	{
		$mime = mime_content_type($filePath);

		if ($mime === false) {
			return null;
		}

		return $mime;
	}


	private function getEtag(string $srcFile, string $dstFile): string
	{
		return filemtime($srcFile) . '-' . md5($dstFile);
	}
}
