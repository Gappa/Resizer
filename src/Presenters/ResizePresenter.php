<?php

namespace Nelson\Resizer\Presenters;

use Nelson\Resizer\IResizer;
use Nette\Application\Responses\FileResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Presenter;
use Nette\Http\Response;

class ResizePresenter extends Presenter
{

	/**
	 * @inject
	 * @var IResizer
	 */
	public $resizer;

	/**
	 * @var Response
	 */
	protected $httpResponse;


	public function startup(): void
	{
		parent::startup();
		$this->httpResponse = $this->getHttpResponse();
		$this->httpResponse->setHeader('Pragma', '');
		$this->httpResponse->setHeader('Cache-Control', '');
	}


	public function actionDefault(string $file, string $params = null, bool $useAssets = false): void
	{
		$image = $this->resizer->send($file, $params, $useAssets);

		if ($image['imageExists']) {
			$etag = $this->getEtag($image['imageInputFilePath'], $image['imageOutputFilePath']);
			$this->httpResponse->setHeader('Etag', $etag);

			if ($this->matchEtag($etag)) {
				$this->httpResponse->setHeader('Content-Length', 0);
				$this->httpResponse->setHeader('Expires', '');
				$this->httpResponse->setHeader('Last-Modified', '');
				$this->httpResponse->setCode(Response::S304_NOT_MODIFIED);

				//send empty response
				$this->sendResponse(new TextResponse(''));
			} else {
				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mime = finfo_file($finfo, $image['imageOutputFilePath']);

				$fileResponse = new FileResponse(
					$image['imageOutputFilePath'],
					$image['name'],
					$mime,
					false
				);

				$this->httpResponse->setHeader('Last-Modified', gmdate('D, j M Y H:i:s T', filemtime($image['imageOutputFilePath'])));
				$this->httpResponse->setHeader('Expires', gmdate('D, j M Y H:i:s T', time() + 29030400));

				// send
				$this->sendResponse($fileResponse);
			}
		} else {
			$this->error('Image does not exist.');
		}
	}


	protected function getEtag(string $srcFile, string $dstFile): string
	{
		return filemtime($srcFile) . '-' . md5($dstFile);
	}


	protected function matchEtag(string $etag): bool
	{
		return isset($_SERVER['HTTP_IF_NONE_MATCH']) && stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) === $etag;
	}
}
