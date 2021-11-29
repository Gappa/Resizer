<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Exception;
use Nelson\Resizer\Exceptions\CouldNotParseResizerParamsException;
use Nette\SmartObject;

class ResizerParamsParser
{
	use SmartObject;

	private const PATTERN = '#(?:(ifresize)-)?([lcr]?)(\d*)x([tcb]?)(\d*)([!]?)([+-]?[0-9]*)([+-]?[0-9]*)#';

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

		$ifresize = (bool) $matches[1];
		$horizontal = $matches[2] ?: null;
		$vertical = $matches[4] ?: null;
		$width = $this->parseNumericValueToIntOrNull($matches[3]);
		$height = $this->parseNumericValueToIntOrNull($matches[5]);
		$suffix = $matches[6];
		$horizontalMargin = $matches[7] ?: null;
		$verticalMargin = $matches[8] ?: null;

		return new ResizerParams(
			$ifresize,
			$horizontal,
			$vertical,
			(bool) $suffix,
			$horizontalMargin,
			$verticalMargin,
			$width,
			$height
		);
	}


	public function getParams(): ResizerParams
	{
		return $this->params;
	}


	private function parseNumericValueToIntOrNull(string $value): ?int
	{
		if (strlen($value) === 0) {
			return null;
		}

		if (is_numeric($value)) {
			return (int) $value;
		}

		return null;
	}


}
