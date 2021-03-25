<?php
declare(strict_types=1);

namespace Test;

use Nette\SmartObject;
use Tester\Assert;
use Tester\TestCase;
use Nelson\Resizer\Geometry;

// $container = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/bootstrap.php';

class GeometryCalculatorTest extends TestCase
{
	use SmartObject;

	private int $width = 1600;
	private int $height = 900;
	private array $srcSize = [];


	public function __construct()
	{
		$this->srcSize = [
			'width' => $this->width,
			'height' => $this->height,
		];
	}


	// runs for every test
	public function setUp(): void
	{
		// $this->expected = $this->default;
	}


	public function testEmpty(): void
	{
		Assert::same(
			$this->exp(1600, 900),
			$this->actual('x')
		);
	}


	public function testWidth(): void
	{
		Assert::same(
			$this->exp(100.0, 56.0),
			$this->actual('100x')
		);
	}


	public function testHeight(): void
	{
		Assert::same(
			$this->exp(178.0, 100.0),
			$this->actual('x100')
		);
	}


	public function testWidthHeight(): void
	{
		Assert::same(
			$this->exp(200.0, 113.0),
			$this->actual('200x200')
		);
	}


	public function testCrop1(): void
	{
		Assert::same(
			$this->exp(250, 150),
			$this->actual('c250xc150')
		);
	}


	public function testCrop2(): void
	{
		Assert::same(
			$this->exp(250, 150),
			$this->actual('l250xt150')
		);
	}


	public function testCrop3(): void
	{
		Assert::same(
			$this->exp(250, 150),
			$this->actual('r250xb150')
		);
	}



	public function testForce(): void
	{
		Assert::same(
			$this->exp(650, 350),
			$this->actual('650x350!')
		);
	}


	public function testIfresizeOver(): void
	{
		Assert::same(
			$this->exp(1600, 900),
			$this->actual('ifresize-2500x2500')
		);
	}


	public function testIfresizeUnder(): void
	{
		Assert::same(
			$this->exp(1200.0, 675.0),
			$this->actual('ifresize-1200x800')
		);
	}


	/** This should fail like a boss, but it does not because of wrong design */
	public function testIfresizeCrop(): void
	{
		Assert::same(
			$this->exp(2500, 1500),
			$this->actual('ifresize-c2500xc1500')
		);
	}




	// Helpers
	private function actual(string $geometry): array
	{
		$parsedGeometry = Geometry::parseGeometry($geometry);

		if (Geometry::isCrop($parsedGeometry)) {
			return [
				'width' => $parsedGeometry['width'],
				'height' => $parsedGeometry['height'],
			];
		} else {
			return Geometry::calculateNewSize($this->srcSize, $parsedGeometry);
		}
	}


	/**
	 * @param $width
	 * @param $height
	 * @return array
	 */
	private function exp($width, $height): array
	{
		return [
			'width' => $width,
			'height' => $height,
		];
	}
}

$test = new GeometryCalculatorTest();
$test->run();
