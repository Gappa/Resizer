<?php
declare(strict_types=1);

namespace Test;

use Nette\SmartObject;
use Tester\Assert;
use Tester\TestCase;
use Nelson\Resizer\Geometry;

// $container = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/bootstrap.php';

class GeometryParserTest extends TestCase
{
	use SmartObject;

	private array $default = [
		'ifresize' => false,
		'horizontal' => '',
		'vertical' => '',
		'width' => 0,
		'height' => 0,
		'suffix' => '',
		'horizontalMargin' => '',
		'verticalMargin' => '',
	];

	private ?array $expected = [];


	public function __construct()
	{
		// $this->container = $container;
		// $this->config = $container->getByType(Config::class);
		// $this->linkGenerator = $container->getByType(LinkGenerator::class);
	}


	// runs for every test
	public function setUp()
	{
		$this->expected = $this->default;
	}


	public function testEmpty(): void
	{
		$actual = Geometry::parseGeometry('x');
		Assert::same($this->expected, $actual);
	}


	public function testWidth(): void
	{
		$this->expected['width'] = 500;
		$actual = Geometry::parseGeometry('500x');
		Assert::same($this->expected, $actual);
	}


	public function testHeight(): void
	{
		$this->expected['height'] = 500;
		$actual = Geometry::parseGeometry('x500');
		Assert::same($this->expected, $actual);
	}


	public function testWidthHeight(): void
	{
		$this->expected['width'] = 400;
		$this->expected['height'] = 300;
		$actual = Geometry::parseGeometry('400x300');
		Assert::same($this->expected, $actual);
	}


	public function testForce(): void
	{
		$this->expected['width'] = 500;
		$this->expected['height'] = 500;
		$this->expected['suffix'] = '!';
		$actual = Geometry::parseGeometry('500x500!');
		Assert::same($this->expected, $actual);
	}


	public function testIfresizeWidth(): void
	{
		$this->expected['ifresize'] = true;
		$this->expected['width'] = 400;
		$actual = Geometry::parseGeometry('ifresize-400x');
		Assert::same($this->expected, $actual);
	}


	public function testIfresizeHeight(): void
	{
		$this->expected['ifresize'] = true;
		$this->expected['height'] = 400;
		$actual = Geometry::parseGeometry('ifresize-x400');
		Assert::same($this->expected, $actual);
	}


	public function testIfresizeWidthHeight(): void
	{
		$this->expected['ifresize'] = true;
		$this->expected['width'] = 400;
		$this->expected['height'] = 600;
		$actual = Geometry::parseGeometry('ifresize-400x600');
		Assert::same($this->expected, $actual);
	}


	// These test intentionally omit most of the possibilities (20+)
	public function testCropHorizontalCenter(): void
	{
		$this->expected['horizontal'] = 'c';
		$this->expected['width'] = 500;
		$this->expected['height'] = 800;
		$actual = Geometry::parseGeometry('c500x800');
		Assert::same($this->expected, $actual);
	}


	public function testCropVerticalCenter(): void
	{
		$this->expected['vertical'] = 'c';
		$this->expected['width'] = 500;
		$this->expected['height'] = 800;
		$actual = Geometry::parseGeometry('500xc800');
		Assert::same($this->expected, $actual);
	}


	public function testCropCenter(): void
	{
		$this->expected['vertical'] = 'c';
		$this->expected['horizontal'] = 'c';
		$this->expected['width'] = 500;
		$this->expected['height'] = 800;
		$actual = Geometry::parseGeometry('c500xc800');
		Assert::same($this->expected, $actual);
	}


	public function testEmptyString(): void
	{
		$this->expected = null;
		$actual = Geometry::parseGeometry('');
		Assert::same($this->expected, $actual);
	}


	public function testEmptyNull(): void
	{
		$this->expected = null;
		$actual = Geometry::parseGeometry(null);
		Assert::same($this->expected, $actual);
	}

}

$test = new GeometryParserTest();
$test->run();
