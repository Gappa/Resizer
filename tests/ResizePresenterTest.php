<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\Presenters\ResizePresenter;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nelson\Resizer\Responses\SendfileResponse;
use Nelson\Resizer\UrlSigner;
use Nette\Application\BadRequestException;
use Nette\Application\Request as AppRequest;
use Nette\Application\Response;
use Nette\Application\Responses\FileResponse;
use Nette\Application\Responses\TextResponse;
use Nette\Http\IResponse;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResizePresenterTest extends TestCase
{
	private const string IMAGE = 'fixtures/test.png';
	private const string TEMP_DIR = __DIR__ . '/../temp';

	private RecordingHttpResponse $httpResponse;


	protected function setUp(): void
	{
		parent::setUp();
		$this->httpResponse = new RecordingHttpResponse;
	}


	protected function tearDown(): void
	{
		parent::tearDown();
		FileSystem::delete(self::TEMP_DIR . '/resizer');
	}


	public function testServesThumbnail(): void
	{
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'c100xc100']);

		$this->assertInstanceOf(FileResponse::class, $response);
		$this->assertSame(IResponse::S200_OK, $this->httpResponse->getCode());
		$this->assertSame('image/png', $response->getContentType());
		$this->assertSame('test.png', $response->getName());
		$this->assertSame([100, 100], array_slice((array) getimagesize($response->getFile()), 0, 2));

		$this->assertNotNull($this->httpResponse->getHeader('ETag'));
		$this->assertNotNull($this->httpResponse->getHeader('Last-Modified'));
		$this->assertStringStartsWith('max-age=', (string) $this->httpResponse->getHeader('Cache-Control'));
	}


	public function testNegotiatedFormatVariesByAccept(): void
	{
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], headers: ['Accept' => 'image/webp,*/*'], webp: true);

		$this->assertInstanceOf(FileResponse::class, $response);
		$this->assertSame('image/webp', $response->getContentType());
		// the file name follows the served format, not the source
		$this->assertSame('test.webp', $response->getName());
		$this->assertContains('Accept', $this->getVary());
		// added to the presenter's own Vary, not replacing it
		$this->assertContains('X-Requested-With', $this->getVary());
	}


	public function testExplicitFormatDoesNotVary(): void
	{
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50', 'format' => 'png'], headers: ['Accept' => 'image/webp'], webp: true);

		$this->assertInstanceOf(FileResponse::class, $response);
		$this->assertSame('image/png', $response->getContentType());
		$this->assertNotContains('Accept', $this->getVary());
	}


	public function testNotModified(): void
	{
		$this->runPresenter(['file' => self::IMAGE, 'params' => 'x50']);
		$etag = $this->httpResponse->getHeader('ETag');
		$this->assertNotNull($etag);

		$this->httpResponse = new RecordingHttpResponse;
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], headers: ['If-None-Match' => $etag]);

		$this->assertInstanceOf(TextResponse::class, $response);
		$this->assertSame(IResponse::S304_NotModified, $this->httpResponse->getCode());
	}


	public function testModifiedWithDifferentEtag(): void
	{
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], headers: ['If-None-Match' => '"something-else"']);

		$this->assertInstanceOf(FileResponse::class, $response);
		$this->assertSame(IResponse::S200_OK, $this->httpResponse->getCode());
	}


	public function testMissingImageIs404(): void
	{
		$this->assertError(IResponse::S404_NotFound, 'Image not found.', ['file' => 'does_not_exist.png', 'params' => 'x50']);
	}


	public function testUnsupportedSourceTypeIs404(): void
	{
		$this->assertError(IResponse::S404_NotFound, 'Image not found.', ['file' => 'phpunit.xml', 'params' => 'x50']);
	}


	/** @return iterable<string, array{array<string, string>}> */
	public static function provideInvalidRequests(): iterable
	{
		yield 'path traversal' => [['file' => '../composer.json', 'params' => 'x50']];
		yield 'invalid params' => [['file' => self::IMAGE, 'params' => 'nonsense']];
		yield 'params with a slash' => [['file' => self::IMAGE, 'params' => 'evil/dir']];
		yield 'too large' => [['file' => self::IMAGE, 'params' => '5000x']];
		yield 'crop and ifresize' => [['file' => self::IMAGE, 'params' => 'ifresize-c10xc10']];
		yield 'crop with one dimension' => [['file' => self::IMAGE, 'params' => 'cxc10']];
		yield 'unsupported format' => [['file' => self::IMAGE, 'params' => 'x50', 'format' => 'exe']];
	}


	/** @param array<string, string> $params */
	#[DataProvider('provideInvalidRequests')]
	public function testInvalidRequestIs400(array $params): void
	{
		$this->assertError(IResponse::S400_BadRequest, 'Invalid image request.', $params);
	}


	public function testValidSignature(): void
	{
		$signature = (string) $this->createSigner()->sign(self::IMAGE, 'x50');
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50', 's' => $signature], secret: UrlSignerTest::SECRET);

		$this->assertInstanceOf(FileResponse::class, $response);
	}


	public function testValidSignatureWithDefaultParams(): void
	{
		// signed without params (route default "auto")
		$signature = (string) $this->createSigner()->sign(self::IMAGE, null);
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'auto', 's' => $signature], secret: UrlSignerTest::SECRET);

		$this->assertInstanceOf(FileResponse::class, $response);
	}


	public function testFormatIsNotSigned(): void
	{
		$signature = (string) $this->createSigner()->sign(self::IMAGE, 'x50');
		$response = $this->runPresenter(
			['file' => self::IMAGE, 'params' => 'x50', 'format' => 'jpg', 's' => $signature],
			secret: UrlSignerTest::SECRET,
		);

		$this->assertInstanceOf(FileResponse::class, $response);
	}


	public function testMissingSignature(): void
	{
		$this->assertError(
			IResponse::S403_Forbidden,
			'Invalid signature.',
			['file' => self::IMAGE, 'params' => 'x50'],
			UrlSignerTest::SECRET,
		);
	}


	public function testSignatureOfDifferentParams(): void
	{
		$signature = (string) $this->createSigner()->sign(self::IMAGE, 'x50');

		$this->assertError(
			IResponse::S403_Forbidden,
			'Invalid signature.',
			['file' => self::IMAGE, 'params' => 'x51', 's' => $signature],
			UrlSignerTest::SECRET,
		);
		$this->assertDirectoryDoesNotExist(self::TEMP_DIR . '/resizer/fixtures/test.png');
	}


	public function testXSendfile(): void
	{
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], sendfile: 'x-sendfile');

		$this->assertInstanceOf(SendfileResponse::class, $response);
		$this->assertSame('X-Sendfile', $response->getHeader());
		// absolute path without ".." (tempDir in the test contains it)
		$this->assertSame(realpath(self::TEMP_DIR) . '/resizer/fixtures/test.png/x50.png', $response->getValue());
		$this->assertFileExists($response->getValue());

		$response->send(new Request(new UrlScript), $this->httpResponse);
		$this->assertSame('image/png', $this->httpResponse->getHeader('Content-Type'));
		$this->assertSame($response->getValue(), $this->httpResponse->getHeader('X-Sendfile'));
		$this->assertSame('inline; filename="test.png"; filename*=utf-8\'\'test.png', $this->httpResponse->getHeader('Content-Disposition'));
		// cache headers stay, the web server passes them on
		$this->assertNotNull($this->httpResponse->getHeader('ETag'));
		$this->assertNotNull($this->httpResponse->getHeader('Cache-Control'));
	}


	public function testXAccelRedirect(): void
	{
		$response = $this->runPresenter(
			['file' => self::IMAGE, 'params' => 'x50'],
			sendfile: 'x-accel-redirect',
			sendfileInternalUri: '/_resizer', // the trailing slash is added
		);

		$this->assertInstanceOf(SendfileResponse::class, $response);
		$this->assertSame('X-Accel-Redirect', $response->getHeader());
		$this->assertSame('/_resizer/fixtures/test.png/x50.png', $response->getValue());
	}


	public function testSendfileNotModified(): void
	{
		$this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], sendfile: 'x-sendfile');
		$etag = (string) $this->httpResponse->getHeader('ETag');

		$this->httpResponse = new RecordingHttpResponse;
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], headers: ['If-None-Match' => $etag], sendfile: 'x-sendfile');

		// 304 is answered by PHP, the web server is not involved
		$this->assertInstanceOf(TextResponse::class, $response);
		$this->assertSame(IResponse::S304_NotModified, $this->httpResponse->getCode());
	}


	/** @return iterable<string, array{string, string|null}> */
	public static function provideInvalidSendfileConfig(): iterable
	{
		yield 'unknown mode' => ['sendfile', null];
		yield 'x-accel-redirect without uri' => ['x-accel-redirect', null];
		yield 'x-accel-redirect with relative uri' => ['x-accel-redirect', '_resizer/'];
	}


	#[DataProvider('provideInvalidSendfileConfig')]
	public function testInvalidSendfileConfig(string $sendfile, ?string $internalUri): void
	{
		$this->expectException(\Exception::class);
		$this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], sendfile: $sendfile, sendfileInternalUri: $internalUri);
	}


	private function createSigner(): UrlSigner
	{
		$config = new ResizerConfigDTO;
		$config->tempDir = self::TEMP_DIR;
		$config->wwwDir = __DIR__;
		$config->library = 'Gd';
		$config->secret = UrlSignerTest::SECRET;

		return new UrlSigner(new ResizerConfig($config));
	}


	/** @param array<string, string> $params */
	private function assertError(int $code, string $message, array $params, ?string $secret = null): void
	{
		try {
			$this->runPresenter($params, secret: $secret);
			$this->fail('BadRequestException expected.');
		} catch (BadRequestException $e) {
			$this->assertSame($code, $e->getCode());
			// no paths or other details of the failure are disclosed
			$this->assertSame($message, $e->getMessage());
		}
	}


	/** @return list<string> */
	private function getVary(): array
	{
		return array_map(trim(...), explode(',', (string) $this->httpResponse->getHeader('Vary')));
	}


	/**
	 * @param array<string, string> $params
	 * @param array<string, string> $headers
	 */
	private function runPresenter(
		array $params,
		array $headers = [],
		bool $webp = false,
		?string $secret = null,
		?string $sendfile = null,
		?string $sendfileInternalUri = null,
	): Response {
		$httpRequest = new Request(
			new UrlScript('https://example.com/ir/x50/fixtures/test.png', '/index.php'),
			headers: $headers,
		);

		$config = new ResizerConfigDTO;
		$config->tempDir = self::TEMP_DIR;
		$config->wwwDir = __DIR__;
		$config->library = 'Gd';
		$config->isWebpSupportedByServer = $webp;
		$config->secret = $secret;
		$config->sendfile = $sendfile;
		$config->sendfileInternalUri = $sendfileInternalUri;
		$resizerConfig = new ResizerConfig($config);

		$presenter = new ResizePresenter(
			new Resizer($resizerConfig, new OutputFormat($httpRequest, $resizerConfig)),
			new UrlSigner($resizerConfig),
			$resizerConfig,
		);
		$presenter->injectPrimary($httpRequest, $this->httpResponse);
		$presenter->autoCanonicalize = false; // no router in the test

		return $presenter->run(new AppRequest('Resizer:Resize', 'GET', ['action' => 'default'] + $params));
	}
}
