<?php
declare(strict_types=1);

namespace Nelson\Resizer\Presenters;

use Nelson\Resizer\IResizer;
use Nette\Application\Responses\FileResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\Context;
use Nette\Http\Request;
use Nette\Http\Response;
use Nette\Utils\DateTime;

final class ResizePresenter extends Presenter
{

	/**
	 * @inject
	 * @var IResizer
	 */
	public $resizer;

	/**
	 * @var Request
	 */
	private $request;

	/**
	 * @var Response
	 */
	private $response;


	public function startup(): void
	{
		parent::startup();
		$this->request = $this->getHttpRequest();
		$this->response = $this->getHttpResponse();

		// Get rid of troublemaking headers
		$this->response->setHeader('Pragma', null);
		$this->response->setHeader('Cache-Control', null);
	}


	public function actionDefault(string $file, string $params = null, bool $useAssets = false): void
	{
		$image = $this->resizer->send($file, $params, $useAssets);

		if (!$image['imageExists']) {
			$this->error('Image does not exist.');
		}

		$context = new Context($this->request, $this->response);
		$etag = $this->getEtag($image['imageInputFilePath'], $image['imageOutputFilePath']);

		if (!$context->isModified(null, $etag)) {
			$this->response->setCode(Response::S304_NOT_MODIFIED);
			$this->sendResponse(new TextResponse(''));
		} else {
			$fileResponse = new FileResponse(
				$image['imageOutputFilePath'],
				$image['name'],
				$this->getMimeType($image['imageOutputFilePath']),
				false
			);

			$now = new DateTime;
			$this->response->setExpiration($now->modify('+1 YEAR'));
			$this->sendResponse($fileResponse);
		}
	}


	private function getMimeType($filePath): string
	{
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		return finfo_file($finfo, $filePath);
	}


	private function getEtag(string $srcFile, string $dstFile): string
	{
		return filemtime($srcFile) . '-' . md5($dstFile);
	}
}
