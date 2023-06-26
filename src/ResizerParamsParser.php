<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nette\SmartObject;

class ResizerParamsParser
{
	use SmartObject;

	public const PATTERN_IR = 'ir/';
	public const PATTERN_AUTO = '?:auto'; 										# use the image as-is, only compress/convert
	public const PATTERN_IFRESIZE = '(?:(ifresize)-)?';					# ifresize modifier
	public const PATTERN_WIDTH_MOD = '([lcr]?)([1-9][0-9]*)?';			# width modifer and dimension
	public const PATTERN_DIVIDER = 'x';											# divider
	public const PATTERN_HEIGHT_MOD = '([tcb]?)([1-9][0-9]*)?';			# height modifier and dimension
	public const PATTERN_FORCE_DIMS = '(!?)';									# force dimensions, disregard aspect ratio
	public const PATTERN_MARGIN_H = '(?:-hm([+-]?[0-9]*))?';				# horizontal margin, unused
	public const PATTERN_MARGIN_V = '(?:-vm([+-]?[0-9]*))?';				# vertical margin, unused
	public const PATTERN_MARGIN_Q = '(?:-q([0-9][0-9]?|100))?';			# quality, 0-100

	public const PATTERN_PARAMS = '(' .
		self::PATTERN_AUTO . '|' .
		self::PATTERN_IFRESIZE .
		self::PATTERN_WIDTH_MOD .
		self::PATTERN_DIVIDER .
		self::PATTERN_HEIGHT_MOD .
		self::PATTERN_FORCE_DIMS .
		self::PATTERN_MARGIN_H .
		self::PATTERN_MARGIN_V .
		self::PATTERN_MARGIN_Q .
	')';

	public const PATTERN = '~^' . self::PATTERN_PARAMS . '$~';
	public const PATTERN_JS = self::PATTERN_PARAMS;

	// public const PATTERN = '~
	// 	^(?:auto|                        # use the image as-is, only compress/convert
	// 		(?:(ifresize)-)?              # ifresize modifier
	// 		([lcr]?)([1-9][0-9]*)?        # width modifer and dimension
	// 		x                             # divider
	// 		([tcb]?)([1-9][0-9]*)?        # height modifier and dimension
	// 		(!?)                          # force dimensions, disregard aspect ratio
	// 		(?:-hm([+-]?[0-9]*))?         # horizontal margin, unused
	// 		(?:-vm([+-]?[0-9]*))?         # vertical margin, unused
	// 		(?:-q([0-9][0-9]?|100))?      # quality, 0-100
	// 	)$
	// ~x';

	private ResizerParams $params;


	public function __construct(?string $rawParams)
	{
		if ($rawParams === null) {
			throw new CouldNotParseResizerParamsException('Null string passed.');
		}

		$this->params = $this->parseParams($rawParams);
	}


	public function parseParams(string $rawParams): ResizerParams
	{
		if (!preg_match(self::PATTERN, $rawParams, $matches)) {
			throw new CouldNotParseResizerParamsException('Wrong params format.');
		}

		$ifresize = (bool) ($matches[1] ?? '');
		$horizontal = $matches[2] ?? '';
		$vertical = $matches[4] ?? '';
		$width = $this->parseNumericValueToPositiveIntOrNull($matches[3] ?? '');
		$height = $this->parseNumericValueToPositiveIntOrNull($matches[5] ?? '');
		$suffix = $matches[6] ?? '';
		$horizontalMargin = $matches[7] ?? '';
		$verticalMargin = $matches[8] ?? '';
		$quality = $this->parseNumericValueToIntOrNull($matches[9] ?? '');

		return new ResizerParams(
			$ifresize,
			$horizontal,
			$vertical,
			(bool) $suffix,
			$horizontalMargin,
			$verticalMargin,
			$width,
			$height,
			$quality,
		);
	}


	public function getParams(): ResizerParams
	{
		return $this->params;
	}


	/** @return int<0,100>|null */
	private function parseNumericValueToIntOrNull(string $value): ?int
	{
		if (strlen($value) === 0) {
			return null;
		}

		if (is_numeric($value)) {
			$int = (int) $value;

			if ($int >= 0 && $int <= 100) {
				return $int;
			}
		}

		return null;
	}


	/** @return positive-int|null */
	private function parseNumericValueToPositiveIntOrNull(string $value): ?int
	{
		if (strlen($value) === 0) {
			return null;
		}

		if (is_numeric($value)) {
			$int = (int) $value;

			if ($int > 0) {
				return $int;
			}
		}

		return null;
	}

}
