<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Exception;
use Latte\Engine;
use Latte\Loaders\StringLoader;
use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\Latte\ResizerExtension;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nelson\Resizer\ResizerLinkGenerator;
use Nelson\Resizer\UrlSigner;
use Nette\Application\LinkGenerator;
use Nette\Application\PresenterFactory;
use Nette\Application\Routers\RouteList;
use Nette\Application\UI\Control;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;

class ResizerLinkGeneratorTest extends TestCase
{
	private const IMAGE = 'fixtures/test.png';
	private const PUBLIC_DIR = __DIR__ . '/_public/ir';


	protected function tearDown(): void
	{
		parent::tearDown();
		FileSystem::delete(__DIR__ . '/_public');
	}


	public function testPublicCacheDirOutsideWwwDir(): void
	{
		$this->expectException(Exception::class);
		$this->getConfig(__DIR__ . '/../temp/ir');
	}


	public function testPublicCacheUrlPath(): void
	{
		$config = $this->getConfig(self::PUBLIC_DIR);
		$this->assertTrue($config->isStatic());
		$this->assertSame('_public/ir/', $config->getPublicCacheUrlPath());
		$this->assertSame(FileSystem::normalizePath(self::PUBLIC_DIR) . '/', $config->getPublicCacheDir());
	}


	public function testDisabled(): void
	{
		[$generator, $resizer] = $this->create(null);
		$resizer->process(self::IMAGE, 'c100xc100');

		$this->assertFalse($generator->isStatic());
		$this->assertNull($generator->getStaticUrl(self::IMAGE, 'c100xc100'));
		$this->assertNull($generator->resolveFormat(self::IMAGE));
	}


	public function testStaticUrlAfterProcessing(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR, webp: true);

		$this->assertNull($generator->getStaticUrl(self::IMAGE, 'c100xc100'));

		// the browser does not accept WEBP, but the format resolved for the link is used
		$format = $generator->resolveFormat(self::IMAGE);
		$this->assertSame('webp', $format);

		$thumbnail = $resizer->process(self::IMAGE, 'c100xc100', $format);
		$this->assertSame(
			FileSystem::normalizePath(self::PUBLIC_DIR . '/fixtures/test.png/c100xc100.webp'),
			FileSystem::normalizePath($thumbnail),
		);

		$this->assertSame(
			'/web/_public/ir/fixtures/test.png/c100xc100.webp',
			$generator->getStaticUrl(self::IMAGE, 'c100xc100'),
		);

		$this->assertSame(
			'https://example.com/web/_public/ir/fixtures/test.png/c100xc100.webp',
			$generator->getStaticUrl(self::IMAGE, 'c100xc100', absolute: true),
		);

		// a different format has not been generated yet
		$this->assertNull($generator->getStaticUrl(self::IMAGE, 'c100xc100', 'png'));
	}


	public function testAutoParams(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR);
		$resizer->process(self::IMAGE, 'auto', 'png');

		$this->assertSame('/web/_public/ir/fixtures/test.png/auto.png', $generator->getStaticUrl(self::IMAGE));
	}


	public function testUrlEncoding(): void
	{
		[$generator] = $this->create(self::PUBLIC_DIR);

		$thumbnail = self::PUBLIC_DIR . '/dir with space/ž.png/x100.png';
		FileSystem::write($thumbnail, 'data');

		$this->assertSame(
			'/web/_public/ir/dir%20with%20space/%C5%BE.png/x100.png',
			$generator->getStaticUrl('/dir with space/ž.png', 'x100'),
		);
	}


	public function testInvalidInput(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR);
		$resizer->process(self::IMAGE, 'c100xc100', 'png');

		$this->assertNull($generator->getStaticUrl(self::IMAGE, 'nonsense'));
		$this->assertNull($generator->getStaticUrl('fixtures/../fixtures/test.png', 'c100xc100'));
		$this->assertNull($generator->getStaticUrl(self::IMAGE, 'c100xc100', 'exe'));
		$this->assertNull($generator->getStaticUrl('', 'c100xc100'));
	}


	public function testEmptyThumbnailIsIgnored(): void
	{
		[$generator] = $this->create(self::PUBLIC_DIR);
		FileSystem::write(self::PUBLIC_DIR . '/fixtures/test.png/c100xc100.png', '');

		$this->assertNull($generator->getStaticUrl(self::IMAGE, 'c100xc100'));
	}


	public function testLinkForPhpCode(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR, webp: true);

		$control = $this->createMock(Control::class);
		$control->expects($this->exactly(2))
			->method('link')
			->willReturnCallback(fn(string $destination, array $args): string => $destination . ' ' . json_encode($args, JSON_THROW_ON_ERROR));

		$this->assertSame(
			':Resizer:Resize: ["fixtures\/test.png","c100xc100","webp"]',
			$generator->link($control, self::IMAGE, 'c100xc100'),
		);
		$this->assertSame(
			'//:Resizer:Resize: ["fixtures\/test.png","c100xc100","png"]',
			$generator->link($control, self::IMAGE, 'c100xc100', 'png', absolute: true),
		);

		$resizer->process(self::IMAGE, 'c100xc100', 'webp');

		$this->assertSame(
			'/web/_public/ir/fixtures/test.png/c100xc100.webp',
			$generator->link($control, self::IMAGE, 'c100xc100'),
		);
	}


	public function testRewriteHtml(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR, webp: true);
		$resizer->process(self::IMAGE, '200x100!', 'webp');

		$html = '<p>Text /web/ir/x/y.png and /web/ir/300x/fixtures/test.png</p>'
			. '<img src="/web/ir/200x100!/fixtures/test.png" alt="">'
			. "<img src='https://example.com/web/ir/200x100!/fixtures/test.png'>"
			. '<img src="/web/ir/300x/fixtures/test.png">'
			. '<img src="/web/ir/300x/fixtures/test.png?format=png">'
			. '<img src="/web/ir/300x/fixtures/test.png?foo=1">'
			. '<img src="https://other.com/web/ir/200x100!/fixtures/test.png">'
			. '<img srcset="/web/ir/200x100!/fixtures/test.png 1x, /web/ir/400x/fixtures/test.png 2x">'
			. '<a href="/web/ir/200x100!/fixtures/test.png?format=webp">x</a>';

		$this->assertSame(
			'<p>Text /web/ir/x/y.png and /web/ir/300x/fixtures/test.png</p>'
			. '<img src="/web/_public/ir/fixtures/test.png/200x100%21.webp" alt="">'
			. "<img src='https://example.com/web/_public/ir/fixtures/test.png/200x100%21.webp'>"
			. '<img src="/web/ir/300x/fixtures/test.png?format=webp">'
			. '<img src="/web/ir/300x/fixtures/test.png?format=png">'
			. '<img src="/web/ir/300x/fixtures/test.png?foo=1">'
			. '<img src="https://other.com/web/ir/200x100!/fixtures/test.png">'
			. '<img srcset="/web/_public/ir/fixtures/test.png/200x100%21.webp 1x, /web/ir/400x/fixtures/test.png?format=webp 2x">'
			. '<a href="/web/_public/ir/fixtures/test.png/200x100%21.webp">x</a>',
			$generator->rewriteHtml($html),
		);
	}


	public function testRestoreDynamicUrls(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR, webp: true);
		$resizer->process(self::IMAGE, '200x100!', 'webp');

		$dynamic = '<img src="/web/ir/200x100!/fixtures/test.png">'
			. '<img srcset="/web/ir/200x100!/fixtures/test.png 1x, /web/ir/400x/fixtures/test.png?format=webp 2x">';

		// round trip: dynamic -> static -> dynamic
		$static = $generator->rewriteHtml($dynamic);
		$this->assertStringContainsString('/web/_public/ir/fixtures/test.png/200x100%21.webp', $static);
		$this->assertSame(
			'<img src="/web/ir/200x100!/fixtures/test.png">'
			. '<img srcset="/web/ir/200x100!/fixtures/test.png 1x, /web/ir/400x/fixtures/test.png?format=webp 2x">',
			$generator->restoreDynamicUrls($static),
		);

		$this->assertSame(
			'<a href="https://example.com/web/ir/c10xc10/dir%20x/a.png">'
			. '<img src="https://other.com/web/_public/ir/a.png/x10.png">'
			. '<p>Text /web/_public/ir/a.png/x10.png</p>'
			. '<img src="/web/_public/ir/a.png/nonsense.png">',
			$generator->restoreDynamicUrls(
				'<a href="https://example.com/web/_public/ir/dir%20x/a.png/c10xc10.webp">'
				. '<img src="https://other.com/web/_public/ir/a.png/x10.png">'
				. '<p>Text /web/_public/ir/a.png/x10.png</p>'
				. '<img src="/web/_public/ir/a.png/nonsense.png">',
			),
		);
	}


	public function testRewriteHtmlWithoutFillFormat(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR, webp: true);
		$resizer->process(self::IMAGE, 'x100', 'webp');

		$this->assertSame(
			'<img src="/web/_public/ir/fixtures/test.png/x100.webp"><img src="/web/ir/300x/fixtures/test.png">',
			$generator->rewriteHtml('<img src="/web/ir/x100/fixtures/test.png"><img src="/web/ir/300x/fixtures/test.png">', fillFormat: false),
		);
	}


	public function testRewriteHtmlWithoutStaticMode(): void
	{
		[$generator] = $this->create(null);
		$html = '<img src="/web/ir/300x/fixtures/test.png">';

		$this->assertSame($html, $generator->rewriteHtml($html));
	}


	public function testLatteStatic(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR, webp: true);
		$resizer->process(self::IMAGE, 'c100xc100', 'webp');

		$control = $this->createMock(Control::class);
		$control->expects($this->never())->method('link');

		$this->assertSame(
			'/web/_public/ir/fixtures/test.png/c100xc100.webp|https://example.com/web/_public/ir/fixtures/test.png/c100xc100.webp',
			$this->render($generator, $control, "{rlink 'fixtures/test.png', 'c100xc100'}|{rlinkabs 'fixtures/test.png', 'c100xc100'}"),
		);
	}


	public function testLatteDynamicFillsFormat(): void
	{
		[$generator] = $this->create(self::PUBLIC_DIR, webp: true);

		$control = $this->createMock(Control::class);
		$control->expects($this->exactly(3))
			->method('link')
			->willReturnCallback(fn(string $destination, array $args): string => $destination . ' ' . json_encode($args, JSON_THROW_ON_ERROR));

		$this->assertSame(
			':Resizer:Resize: ["fixtures\/test.png","c100xc100","webp"]'
			. '|:Resizer:Resize: ["fixtures\/test.png",null,"webp"]'
			. '|//:Resizer:Resize: ["fixtures\/test.png","c100xc100","png"]',
			$this->render(
				$generator,
				$control,
				"{rlink 'fixtures/test.png', 'c100xc100'}|{rlink 'fixtures/test.png'}|{rlinkabs 'fixtures/test.png', 'c100xc100', 'png'}",
			),
		);
	}


	public function testLatteWithLinkGenerator(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR, webp: true);
		$resizer->process(self::IMAGE, 'c100xc100', 'webp');

		$router = new RouteList;
		$router->withModule('Resizer')->addRoute('ir/<params=auto>/<file .+>', ['presenter' => 'Resize', 'action' => 'default']);
		$presenterFactory = new PresenterFactory;
		$presenterFactory->setMapping(['Resizer' => 'Nelson\Resizer\Presenters\*Presenter']);
		$linkGenerator = new LinkGenerator($router, new UrlScript('https://example.com/web/index.php', '/web/index.php'), $presenterFactory);

		$latte = new Engine;
		$latte->setLoader(new StringLoader);
		$latte->addExtension(new ResizerExtension($generator));
		$latte->addProvider('uiControl', $linkGenerator);

		// e-mails: always absolute, static when the thumbnail exists
		$this->assertSame(
			'https://example.com/web/_public/ir/fixtures/test.png/c100xc100.webp'
			. '|https://example.com/web/ir/300x/fixtures/test.png?format=webp',
			$latte->renderToString("{rlink 'fixtures/test.png', 'c100xc100'}|{rlink 'fixtures/test.png', '300x'}"),
		);
	}


	public function testLatteWithoutStaticMode(): void
	{
		[$generator] = $this->create(null, webp: true);

		$control = $this->createMock(Control::class);
		$control->expects($this->once())
			->method('link')
			->with(':Resizer:Resize:', ['fixtures/test.png', 'c100xc100'])
			->willReturn('/ir/c100xc100/fixtures/test.png');

		$this->assertSame(
			'/ir/c100xc100/fixtures/test.png',
			$this->render($generator, $control, "{rlink 'fixtures/test.png', 'c100xc100'}"),
		);
	}


	public function testSignedLinkForPhpCode(): void
	{
		[$generator] = $this->create(null, secret: UrlSignerTest::SECRET);
		$signature = $this->sign(self::IMAGE, 'c100xc100');

		$control = $this->createMock(Control::class);
		$control->expects($this->once())
			->method('link')
			->with(':Resizer:Resize:', [self::IMAGE, 'c100xc100', null, $signature])
			->willReturn('/ir/c100xc100/fixtures/test.png?s=' . $signature);

		$this->assertTrue($generator->isSigned());
		$this->assertSame(
			'/ir/c100xc100/fixtures/test.png?s=' . $signature,
			$generator->link($control, self::IMAGE, 'c100xc100'),
		);
	}


	public function testSignedLatteWithoutStaticMode(): void
	{
		[$generator] = $this->create(null, secret: UrlSignerTest::SECRET);
		$signature = $this->sign(self::IMAGE, 'c100xc100');

		$control = $this->createMock(Control::class);
		$control->expects($this->once())
			->method('link')
			->with(':Resizer:Resize:', [self::IMAGE, 'c100xc100', null, $signature])
			->willReturn('/ir/c100xc100/fixtures/test.png?s=' . $signature);

		$this->assertSame(
			'/ir/c100xc100/fixtures/test.png?s=' . $signature,
			$this->render($generator, $control, "{rlink 'fixtures/test.png', 'c100xc100'}"),
		);
	}


	public function testSignedLatteWithLinkGenerator(): void
	{
		[$generator] = $this->create(null, secret: UrlSignerTest::SECRET);

		$router = new RouteList;
		$router->withModule('Resizer')->addRoute('ir/<params=auto>/<file .+>', ['presenter' => 'Resize', 'action' => 'default']);
		$presenterFactory = new PresenterFactory;
		$presenterFactory->setMapping(['Resizer' => 'Nelson\Resizer\Presenters\*Presenter']);
		$linkGenerator = new LinkGenerator($router, new UrlScript('https://example.com/web/index.php', '/web/index.php'), $presenterFactory);

		$latte = new Engine;
		$latte->setLoader(new StringLoader);
		$latte->addExtension(new ResizerExtension($generator));
		$latte->addProvider('uiControl', $linkGenerator);

		// real routing: the signature ends up in the query string
		$this->assertSame(
			'https://example.com/web/ir/300x/fixtures/test.png?s=' . $this->sign(self::IMAGE, '300x'),
			$latte->renderToString("{rlink 'fixtures/test.png', '300x'}"),
		);
	}


	public function testRewriteHtmlSignsUrlsWithoutStaticMode(): void
	{
		[$generator] = $this->create(null, secret: UrlSignerTest::SECRET);
		$sig300 = $this->sign(self::IMAGE, '300x');
		$sigAuto = $this->sign('a b.png', null);

		$this->assertSame(
			'<img src="/web/ir/300x/fixtures/test.png?s=' . $sig300 . '">'
			. '<img src="/web/ir/300x/fixtures/test.png?format=png&amp;s=' . $sig300 . '">'
			// a stale or forged signature is replaced
			. '<img src="/web/ir/300x/fixtures/test.png?s=' . $sig300 . '">'
			. '<img src="/web/ir/auto/a%20b.png?s=' . $sigAuto . '">'
			// other query parameters: left as they are
			. '<img src="/web/ir/300x/fixtures/test.png?foo=1">',
			$generator->rewriteHtml(
				'<img src="/web/ir/300x/fixtures/test.png">'
				. '<img src="/web/ir/300x/fixtures/test.png?format=png">'
				. '<img src="/web/ir/300x/fixtures/test.png?s=forged">'
				. '<img src="/web/ir/auto/a%20b.png">'
				. '<img src="/web/ir/300x/fixtures/test.png?foo=1">',
			),
		);
	}


	public function testRewriteHtmlSignedUrlIsStable(): void
	{
		[$generator] = $this->create(null, secret: UrlSignerTest::SECRET);
		$html = '<img src="/web/ir/300x/fixtures/test.png?format=png&amp;s=' . $this->sign(self::IMAGE, '300x') . '">';

		$this->assertSame($html, $generator->rewriteHtml($html));
	}


	public function testRewriteHtmlSignedStaticMode(): void
	{
		[$generator, $resizer] = $this->create(self::PUBLIC_DIR, webp: true, secret: UrlSignerTest::SECRET);
		$resizer->process(self::IMAGE, 'x100', 'webp');

		$this->assertSame(
			// existing thumbnail: static URL, the signature is dropped
			'<img src="/web/_public/ir/fixtures/test.png/x100.webp">'
			// missing one: format filled in and signed
			. '<img src="/web/ir/300x/fixtures/test.png?format=webp&amp;s=' . $this->sign(self::IMAGE, '300x') . '">',
			$generator->rewriteHtml(
				'<img src="/web/ir/x100/fixtures/test.png?s=' . $this->sign(self::IMAGE, 'x100') . '">'
				. '<img src="/web/ir/300x/fixtures/test.png">',
			),
		);
	}


	public function testRestoreDynamicUrlsSigned(): void
	{
		[$generator] = $this->create(self::PUBLIC_DIR, secret: UrlSignerTest::SECRET);

		$this->assertSame(
			'<img src="/web/ir/c10xc10/dir%20x/a.png?s=' . $this->sign('dir x/a.png', 'c10xc10') . '">',
			$generator->restoreDynamicUrls('<img src="/web/_public/ir/dir%20x/a.png/c10xc10.webp">'),
		);
	}


	private function sign(string $file, ?string $params): string
	{
		return (string) (new UrlSigner($this->getConfig(null, secret: UrlSignerTest::SECRET)))->sign($file, $params);
	}


	private function render(ResizerLinkGenerator $generator, Control $control, string $template): string
	{
		$latte = new Engine;
		$latte->setLoader(new StringLoader);
		$latte->addExtension(new ResizerExtension($generator));
		$latte->addProvider('uiControl', $control);

		return $latte->renderToString($template);
	}


	/** @return array{ResizerLinkGenerator, Resizer} */
	private function create(?string $publicCacheDir, bool $webp = false, ?string $secret = null): array
	{
		$config = $this->getConfig($publicCacheDir, $webp, $secret);
		$httpRequest = new Request(new UrlScript('https://example.com/web/index.php', '/web/index.php'));
		$outputFormat = new OutputFormat($httpRequest, $config);
		$resizer = new Resizer($config, $outputFormat);

		return [new ResizerLinkGenerator($config, $outputFormat, $resizer, $httpRequest, new UrlSigner($config)), $resizer];
	}


	private function getConfig(?string $publicCacheDir, bool $webp = false, ?string $secret = null): ResizerConfig
	{
		$config = new ResizerConfigDTO;
		$config->tempDir = __DIR__ . '/../temp';
		$config->wwwDir = __DIR__;
		$config->publicCacheDir = $publicCacheDir;
		$config->library = 'Gd';
		$config->isWebpSupportedByServer = $webp;
		$config->secret = $secret;

		return new ResizerConfig($config);
	}
}
