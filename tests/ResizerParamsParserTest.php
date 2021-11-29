<?php
declare(strict_types=1);

namespace Test;

use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nelson\Resizer\ResizerParams;
use Nelson\Resizer\ResizerParamsParser;
use Nette\SmartObject;
use Tester\Assert;
use Tester\TestCase;

// $container = require __DIR__ . '/bootstrap.php';
require __DIR__ . '/bootstrap.php';

/** @testCase */
class ResizerParamsParserTest extends TestCase
{
	use SmartObject;


	public function __construct()
	{
	}


	// runs for every test
	// public function setUp(): void
	// {
	// }


	public function testEmpty(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			null,
			false,
			null,
			null,
			null,
			null,
		);

		$actual = $this->parse('x');
		Assert::equal($expected, $actual);
	}


	public function testWidth(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			null,
			false,
			null,
			null,
			500,
			null,
		);

		$actual = $this->parse('500x');
		Assert::equal($expected, $actual);
	}


	public function testHeight(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			null,
			false,
			null,
			null,
			null,
			500,
		);

		$actual = $this->parse('x500');
		Assert::equal($expected, $actual);
	}


	public function testWidthHeight(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			null,
			false,
			null,
			null,
			400,
			300,
		);

		$actual = $this->parse('400x300');
		Assert::equal($expected, $actual);
	}


	public function testForce(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			null,
			true,
			null,
			null,
			500,
			500,
		);

		$actual = $this->parse('500x500!');
		Assert::equal($expected, $actual);
	}


	public function testIfresizeWidth(): void
	{
		$expected = new ResizerParams(
			true,
			null,
			null,
			false,
			null,
			null,
			400,
			null,
		);

		$actual = $this->parse('ifresize-400x');
		Assert::equal($expected, $actual);
	}


	public function testIfresizeHeight(): void
	{
		$expected = new ResizerParams(
			true,
			null,
			null,
			false,
			null,
			null,
			null,
			400,
		);

		$actual = $this->parse('ifresize-x400');
		Assert::equal($expected, $actual);
	}


	public function testIfresizeWidthHeight(): void
	{
		$expected = new ResizerParams(
			true,
			null,
			null,
			false,
			null,
			null,
			400,
			600,
		);

		$actual = $this->parse('ifresize-400x600');
		Assert::equal($expected, $actual);
	}


	// These test intentionally omit most of the possibilities (20+)
	public function testCropHorizontalCenter(): void
	{
		$expected = new ResizerParams(
			false,
			'c',
			null,
			false,
			null,
			null,
			500,
			800,
		);

		$actual = $this->parse('c500x800');
		Assert::equal($expected, $actual);
	}


	public function testCropVerticalCenter(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			'c',
			false,
			null,
			null,
			500,
			800,
		);

		$actual = $this->parse('500xc800');
		Assert::equal($expected, $actual);
	}


	public function testCropCenter(): void
	{
		$expected = new ResizerParams(
			false,
			'c',
			'c',
			false,
			null,
			null,
			500,
			800,
		);

		$actual = $this->parse('c500xc800');
		Assert::equal($expected, $actual);
	}


	public function testEmptyString(): void
	{
		Assert::exception(
			function() {$this->parse('');},
			CouldNotParseResizerParamsException::class
		);
	}


	public function testEmptyNull(): void
	{
		Assert::exception(
			function() {$this->parse(null);},
			CouldNotParseResizerParamsException::class
		);
	}


	private function parse(?string $params): ResizerParams
	{
		return (new ResizerParamsParser($params))->getParams();
	}
}

$test = new ResizerParamsParserTest;
$test->run();
