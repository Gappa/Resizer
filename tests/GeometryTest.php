<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Imagine\Image\Point;
use Nelson\Resizer\Dimensions;
use Nelson\Resizer\Exceptions\IncompatibleResizerParamsException;
use Nelson\Resizer\Geometry;
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
			$this->expected(266, 150),
			$this->actual('c250xc150'),
		);
	}


	public function testCrop2(): void
	{
		$this->assertEquals(
			$this->expected(266, 150),
			$this->actual('l250xt150'),
		);
	}


	public function testCrop3(): void
	{
		$this->assertEquals(
			$this->expected(266, 150),
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
