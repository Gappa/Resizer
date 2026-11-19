<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\Access\AccessDecision;
use Nelson\Resizer\Access\AccessGuard;
use Nelson\Resizer\Access\AccessPolicy;
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
	private const string PUBLIC_DIR = __DIR__ . '/_public';
	private const string REPLACEMENT = '_public/locked.png';

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
		FileSystem::delete(self::PUBLIC_DIR);
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


	public function testPublicFileWithPolicies(): void
	{
		$policy = new FakeAccessPolicy('storage/', AccessDecision::deny());
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], policies: [$policy]);

		$this->assertInstanceOf(FileResponse::class, $response);
		$this->assertSame([], $policy->decided);
		$this->assertStringStartsWith('max-age=', (string) $this->httpResponse->getHeader('Cache-Control'));
	}


	public function testProtectedAllowed(): void
	{
		$policy = new FakeAccessPolicy('fixtures/', AccessDecision::allow());
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], policies: [$policy]);

		$this->assertInstanceOf(FileResponse::class, $response);
		$this->assertSame('test.png', $response->getName());
		$this->assertSame([self::IMAGE], $policy->decided);
		// never stored by shared caches, revalidated by browsers (a logout takes effect)
		$this->assertSame('private, no-cache', $this->httpResponse->getHeader('Cache-Control'));
		$this->assertNull($this->httpResponse->getHeader('Expires'));
		$this->assertNotNull($this->httpResponse->getHeader('ETag'));
	}


	public function testProtectedMaxAge(): void
	{
		$policy = new FakeAccessPolicy('fixtures/', AccessDecision::allow());
		$this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], policies: [$policy], protectedMaxAge: 600);

		$this->assertSame('private, max-age=600', $this->httpResponse->getHeader('Cache-Control'));
	}


	public function testProtectedNotModified(): void
	{
		$policies = [new FakeAccessPolicy('fixtures/', AccessDecision::allow())];
		$this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], policies: $policies);
		$etag = (string) $this->httpResponse->getHeader('ETag');

		$this->httpResponse = new RecordingHttpResponse;
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'x50'], headers: ['If-None-Match' => $etag], policies: $policies);

		$this->assertInstanceOf(TextResponse::class, $response);
		$this->assertSame(IResponse::S304_NotModified, $this->httpResponse->getCode());
	}


	public function testProtectedDenied(): void
	{
		$policy = new FakeAccessPolicy('fixtures/', AccessDecision::deny());
		$this->assertError(IResponse::S404_NotFound, 'Image not found.', ['file' => self::IMAGE, 'params' => 'x50'], policies: [$policy]);
		// nothing is generated for a denied request
		$this->assertDirectoryDoesNotExist(self::TEMP_DIR . '/resizer/fixtures/test.png');
	}


	public function testProtectedDeniedWith403(): void
	{
		$policy = new FakeAccessPolicy('fixtures/', AccessDecision::deny(IResponse::S403_Forbidden));
		$this->assertError(IResponse::S403_Forbidden, 'Access denied.', ['file' => self::IMAGE, 'params' => 'x50'], policies: [$policy]);
	}


	public function testProtectedDeniedWithNotModified(): void
	{
		// a cached ETag does not bypass the decision
		$this->runPresenter(['file' => self::IMAGE, 'params' => 'x50']);
		$etag = (string) $this->httpResponse->getHeader('ETag');

		$this->httpResponse = new RecordingHttpResponse;
		$this->expectException(BadRequestException::class);
		$this->runPresenter(
			['file' => self::IMAGE, 'params' => 'x50'],
			headers: ['If-None-Match' => $etag],
			policies: [new FakeAccessPolicy('fixtures/', AccessDecision::deny())],
		);
	}


	public function testProtectedReplaced(): void
	{
		$image = imagecreatetruecolor(300, 300);
		self::assertNotFalse($image);
		FileSystem::createDir(self::PUBLIC_DIR);
		imagepng($image, self::PUBLIC_DIR . '/locked.png');

		$policy = new FakeAccessPolicy('fixtures/', AccessDecision::replace(self::REPLACEMENT));
		$response = $this->runPresenter(['file' => self::IMAGE, 'params' => 'c40xc20'], policies: [$policy]);

		$this->assertInstanceOf(FileResponse::class, $response);
		$this->assertSame('locked.png', $response->getName());
		// resized with the same params, the layout is kept
		$this->assertSame([40, 20], array_slice((array) getimagesize($response->getFile()), 0, 2));
		$this->assertStringContainsString('/_public/locked.png/', strtr($response->getFile(), '\\', '/'));
		// the real image is shown right after a login
		$this->assertSame('private, no-cache', $this->httpResponse->getHeader('Cache-Control'));
	}


	public function testMissingReplacementIs404(): void
	{
		$policy = new FakeAccessPolicy('fixtures/', AccessDecision::replace('_public/does-not-exist.png'));
		$this->assertError(IResponse::S404_NotFound, 'Image not found.', ['file' => self::IMAGE, 'params' => 'x50'], policies: [$policy]);
	}


	public function testProtectedInStaticMode(): void
	{
		$policy = new FakeAccessPolicy('fixtures/', AccessDecision::allow());
		$response = $this->runPresenter(
			['file' => self::IMAGE, 'params' => 'x50'],
			sendfile: 'x-accel-redirect',
			sendfileInternalUri: '/_resizer/',
			policies: [$policy],
			publicCacheDir: self::PUBLIC_DIR . '/ir',
		);

		// not in the public cache (the web server would serve it to anyone), so PHP sends it
		$this->assertInstanceOf(FileResponse::class, $response);
		$this->assertSame(
			FileSystem::normalizePath(self::TEMP_DIR . '/resizer/fixtures/test.png/x50.png'),
			FileSystem::normalizePath($response->getFile()),
		);
		$this->assertDirectoryDoesNotExist(self::PUBLIC_DIR . '/ir/fixtures');
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


	/**
	 * @param array<string, string> $params
	 * @param list<AccessPolicy> $policies
	 */
	private function assertError(int $code, string $message, array $params, ?string $secret = null, array $policies = []): void
	{
		try {
			$this->runPresenter($params, secret: $secret, policies: $policies);
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
	 * @param list<AccessPolicy> $policies
	 * @param int<0, max> $protectedMaxAge
	 */
	private function runPresenter(
		array $params,
		array $headers = [],
		bool $webp = false,
		?string $secret = null,
		?string $sendfile = null,
		?string $sendfileInternalUri = null,
		array $policies = [],
		int $protectedMaxAge = 0,
		?string $publicCacheDir = null,
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
		$config->protectedMaxAge = $protectedMaxAge;
		$config->publicCacheDir = $publicCacheDir;
		$resizerConfig = new ResizerConfig($config);
		$accessGuard = new AccessGuard($policies);

		$presenter = new ResizePresenter(
			new Resizer($resizerConfig, new OutputFormat($httpRequest, $resizerConfig), $accessGuard),
			new UrlSigner($resizerConfig),
			$resizerConfig,
			$accessGuard,
		);
		$presenter->injectPrimary($httpRequest, $this->httpResponse);
		$presenter->autoCanonicalize = false; // no router in the test

		return $presenter->run(new AppRequest('Resizer:Resize', 'GET', ['action' => 'default'] + $params));
	}
}
