<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Imagine\Image\Point;
use Nelson\Resizer\Dimensions;
use Nelson\Resizer\Exceptions\IncompatibleResizerParamsException;
use Nelson\Resizer\Geometry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GeometryTest extends TestCase
{
	private static Dimensions $sourceDimensions;


	public static function setUpBeforeClass(): void
	{
		parent::setUpBeforeClass();
		self::$sourceDimensions = new Dimensions(1600, 900);
	}


	public function testWidth(): void
	{
		$this->assertEquals(
			$this->expected(100, 56),
			$this->actual('100x'),
		);
	}


	public function testHeight(): void
	{
		$this->assertEquals(
			$this->expected(178, 100),
			$this->actual('x100'),
		);
	}


	public function testWidthHeight(): void
	{
		$this->assertEquals(
			$this->expected(200, 113),
			$this->actual('200x200'),
		);
	}


	public function testCrop1(): void
	{
		$this->assertEquals(
			$this->expected(267, 150),
			$this->actual('c250xc150'),
		);
	}


	public function testCrop2(): void
	{
		$this->assertEquals(
			$this->expected(267, 150),
			$this->actual('l250xt150'),
		);
	}


	public function testCrop3(): void
	{
		$this->assertEquals(
			$this->expected(267, 150),
			$this->actual('r250xb150'),
		);
	}


	public function testForceDimensions(): void
	{
		$this->assertEquals(
			$this->expected(650, 350),
			$this->actual('650x350!'),
		);
	}


	public function testIfresizeOver(): void
	{
		$this->assertEquals(
			$this->expected(1600, 900),
			$this->actual('ifresize-2500x2500'),
		);
	}


	public function testIfresizeUnder(): void
	{
		$this->assertEquals(
			$this->expected(1200, 675),
			$this->actual('ifresize-1200x800'),
		);
	}


	/** This should fail like a boss, but it does not because of wrong design */
	public function testIfresizeCrop(): void
	{
		$this->expectException(IncompatibleResizerParamsException::class);

		$this->actual('ifresize-c2500xc1500');
	}


	public function testExtremeAspectRatioDoesNotRoundToZero(): void
	{
		$this->assertEquals(
			$this->expected(1, 1),
			(new Geometry('x1'))->calculateNewSize(new Dimensions(7, 1999)),
		);
	}


	public function testOutputDimensions(): void
	{
		$this->assertEquals($this->expected(100, 56), (new Geometry('100x'))->getOutputDimensions(self::$sourceDimensions));
		// crop: the crop box, not the scaled image (267x150)
		$this->assertEquals($this->expected(250, 150), (new Geometry('c250xc150'))->getOutputDimensions(self::$sourceDimensions));
		$this->assertEquals($this->expected(100, 100), (new Geometry('c100xc100!'))->getOutputDimensions(self::$sourceDimensions));
		$this->assertEquals($this->expected(1600, 900), (new Geometry('ifresize-2000x'))->getOutputDimensions(self::$sourceDimensions));
	}


	/**
	 * Source 1600x900: params, expected resized image, expected output (crop box), expected crop point
	 * @return iterable<string, array{string, array{int, int}, array{int, int}, array{int, int}|null}>
	 */
	public static function provideWithoutUpscale(): iterable
	{
		yield 'downscale unchanged' => ['800x', [800, 450], [800, 450], null];
		yield 'width' => ['2000x', [1600, 900], [1600, 900], null];
		yield 'height' => ['x1000', [1600, 900], [1600, 900], null];
		yield 'both' => ['3000x2000', [1600, 900], [1600, 900], null];
		yield 'forced' => ['2000x1000!', [2000, 1000], [2000, 1000], null];
		yield 'crop fits' => ['c400xc300', [533, 300], [400, 300], [66, 0]];
		// crop box 2000x1000 scaled by 0.8 to fit: 1600x800, centered vertically
		yield 'crop bigger' => ['c2000xc1000', [1600, 900], [1600, 800], [0, 50]];
		yield 'crop wider' => ['l3200xb900', [1600, 900], [1600, 450], [0, 450]];
		yield 'crop forced' => ['c2000xc2000!', [2000, 2000], [2000, 2000], [0, 0]];
	}


	/**
	 * @param array{int, int} $resized
	 * @param array{int, int} $output
	 * @param array{int, int}|null $cropPoint
	 */
	#[DataProvider('provideWithoutUpscale')]
	public function testWithoutUpscale(string $params, array $resized, array $output, ?array $cropPoint): void
	{
		$geometry = new Geometry($params, allowUpscale: false);
		$resizedDimensions = $geometry->calculateNewSize(self::$sourceDimensions);
		$outputDimensions = $geometry->getOutputDimensions(self::$sourceDimensions);

		$this->assertSame($resized, [$resizedDimensions->getWidth(), $resizedDimensions->getHeight()], 'resized');
		$this->assertSame($output, [$outputDimensions->getWidth(), $outputDimensions->getHeight()], 'output');

		if ($cropPoint !== null) {
			$point = $geometry->getCropPoint($resizedDimensions, $geometry->getCropBox(self::$sourceDimensions));
			$this->assertSame($cropPoint, [$point->getX(), $point->getY()], 'crop point');
		}
	}


	public function testDecodeSizeHintWithoutUpscale(): void
	{
		// the output is the source itself, nothing to reduce
		$this->assertNull((new Geometry('8000x', allowUpscale: false))->getDecodeSizeHint(new Dimensions(4000, 3000)));
	}


	/** @return iterable<string, array{string, int|null}> */
	public static function provideDecodeSizeHint(): iterable
	{
		// source 4000x3000; the hint covers both orientations (3000x4000 gives 400x533 for "400x")
		yield 'width' => ['400x', 1066];
		yield 'crop' => ['c200xc200', 534];
		yield 'forced' => ['100x100!', 200];
		yield 'too big for 1/2 scaling' => ['2000x', null];
		yield 'no dimensions' => ['x-q50', null];
		yield 'auto' => ['auto', null];
	}


	#[DataProvider('provideDecodeSizeHint')]
	public function testDecodeSizeHint(string $params, ?int $expected): void
	{
		$this->assertSame($expected, (new Geometry($params))->getDecodeSizeHint(new Dimensions(4000, 3000)));
	}


	public function testCropWithoutBothDimensions(): void
	{
		$this->expectException(IncompatibleResizerParamsException::class);

		$this->actual('cxc150');
	}


	/** Rounding must never make the scaled image smaller than the crop box */
	public function testCropCoversCropBox(): void
	{
		foreach ([[1600, 900], [333, 777], [1001, 999], [4000, 3], [7, 1999]] as [$sourceWidth, $sourceHeight]) {
			foreach ([[250, 150], [1, 1], [999, 1001], [123, 457], [1600, 1]] as [$cropWidth, $cropHeight]) {
				$output = (new Geometry("c{$cropWidth}xc{$cropHeight}"))
					->calculateNewSize(new Dimensions($sourceWidth, $sourceHeight));

				$message = "{$sourceWidth}x{$sourceHeight} -> c{$cropWidth}xc{$cropHeight}";
				$this->assertGreaterThanOrEqual($cropWidth, $output->getWidth(), $message);
				$this->assertGreaterThanOrEqual($cropHeight, $output->getHeight(), $message);
				// one side matches the crop box exactly
				$this->assertTrue($output->getWidth() === $cropWidth || $output->getHeight() === $cropHeight, $message);
			}
		}
	}


	public function testCropPointEqualCC(): void
	{
		$this->assertEquals(
			$this->expectedPoint(0, 0),
			$this->actualPoint('c1600xc900'),
		);
	}


	public function testCropPointEqualLT(): void
	{
		$this->assertEquals(
			$this->expectedPoint(0, 0),
			$this->actualPoint('l1600xt900'),
		);
	}


	public function testCropPointEqualRB(): void
	{
		$this->assertEquals(
			$this->expectedPoint(0, 0),
			$this->actualPoint('r1600xb900'),
		);
	}


	public function testCropPointHalfCC(): void
	{
		$this->assertEquals(
			$this->expectedPoint(0, 0),
			$this->actualPoint('c800xc450'),
		);
	}


	public function testCropPointHorizontalLC(): void
	{
		$this->assertEquals(
			$this->expectedPoint(0, 0),
			$this->actualPoint('l800xc900'),
		);
	}


	public function testCropPointHorizontalCC(): void
	{
		$this->assertEquals(
			$this->expectedPoint(400, 0),
			$this->actualPoint('c800xc900'),
		);
	}


	public function testCropPointHorizontalRC(): void
	{
		$this->assertEquals(
			$this->expectedPoint(800, 0),
			$this->actualPoint('r800xc900'),
		);
	}


	public function testCropPointVerticalCT(): void
	{
		$this->assertEquals(
			$this->expectedPoint(0, 0),
			$this->actualPoint('c1600xt450'),
		);
	}


	public function testCropPointVerticalCC(): void
	{
		$this->assertEquals(
			$this->expectedPoint(0, 225),
			$this->actualPoint('c1600xc450'),
		);
	}


	public function testCropPointVerticalCB(): void
	{
		$this->assertEquals(
			$this->expectedPoint(0, 450),
			$this->actualPoint('c1600xb450'),
		);
	}


	/* ------------------------------------------------------- */

	private function actual(string $params): Dimensions
	{
		$geometry = new Geometry($params);
		return $geometry->calculateNewSize(self::$sourceDimensions);
	}


	private function actualPoint(string $params): Point
	{
		$geometry = new Geometry($params);
		$source = $geometry->calculateNewSize(self::$sourceDimensions);
		return $geometry->getCropPoint($source);
	}


	/**
	 * @param positive-int $width
	 * @param positive-int $height
	 */
	private function expected(int $width, int $height): Dimensions
	{
		return new Dimensions($width, $height);
	}


	private function expectedPoint(int $x, int $y): Point
	{
		return new Point($x, $y);
	}
}
