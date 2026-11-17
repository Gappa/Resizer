<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\Dimensions;
use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\Placeholder\Placeholder;
use Nelson\Resizer\Placeholder\PlaceholderGenerator;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PlaceholderGeneratorTest extends TestCase
{
	private const string PUBLIC_DIR = __DIR__ . '/_public';
	private const string CACHE = '/resizer-placeholder/';


	protected function setUp(): void
	{
		parent::setUp();

		// 200x100, solid rgb(200, 50, 50)
		$red = imagecreatetruecolor(200, 100);
		self::assertNotFalse($red);
		imagefill($red, 0, 0, (int) imagecolorallocate($red, 200, 50, 50));
		FileSystem::createDir(self::PUBLIC_DIR);
		imagejpeg($red, self::PUBLIC_DIR . '/red.jpg', 95);

		// 100x100, fully transparent
		$transparent = imagecreatetruecolor(100, 100);
		self::assertNotFalse($transparent);
		imagealphablending($transparent, false);
		imagesavealpha($transparent, true);
		imagefill($transparent, 0, 0, (int) imagecolorallocatealpha($transparent, 0, 0, 0, 127));
		imagepng($transparent, self::PUBLIC_DIR . '/transparent.png');
	}


	protected function tearDown(): void
	{
		parent::tearDown();
		FileSystem::delete(__DIR__ . '/../temp' . self::CACHE);
		FileSystem::delete(self::PUBLIC_DIR);
	}


	/** @return iterable<string, array{string|null, array{positive-int, positive-int}, string}> */
	public static function provideTinyParams(): iterable
	{
		yield 'width' => ['400x', [400, 200], '16x8-q40'];
		yield 'crop keeps the alignment' => ['l400xb300', [400, 300], 'l16xb12-q40'];
		yield 'forced' => ['100x50!', [100, 50], '16x8!-q40'];
		yield 'quality of the params is replaced' => ['400x-q90', [400, 200], '16x8-q40'];
		yield 'auto' => [null, [500, 500], '16x16-q40'];
		yield 'portrait' => ['x400', [200, 400], '8x16-q40'];
		yield 'already tiny' => ['10x', [10, 5], '10x5-q40'];
	}


	/** @param array{positive-int, positive-int} $output */
	#[DataProvider('provideTinyParams')]
	public function testTinyParams(?string $params, array $output, string $expected): void
	{
		$tiny = PlaceholderGenerator::getTinyParams($params, new Dimensions(...$output));
		$this->assertSame($expected, $tiny);
	}


	public function testPlaceholder(): void
	{
		$placeholder = $this->createGenerator()->get('_public/red.jpg', '100x');
		$this->assertNotNull($placeholder);

		// 100x50 output -> 16x8 tiny image
		$this->assertSame([16, 8], [$placeholder->size->getWidth(), $placeholder->size->getHeight()]);
		$this->assertFalse($placeholder->transparent);
		$this->assertStringStartsWith('data:image/png;base64,', $placeholder->imageDataUri);

		// the average color is close to rgb(200, 50, 50)
		$this->assertMatchesRegularExpression('~^#[0-9a-f]{6}$~', $placeholder->color);
		[$r, $g, $b] = array_map(hexdec(...), str_split(substr($placeholder->color, 1), 2));
		$this->assertEqualsWithDelta(200, $r, 15);
		$this->assertEqualsWithDelta(50, $g, 15);
		$this->assertEqualsWithDelta(50, $b, 15);

		$this->assertSame('background-color:' . $placeholder->color, $placeholder->getStyle(Placeholder::COLOR));

		$blur = $placeholder->getStyle(Placeholder::BLUR);
		$this->assertStringStartsWith('background:' . $placeholder->color . ' url("data:image/svg+xml;base64,', $blur);
		$this->assertStringEndsWith('") 0 0/100% 100% no-repeat', $blur);

		$svg = base64_decode(substr($placeholder->getBlurredImageDataUri(), strlen('data:image/svg+xml;base64,')), true);
		$this->assertIsString($svg);
		$this->assertStringContainsString('viewBox="0 0 16 8"', $svg);
		$this->assertStringContainsString('<feGaussianBlur', $svg);
		$this->assertStringContainsString('href="' . $placeholder->imageDataUri . '"', $svg);
	}


	public function testCrop(): void
	{
		$placeholder = $this->createGenerator()->get('_public/red.jpg', 'c40xc40');
		$this->assertSame([16, 16], [$placeholder?->size->getWidth(), $placeholder?->size->getHeight()]);
	}


	public function testTransparent(): void
	{
		$placeholder = $this->createGenerator()->get('_public/transparent.png', '50x');

		$this->assertNotNull($placeholder);
		$this->assertTrue($placeholder->transparent);
		// a background would show through the image
		$this->assertSame('', $placeholder->getStyle(Placeholder::COLOR));
		$this->assertSame('', $placeholder->getStyle(Placeholder::BLUR));
	}


	public function testBudget(): void
	{
		$generator = $this->createGenerator(budget: 1);

		$this->assertNotNull($generator->get('_public/red.jpg', '100x'));
		// a different tiny thumbnail (60x would give the same 16x8 as 100x)
		$this->assertNull($generator->get('_public/red.jpg', 'c60xc60'), 'over the budget');
		$this->assertNotNull($generator->warmUp('_public/red.jpg', 'c60xc60'), 'warmUp() has no budget');
		$this->assertNotNull($generator->get('_public/red.jpg', 'c60xc60'), 'generated by warmUp()');

		// already generated tiny thumbnails do not count
		$this->assertNotNull($this->createGenerator(budget: 0)->get('_public/red.jpg', '100x'));
	}


	public function testInvalid(): void
	{
		$generator = $this->createGenerator();

		$this->assertNull($generator->get('_public/does-not-exist.jpg', '100x'));
		$this->assertNull($generator->get('_public/red.jpg', 'nonsense'));
		$this->assertNull($generator->get('../composer.json', '100x'));
	}


	/** @param int<0, max> $budget */
	private function createGenerator(int $budget = 10): PlaceholderGenerator
	{
		$dto = new ResizerConfigDTO;
		$dto->tempDir = __DIR__ . '/../temp';
		$dto->wwwDir = __DIR__;
		$dto->library = 'Gd';
		$dto->cache = self::CACHE;
		$dto->placeholderBudget = $budget;
		$config = new ResizerConfig($dto);

		return new PlaceholderGenerator(new Resizer($config, new OutputFormat(new Request(new UrlScript), $config)), $config);
	}
}
