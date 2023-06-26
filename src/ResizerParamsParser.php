<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nette\SmartObject;

class ResizerParamsParser
{
	use SmartObject;

	private const PATTERN = '~
		^(?:auto|                        # use the image as-is, only compress/convert
			(?:(ifresize)-)?              # ifresize modifier
			([lcr]?)(\d*)                 # width modifer and dimension
			x                             # divider
			([tcb]?)(\d*)                 # height modifier and dimension
			(!?)                          # force dimensions, disregard aspect ratio
			([+-]?[0-9]*)                 # horizontal margin, unused
			([+-]?[0-9]*)                 # vertical margin, unused
			(?:-q([1-9][0-9]?|100))?      # quality, 1-100
		)$
	~x';

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
		$width = $this->parseNumericValueToIntOrNull($matches[3] ?? '');
		$height = $this->parseNumericValueToIntOrNull($matches[5] ?? '');
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


	/** @return positive-int|null */
	private function parseNumericValueToIntOrNull(string $value): ?int
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
