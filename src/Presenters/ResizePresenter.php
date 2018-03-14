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


	public function startup()
	{
		parent::startup();
		$this->httpResponse = $this->getHttpResponse();
		$this->httpResponse->setHeader('Pragma', '');
		$this->httpResponse->setHeader('Cache-Control', '');
	}


	/**
	 * @param  string $file
	 * @param  string $params
	 * @return void
	 */
	public function actionDefault($file, $params = null, $useAssets = false)
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


	/**
	 * @param  string $srcFile
	 * @param  string $dstFile
	 * @return string
	 */
	protected function getEtag($srcFile, $dstFile)
	{
		return filemtime($srcFile) . '-' . md5($dstFile);
	}


	/**
	 * @param  string $etag
	 * @return bool
	 */
	protected function matchEtag($etag)
	{
		return isset($_SERVER['HTTP_IF_NONE_MATCH']) && stripslashes($_SERVER['HTTP_IF_NONE_MATCH']) === $etag;
	}
}
