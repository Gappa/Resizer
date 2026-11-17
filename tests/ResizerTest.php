<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Imagick;
use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nelson\Resizer\Exceptions\DimensionsTooLargeException;
use Nelson\Resizer\Exceptions\ImageNotFoundOrReadableException;
use Nelson\Resizer\Exceptions\SecurityException;
use Nelson\Resizer\Exceptions\UnsupportedFormatException;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ResizerTest extends TestCase
{
	private const string PUBLIC_DIR = __DIR__ . '/_public';

	private static Resizer $resizer;
	private static string $image;
	private static ResizerConfig $config;


	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();

		$config = new ResizerConfigDTO;
		$config->tempDir = __DIR__ . '/../temp';
		$config->wwwDir = __DIR__ . '/../tests';
		$config->qualityJpeg = 65;
		$config->qualityAvif = 65;
		$config->qualityWebp = 65;
		$config->compressionPng = 9;
		$config->library = 'Gd';

		$httpRequest = new Request(new UrlScript);
		self::$config = new ResizerConfig($config);

		$outputFormat = new OutputFormat($httpRequest, self::$config);

		self::$resizer = new Resizer(self::$config, $outputFormat);
		self::$image = 'fixtures/test.png';
	}


	public function testImageNotFound(): void
	{
		$this->expectException(ImageNotFoundOrReadableException::class);
		self::$resizer->getSourceImagePath('does_not_exist.jpg');
	}


	public function testSecurityException(): void
	{
		$this->expectException(SecurityException::class);
		self::$resizer->getSourceImagePath('../../haxxor.png');
	}


	public function testImageFound(): void
	{
		$this->assertSame(
			__DIR__ . '/fixtures/test.png',
			self::$resizer->getSourceImagePath(self::$image),
		);
	}


	public function testProcess(): void
	{
		$thumbnail = FileSystem::normalizePath(self::$resizer->process(self::$image, 'c200xc200'));

		$this->assertSame(
			FileSystem::normalizePath(__DIR__ . '/../temp/resizer/fixtures/test.png/c200xc200.png'),
			$thumbnail,
		);
	}


	public function testGeneratedImage(): void
	{
		$thumbnail = FileSystem::normalizePath(self::$resizer->process(self::$image, 'c200xc200'));

		$size = getimagesize($thumbnail);

		$this->assertSame(
			[200, 200],
			[$size[0] ?? 0, $size[1] ?? 0],
		);
	}


	/** @return iterable<string, array{string}> */
	public static function provideUnsafePaths(): iterable
	{
		yield 'parent at start' => ['../fixtures/test.png'];
		yield 'parent in the middle' => ['fixtures/../fixtures/test.png'];
		yield 'parent at end' => ['fixtures/..'];
		yield 'backslash' => ['fixtures\\..\\..\\haxxor.png'];
		yield 'null byte' => ["fixtures/test.png\0.png"];
		yield 'line break' => ["fixtures/test.png\nX-Injected: 1"];
		yield 'empty' => [''];
	}


	#[DataProvider('provideUnsafePaths')]
	public function testUnsafePathIsRefused(string $path): void
	{
		$this->expectException(SecurityException::class);
		self::$resizer->getSourceImagePath($path);
	}


	public function testUnsafePathIsRefusedForThumbnail(): void
	{
		$this->expectException(SecurityException::class);
		self::$resizer->getThumbnailPath('../../haxxor.png', '100x');
	}


	public function testUnsupportedSourceType(): void
	{
		// exists, but is not an image type the resizer is allowed to open
		$this->expectException(ImageNotFoundOrReadableException::class);
		self::$resizer->getSourceImagePath('phpunit.xml');
	}


	public function testSourceWithFakeExtension(): void
	{
		FileSystem::write(self::PUBLIC_DIR . '/fake.png', '<svg xmlns="http://www.w3.org/2000/svg"/>');

		$this->expectException(ImageNotFoundOrReadableException::class);
		self::$resizer->getSourceImagePath('_public/fake.png');
	}


	public function testInvalidParamsDoNotTouchFilesystem(): void
	{
		try {
			self::$resizer->process(self::$image, 'evil/dir');
			$this->fail('Exception expected.');
		} catch (CouldNotParseResizerParamsException) {
		}

		$this->assertDirectoryDoesNotExist(self::$config->getTempDir() . self::$config->getCache() . 'fixtures/test.png/evil');
	}


	public function testTooLargeDimensions(): void
	{
		$this->expectException(DimensionsTooLargeException::class);
		self::$resizer->process(self::$image, '4001x');
	}


	public function testTooLargeNumberIsNotParsed(): void
	{
		$this->expectException(CouldNotParseResizerParamsException::class);
		self::$resizer->process(self::$image, '999999999999999999999x');
	}


	public function testUppercaseFormatIsNormalized(): void
	{
		$this->assertStringEndsWith(
			'/x100.png',
			FileSystem::normalizePath(self::$resizer->getThumbnailPath(self::$image, 'x100', 'PNG')),
		);
	}


	public function testUnsupportedFormat(): void
	{
		$this->expectException(UnsupportedFormatException::class);
		self::$resizer->getThumbnailPath(self::$image, 'x100', 'exe');
	}


	public function testThumbnailIsRegeneratedWhenSourceChanges(): void
	{
		FileSystem::copy(__DIR__ . '/fixtures/test.png', self::PUBLIC_DIR . '/regen.png');

		$thumbnail = self::$resizer->process('_public/regen.png', 'x100');
		touch($thumbnail, time() - 3600);
		touch(self::PUBLIC_DIR . '/regen.png', time() - 60);
		clearstatcache();

		self::$resizer->process('_public/regen.png', 'x100');
		clearstatcache();

		$this->assertGreaterThan(time() - 60, filemtime($thumbnail));
	}


	public function testExifOrientationWithGd(): void
	{
		if (!function_exists('exif_read_data')) {
			$this->markTestSkipped('ext-exif is required to read the orientation with GD.');
		}

		// landscape pixels 200x100, EXIF says "rotate 90° clockwise" -> displayed as portrait 100x200
		self::createJpegWithOrientation(self::PUBLIC_DIR . '/rotated.jpg', 6);

		$size = getimagesize(self::$resizer->process('_public/rotated.jpg', '50x'));
		$this->assertSame([50, 100], [$size[0] ?? 0, $size[1] ?? 0]);
	}


	public function testExifOrientationWithImagick(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('Imagick is not available.');
		}

		self::createJpegWithOrientation(self::PUBLIC_DIR . '/rotated-imagick.jpg', 8);

		$dto = new ResizerConfigDTO;
		$dto->tempDir = __DIR__ . '/../temp';
		$dto->wwwDir = __DIR__;
		$dto->library = 'Imagick';
		$dto->strip = false; // the orientation tag must be reset, not only stripped
		$config = new ResizerConfig($dto);
		$resizer = new Resizer($config, new OutputFormat(new Request(new UrlScript), $config));

		$thumbnail = $resizer->process('_public/rotated-imagick.jpg', '50x');
		$image = new Imagick($thumbnail);

		$this->assertSame([50, 100], [$image->getImageWidth(), $image->getImageHeight()]);
		$this->assertContains($image->getImageOrientation(), [Imagick::ORIENTATION_UNDEFINED, Imagick::ORIENTATION_TOPLEFT]);
	}


	public function testLockFileIsRemoved(): void
	{
		self::$resizer->process(self::$image, 'x130');

		$this->assertSame([], glob(__DIR__ . '/../temp/resizer-locks/*.lock'));
	}


	public function testWaitsForConcurrentGeneration(): void
	{
		$thumbnailPath = self::$resizer->getThumbnailPath(self::$image, 'x140');
		$lockPath = __DIR__ . '/../temp/resizer-locks/' . md5($thumbnailPath) . '.lock';
		FileSystem::createDir(dirname($lockPath));

		// another request: holds the lock, then writes the thumbnail (fake content) and releases it
		$code = sprintf(
			'$h = fopen(%1$s, "c"); flock($h, LOCK_EX); echo "locked\n"; usleep(500000);'
			. ' @mkdir(dirname(%2$s), 0777, true); file_put_contents(%2$s, "generated by the other request");'
			. ' flock($h, LOCK_UN);',
			var_export($lockPath, true),
			var_export($thumbnailPath, true),
		);
		$process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$this->assertSame("locked\n", fgets($pipes[1]));

		// must wait for the lock and use the thumbnail instead of encoding it again
		$result = self::$resizer->process(self::$image, 'x140');
		proc_close($process);

		$this->assertSame($thumbnailPath, $result);
		$this->assertSame('generated by the other request', file_get_contents($thumbnailPath));
	}


	public function testOutputDimensions(): void
	{
		$dimensions = static function (?string $params, string $file = 'fixtures/test.png'): ?array {
			$output = self::$resizer->getOutputDimensions($file, $params);
			return $output === null ? null : [$output->getWidth(), $output->getHeight()];
		};

		// the source is 500x500
		$this->assertSame([100, 100], $dimensions('100x'));
		$this->assertSame([100, 50], $dimensions('c100xc50'));
		// allowUpscale: false by default
		$this->assertSame([500, 500], $dimensions('1000x'));
		$this->assertSame([400, 500], $dimensions('c800xc1000'));
		$this->assertSame([500, 500], $dimensions('ifresize-1000x'));
		$this->assertSame([500, 500], $dimensions(null));
		$this->assertNull($dimensions('nonsense'));
		$this->assertNull($dimensions('ifresize-c10xc10'));
		$this->assertNull($dimensions('100x', 'does_not_exist.png'));
		$this->assertNull($dimensions('100x', '../composer.json'));
	}


	public function testUpscale(): void
	{
		// the source is 500x500
		$thumbnail = self::$resizer->process(self::$image, '800x');
		$this->assertSame([500, 500], array_slice((array) getimagesize($thumbnail), 0, 2), 'not upscaled by default');

		$thumbnail = self::$resizer->process(self::$image, 'c800xc1000');
		$this->assertSame([400, 500], array_slice((array) getimagesize($thumbnail), 0, 2), 'crop box scaled down');

		$dto = new ResizerConfigDTO;
		$dto->tempDir = __DIR__ . '/../temp';
		$dto->wwwDir = __DIR__;
		$dto->library = 'Gd';
		$dto->allowUpscale = true;
		$dto->cache = '/resizer-upscale/'; // the thumbnail name does not depend on the option
		$config = new ResizerConfig($dto);
		$resizer = new Resizer($config, new OutputFormat(new Request(new UrlScript), $config));

		try {
			$thumbnail = $resizer->process(self::$image, '800x');
			$this->assertSame([800, 800], array_slice((array) getimagesize($thumbnail), 0, 2), 'upscaled');
			$this->assertSame([800, 1000], [
				$resizer->getOutputDimensions(self::$image, 'c800xc1000')?->getWidth(),
				$resizer->getOutputDimensions(self::$image, 'c800xc1000')?->getHeight(),
			]);
		} finally {
			FileSystem::delete($dto->tempDir . $dto->cache);
		}
	}


	public function testOutputDimensionsWithExifOrientation(): void
	{
		if (!function_exists('exif_read_data')) {
			$this->markTestSkipped('ext-exif is required to read the orientation with GD.');
		}

		// 200x100 pixels, displayed as 100x200
		self::createJpegWithOrientation(self::PUBLIC_DIR . '/rotated-dimensions.jpg', 6);
		$dimensions = self::$resizer->getOutputDimensions('_public/rotated-dimensions.jpg', '50x');

		$this->assertSame([50, 100], [$dimensions?->getWidth(), $dimensions?->getHeight()]);
	}


	public function testPngIsNotInterlaced(): void
	{
		// interlace: true is the default, Adam7 would make the PNG bigger
		$thumbnail = self::$resizer->process(self::$image, 'x120', 'png');

		// IHDR: 8 bytes signature, 4 length, 4 type, 4 width, 4 height, depth, color, compression, filter, interlace
		$this->assertSame(0, ord(((string) file_get_contents($thumbnail, length: 29))[28]));
	}


	public function testJpegIsProgressive(): void
	{
		$thumbnail = self::$resizer->process(self::$image, 'x120', 'jpg');

		// SOF2 marker = progressive DCT
		$this->assertStringContainsString("\xFF\xC2", (string) file_get_contents($thumbnail));
	}


	public function testLargeJpegWithImagick(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('Imagick is not available.');
		}

		// large enough for the decoder to scale it down while decoding ("jpeg:size")
		self::createJpegWithOrientation(self::PUBLIC_DIR . '/large.jpg', 1, 2000, 1000);
		self::createJpegWithOrientation(self::PUBLIC_DIR . '/large-rotated.jpg', 6, 2000, 1000);
		$resizer = $this->createImagickResizer();

		$cases = [
			['large.jpg', '100x', [100, 50]],
			['large.jpg', 'x100', [200, 100]],
			['large.jpg', 'c80xc80', [80, 80]],
			['large.jpg', '120x30!', [120, 30]],
			['large.jpg', 'ifresize-150x', [150, 75]],
			['large-rotated.jpg', '100x', [100, 200]], // the rotation swaps the dimensions after decoding
			['large-rotated.jpg', 'c50xc80', [50, 80]],
		];

		foreach ($cases as [$file, $params, $expected]) {
			$image = new Imagick($resizer->process('_public/' . $file, $params, 'jpg'));
			$this->assertSame($expected, [$image->getImageWidth(), $image->getImageHeight()], "$file $params");
		}

		// the left half of the source is red - the decoded image is the real one, not garbage
		$image = new Imagick($resizer->process('_public/large.jpg', '100x', 'jpg'));
		$pixel = $image->getImagePixelColor(10, 25)->getColor();
		$this->assertGreaterThan(200, $pixel['r']);
		$this->assertLessThan(60, $pixel['b']);
	}


	public function testAnimatedGif(): void
	{
		$this->requireImagick();
		self::createAnimatedGif(self::PUBLIC_DIR . '/animated.gif', iterations: 3);
		$resizer = $this->createImagickResizer();

		// 100x60 -> 50x30, the delta frames are scaled at their positions
		$frames = $this->readFrames($resizer->process('_public/animated.gif', '50x', 'gif'));
		$this->assertCount(3, $frames);

		foreach ($frames as $frame) {
			$this->assertSame([50, 30], [$frame->getImageWidth(), $frame->getImageHeight()]);
		}

		$this->assertColor('red', $frames[0], 10, 10);
		$this->assertColor('blue', $frames[1], 10, 10); // frame 2 changed only this part
		$this->assertColor('red', $frames[1], 35, 20);
		$this->assertColor('blue', $frames[2], 10, 10); // kept from frame 2 (dispose none)
		$this->assertColor('green', $frames[2], 35, 20); // frame 3 changed only this part
		$this->assertColor('red', $frames[2], 45, 3);

		// the loop count is kept
		$this->assertSame(3, (new Imagick($resizer->process('_public/animated.gif', '50x', 'gif')))->getImageIterations());
	}


	public function testAnimatedGifCrop(): void
	{
		$this->requireImagick();
		self::createAnimatedGif(self::PUBLIC_DIR . '/animated-crop.gif');

		// 100x60 -> scaled to 50x30, cropped to the left 30x30
		$frames = $this->readFrames($this->createImagickResizer()->process('_public/animated-crop.gif', 'l30xt30', 'gif'));
		$this->assertCount(3, $frames);

		foreach ($frames as $frame) {
			$this->assertSame([30, 30], [$frame->getImageWidth(), $frame->getImageHeight()]);
		}

		$this->assertColor('blue', $frames[2], 10, 10);
	}


	public function testAnimatedGifToWebp(): void
	{
		$this->requireImagick();

		if (!in_array('WEBP', Imagick::queryFormats(), true)) {
			$this->markTestSkipped('ImageMagick without WEBP support.');
		}

		self::createAnimatedGif(self::PUBLIC_DIR . '/animated-webp.gif');
		$frames = $this->readFrames($this->createImagickResizer()->process('_public/animated-webp.gif', '50x', 'webp'));

		$this->assertCount(3, $frames);
		$this->assertColor('blue', $frames[2], 10, 10);
		$this->assertColor('green', $frames[2], 35, 20);
	}


	public function testAnimatedGifToStaticFormat(): void
	{
		$this->requireImagick();
		self::createAnimatedGif(self::PUBLIC_DIR . '/animated-static.gif');

		// PNG can't hold the animation: the first frame, not all frames flattened into one
		$frames = $this->readFrames($this->createImagickResizer()->process('_public/animated-static.gif', '50x', 'png'));

		$this->assertCount(1, $frames);
		$this->assertColor('red', $frames[0], 10, 10);
		$this->assertColor('red', $frames[0], 35, 20);
	}


	public function testAnimationOverLimit(): void
	{
		$this->requireImagick();
		self::createAnimatedGif(self::PUBLIC_DIR . '/animated-limit.gif');

		// 3 frames × 100 × 60 = 18 000 pixels
		$frames = $this->readFrames($this->createImagickResizer(['maxAnimationPixels' => 10_000])->process('_public/animated-limit.gif', '50x', 'gif'));

		$this->assertCount(1, $frames);
		$this->assertColor('red', $frames[0], 10, 10);
	}


	public function testAnimatedGifWithGd(): void
	{
		$this->requireImagick(); // to create the GIF

		self::createAnimatedGif(self::PUBLIC_DIR . '/animated-gd.gif');

		// GD can't animate: the first frame
		$frames = $this->readFrames(self::$resizer->process('_public/animated-gd.gif', '50x', 'gif'));
		$this->assertCount(1, $frames);
		$this->assertColor('red', $frames[0], 10, 10);
	}


	private function requireImagick(): void
	{
		if (!extension_loaded('imagick')) {
			$this->markTestSkipped('Imagick is not available.');
		}
	}


	/**
	 * Frames as displayed (coalesced).
	 * @return list<Imagick>
	 */
	private function readFrames(string $path): array
	{
		$frames = [];

		foreach ((new Imagick($path))->coalesceImages() as $frame) {
			$frames[] = $frame->getImage();
		}

		return $frames;
	}


	/** @param 'red'|'green'|'blue' $expected */
	private function assertColor(string $expected, Imagick $frame, int $x, int $y): void
	{
		$color = $frame->getImagePixelColor($x, $y)->getColor();
		$channels = ['red' => $color['r'], 'green' => $color['g'], 'blue' => $color['b']];

		foreach ($channels as $channel => $value) {
			$channel === $expected
				? $this->assertGreaterThan(180, $value, "$expected at $x,$y: $channel")
				: $this->assertLessThan(80, $value, "$expected at $x,$y: $channel");
		}
	}


	/**
	 * 100x60 GIF: a red frame, then two delta frames changing only a 20x20 part (blue at 10,10, green at 60,30),
	 * each keeping the previous content (dispose none).
	 */
	private static function createAnimatedGif(string $path, int $iterations = 0): void
	{
		$animation = new Imagick;

		foreach ([['red', 100, 60, 0, 0], ['blue', 20, 20, 10, 10], ['lime', 20, 20, 60, 30]] as [$color, $width, $height, $x, $y]) {
			$frame = new Imagick;
			$frame->newImage($width, $height, new \ImagickPixel($color));
			$frame->setImageFormat('gif');
			$frame->setImagePage(100, 60, $x, $y);
			$frame->setImageDelay(20);
			$frame->setImageDispose(1); // none
			$frame->setImageIterations($iterations);
			$animation->addImage($frame);
		}

		FileSystem::createDir(dirname($path));
		$animation->writeImages($path, true);
	}


	/** @param array<string, mixed> $overrides ResizerConfigDTO properties */
	private function createImagickResizer(array $overrides = []): Resizer
	{
		$dto = new ResizerConfigDTO;
		$dto->tempDir = __DIR__ . '/../temp';
		$dto->wwwDir = __DIR__;
		$dto->library = 'Imagick';

		foreach ($overrides as $property => $value) {
			$dto->$property = $value;
		}

		$config = new ResizerConfig($dto);

		return new Resizer($config, new OutputFormat(new Request(new UrlScript), $config));
	}


	/**
	 * JPEG (200x100 by default, the left half red) with an EXIF APP1 segment containing only the Orientation tag.
	 * @param positive-int $width
	 * @param positive-int $height
	 */
	private static function createJpegWithOrientation(string $path, int $orientation, int $width = 200, int $height = 100): void
	{
		$gd = imagecreatetruecolor($width, $height);
		self::assertNotFalse($gd);
		imagefilledrectangle($gd, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) imagecolorallocate($gd, 255, 0, 0));
		ob_start();
		imagejpeg($gd);
		$jpeg = (string) ob_get_clean();

		$tiff = 'MM' . pack('n', 42) . pack('N', 8) // big endian TIFF header, IFD0 at offset 8
			. pack('n', 1) // 1 entry
			. pack('nnN', 0x0112, 3, 1) . pack('nn', $orientation, 0) // Orientation, SHORT, count 1, value
			. pack('N', 0); // no next IFD
		$app1 = "Exif\0\0" . $tiff;

		FileSystem::write($path, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2));
	}


	public function testJxlSourceRefusedWithoutServerSupport(): void
	{
		// GD can't read JXL, the source is refused before it is opened
		FileSystem::copy(__DIR__ . '/fixtures/test.png', self::PUBLIC_DIR . '/image.jxl');

		$this->expectException(ImageNotFoundOrReadableException::class);
		self::$resizer->getSourceImagePath('_public/image.jxl');
	}


	public function testJxlWithImagick(): void
	{
		if (!extension_loaded('imagick') || !in_array('JXL', Imagick::queryFormats(), true)) {
			$this->markTestSkipped('Imagick with JPEG XL support is not available.');
		}

		$dto = new ResizerConfigDTO;
		$dto->tempDir = __DIR__ . '/../temp';
		$dto->wwwDir = __DIR__;
		$dto->library = 'Imagick';
		$dto->isJxlSupportedByServer = true;
		$config = new ResizerConfig($dto);
		$resizer = new Resizer($config, new OutputFormat(new Request(new UrlScript, headers: ['Accept' => 'image/jxl']), $config));

		foreach (['x100', 'x100-q100'] as $params) {
			$thumbnail = $resizer->process(self::$image, $params, 'jxl');
			$this->assertStringEndsWith('.jxl', $thumbnail);

			// JXL codestream or container signature
			$header = (string) file_get_contents($thumbnail, length: 12);
			$this->assertTrue(
				str_starts_with($header, "\xFF\x0A") || $header === "\x00\x00\x00\x0CJXL \x0D\x0A\x87\x0A",
				"$params: not a JPEG XL file",
			);
			$this->assertSame(100, (new Imagick($thumbnail))->getImageHeight());
		}

		// a JXL source, the browser accepts JXL
		FileSystem::copy($thumbnail, self::PUBLIC_DIR . '/source.jxl');
		$this->assertStringEndsWith('/x50.jxl', FileSystem::normalizePath($resizer->process('_public/source.jxl', 'x50')));
	}


	public static function tearDownAfterClass(): void
	{
		parent::tearDownAfterClass();
		FileSystem::delete(self::$config->getTempDir() . self::$config->getCache());
		FileSystem::delete(self::PUBLIC_DIR);
	}
}
