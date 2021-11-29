<?php
declare(strict_types=1);

namespace Test;

use Imagine\Image\Point;
use Nelson\Resizer\Dimensions;
use Nelson\Resizer\Exceptions\IncompatibleResizerParamsException;
use Nelson\Resizer\Geometry;
use Nette\SmartObject;
use Tester\Assert;
use Tester\TestCase;

// $container = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/bootstrap.php';

/** @testCase */
class GeometryTest extends TestCase
{
	use SmartObject;

	private Dimensions $sourceDimensions;


	public function __construct()
	{
		$this->sourceDimensions = new Dimensions(1600, 900);
	}


	public function testWidth(): void
	{
		Assert::equal(
			$this->expected(100, 56),
			$this->actual('100x'),
		);
	}


	public function testHeight(): void
	{
		Assert::equal(
			$this->expected(178, 100),
			$this->actual('x100'),
		);
	}


	public function testWidthHeight(): void
	{
		Assert::equal(
			$this->expected(200, 113),
			$this->actual('200x200'),
		);
	}


	public function testCrop1(): void
	{
		Assert::equal(
			$this->expected(266, 150),
			$this->actual('c250xc150'),
		);
	}


	public function testCrop2(): void
	{
		Assert::equal(
			$this->expected(266, 150),
			$this->actual('l250xt150'),
		);
	}


	public function testCrop3(): void
	{
		Assert::equal(
			$this->expected(266, 150),
			$this->actual('r250xb150'),
		);
	}


	public function testForceDimensions(): void
	{
		Assert::equal(
			$this->expected(650, 350),
			$this->actual('650x350!'),
		);
	}


	public function testIfresizeOver(): void
	{
		Assert::equal(
			$this->expected(1600, 900),
			$this->actual('ifresize-2500x2500'),
		);
	}


	public function testIfresizeUnder(): void
	{
		Assert::equal(
			$this->expected(1200, 675),
			$this->actual('ifresize-1200x800'),
		);
	}


	/** This should fail like a boss, but it does not because of wrong design */
	public function testIfresizeCrop(): void
	{
		Assert::exception(
			function() {$this->actual('ifresize-c2500xc1500');},
			IncompatibleResizerParamsException::class,
		);
	}


	public function testCropPointEqualCC(): void
	{
		Assert::equal(
			$this->expectedPoint(0, 0),
			$this->actualPoint('c1600xc900'),
		);
	}


	public function testCropPointEqualLT(): void
	{
		Assert::equal(
			$this->expectedPoint(0, 0),
			$this->actualPoint('l1600xt900'),
		);
	}


	public function testCropPointEqualRB(): void
	{
		Assert::equal(
			$this->expectedPoint(0, 0),
			$this->actualPoint('r1600xb900'),
		);
	}


	public function testCropPointHalfCC(): void
	{
		Assert::equal(
			$this->expectedPoint(0, 0),
			$this->actualPoint('c800xc450'),
		);
	}


	public function testCropPointHorizontalLC(): void
	{
		Assert::equal(
			$this->expectedPoint(0, 0),
			$this->actualPoint('l800xc900'),
		);
	}


	public function testCropPointHorizontalCC(): void
	{
		Assert::equal(
			$this->expectedPoint(400, 0),
			$this->actualPoint('c800xc900'),
		);
	}


	public function testCropPointHorizontalRC(): void
	{
		Assert::equal(
			$this->expectedPoint(800, 0),
			$this->actualPoint('r800xc900'),
		);
	}


	public function testCropPointVerticalCT(): void
	{
		Assert::equal(
			$this->expectedPoint(0, 0),
			$this->actualPoint('c1600xt450'),
		);
	}


	public function testCropPointVerticalCC(): void
	{
		Assert::equal(
			$this->expectedPoint(0, 225),
			$this->actualPoint('c1600xc450'),
		);
	}


	public function testCropPointVerticalCB(): void
	{
		Assert::equal(
			$this->expectedPoint(0, 450),
			$this->actualPoint('c1600xb450'),
		);
	}


	/* ------------------------------------------------------- */

	private function actual(string $params): Dimensions
	{
		$geometry = new Geometry($params);
		return $geometry->calculateNewSize($this->sourceDimensions);
	}


	private function actualPoint(string $params): Point
	{
		$geometry = new Geometry($params);
		$source = $geometry->calculateNewSize($this->sourceDimensions);
		return $geometry->getCropPoint($source);
	}


	private function expected(int $width, int $height): Dimensions
	{
		return new Dimensions($width, $height);
	}


	private function expectedPoint(int $x, int $y): Point
	{
		return new Point($x, $y);
	}

}

$test = new GeometryTest;
$test->run();
