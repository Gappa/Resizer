<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nelson\Resizer\ResizerParams;
use Nelson\Resizer\ResizerParamsParser;
use PHPUnit\Framework\TestCase;

class ResizerParamsParserTest extends TestCase
{

	public function testAuto(): void
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
			null,
		);

		$actual = $this->parse('auto');
		$this->assertEquals($expected, $actual);
	}


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
			null,
		);

		$actual = $this->parse('x');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('500x');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('x500');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('400x300');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('500x500!');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('ifresize-400x');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('ifresize-x400');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('ifresize-400x600');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('c500x800');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('500xc800');
		$this->assertEquals($expected, $actual);
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
			null,
		);

		$actual = $this->parse('c500xc800');
		$this->assertEquals($expected, $actual);
	}


	public function testQuality1(): void
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
			15,
		);

		$actual = $this->parse('x-q15');
		$this->assertEquals($expected, $actual);
	}


	public function testQuality2(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			null,
			false,
			null,
			null,
			100,
			200,
			90,
		);

		$actual = $this->parse('100x200-q90');
		$this->assertEquals($expected, $actual);
	}


	public function testQuality3(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			null,
			false,
			null,
			null,
			100,
			200,
			0,
		);

		$actual = $this->parse('100x200-q0');
		$this->assertEquals($expected, $actual);
	}


	public function testQuality4(): void
	{
		$expected = new ResizerParams(
			false,
			null,
			null,
			false,
			null,
			null,
			100,
			200,
			100,
		);

		$actual = $this->parse('100x200-q100');
		$this->assertEquals($expected, $actual);
	}


	public function testWrongKeyword1(): void
	{
		$this->expectException(CouldNotParseResizerParamsException::class);
		$this->parse('autobus');
	}


	public function testWrongKeyword2(): void
	{
		$this->expectException(CouldNotParseResizerParamsException::class);
		$this->parse('reauto');
	}


	public function testWrongKeyword3(): void
	{
		$this->expectException(CouldNotParseResizerParamsException::class);
		$this->parse('xxx');
	}


	public function testEmptyString(): void
	{
		$this->expectException(CouldNotParseResizerParamsException::class);
		$this->parse('');
	}


	public function testEmptyNull(): void
	{
		$this->expectException(CouldNotParseResizerParamsException::class);
		$this->parse(null);
	}


	/**
	 * @throws CouldNotParseResizerParamsException
	 */
	private function parse(?string $params): ResizerParams
	{
		return (new ResizerParamsParser($params))->getParams();
	}
}
