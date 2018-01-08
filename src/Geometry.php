<?php

/**
 * @package    Nelson
 * @subpackage Helpers
 * @author     Ondřej Pospíšil <ondrej.pospisil@minion.cz>
 * @author     Pavel Linhart <pavel.linhart@minion.cz>
 * @copyright  2017 Minion Interactive s.r.o.
 */

namespace Nelson\Resizer;

use Imagine\Image\Point;
use Nette\InvalidArgumentException;
use Nette\SmartObject;

class Geometry
{
	use SmartObject;

	/**
	 * @param  array|string $geometry
	 * @return array|bool
	 */
	public static function parseGeometry($geometry)
	{
		if (is_array($geometry)) {
			return $geometry;
		} else {
			$pattern = '#(?:(ifresize)-)?([lcr]?)(\d*)x([tcb]?)(\d*)([!]?)([+-]?[0-9]*)([+-]?[0-9]*)#';

			if (preg_match($pattern, $geometry, $matches)) {
				return [
					'ifresize' => (bool) $matches[1],
					'horizontal' => $matches[2],
					'vertical' => $matches[4],
					'width' => (int) $matches[3],
					'height' => (int) $matches[5],
					'suffix' => $matches[6],
					'horizontalMargin' => $matches[7],
					'verticalMargin' => $matches[8],
				];
			}
		}

		return false;
	}


	/**
	 * @param  array $srcSize
	 * @param  array $geometry
	 * @return array
	 */
	public static function calculateNewSize(array $srcSize, array $geometry)
	{
		$desiredSize = $dstSize = [
			'width' => $geometry['width'],
			'height' => $geometry['height'],
		];

		// nejsou zadane zadne parametry, vratit realne rozmery obrazku
		if (!array_filter($geometry)) {
			return $srcSize;
		}

		// pokud parametry vynucuji vysledny rozmer, vratit tyto
		if (!empty($geometry['suffix']) or (strpos($geometry['suffix'], '!') === true)) {
			return $desiredSize;
		}

		// This should not happen
		if (static::isCrop($geometry) and static::isIfResize($geometry)) {
			throw new InvalidArgumentException('Crop and IfResize can not be used together.');
		}

		// -------------------------------

		$srcRatio = $srcSize['width'] / $srcSize['height']; // realny pomer stran
		if (!empty($desiredSize['width']) and !empty($desiredSize['height'])) {
			$desiredRatio = $desiredSize['width'] / $desiredSize['height']; // teoreticky chteny pomer stran
		} else {
			$desiredRatio = $srcRatio;
		}

		if ($desiredRatio <= $srcRatio and !empty($desiredSize['width'])) { // vysledna sirka bude odpovidat pozadavku
			$outputRatio = $desiredSize['width'] / $srcSize['width'];
		} elseif (!empty($desiredSize['height'])) { // vysledna vyska bude odpovidat pozadavku
			$outputRatio = $desiredSize['height'] / $srcSize['height'];
		} else {
			$outputRatio = $srcRatio;
		}

		$dstSize['width'] = round($srcSize['width'] * $outputRatio);
		$dstSize['height'] = round($srcSize['height'] * $outputRatio);

		// need to scale up to make room for the crop again
		if (static::isCrop($geometry)) {
			if ($dstSize['width'] == $geometry['width']) {
				$dstSize['width'] = round($dstSize['width'] * $geometry['height'] / $dstSize['height']);
				$dstSize['height'] = $geometry['height'];
			} else {
				$dstSize['height'] = round($dstSize['height'] * $geometry['width'] / $dstSize['width']);
				$dstSize['width'] = $geometry['width'];
			}
		}

		// If the image is smaller than the desired size, hijack the process
		if (static::isIfResize($geometry)
			and (
				$geometry['width'] > $srcSize['width'] and $geometry['height'] > $srcSize['height']
				or $geometry['width'] > $srcSize['width'] and $geometry['height'] == 0
				or $geometry['height'] > $srcSize['height'] and $geometry['width'] == 0
			)
		) {
			$dstSize['width'] = $srcSize['width'];
			$dstSize['height'] = $srcSize['height'];
		}

		return $dstSize;
	}


	/**
	 * @param  array $geometry
	 * @return bool
	 */
	public static function isCrop(array $geometry)
	{
		return !empty($geometry['horizontal']) and !empty($geometry['vertical']);
	}


	/**
	 * @param  array $geometry
	 * @return bool
	 */
	public static function isIfResize(array $geometry)
	{
		return !empty($geometry['ifresize']);
	}


	/**
	 * @param  array $geometry
	 * @param  array $imageOutputSize
	 * @return Point
	 */
	public static function getCropPoint(array $geometry, array $imageOutputSize)
	{
		switch ($geometry['horizontal']) {
			case 'l':
				$x = 0;
				break;

			case 'c':
				$x = ($imageOutputSize['width'] - $geometry['width']) / 2;
				break;

			case 'r':
				$x = $imageOutputSize['width'] - $geometry['width'];
				break;

			default:
				$x = 0;
				break;
		}

		switch ($geometry['vertical']) {
			case 't':
				$y = 0;
				break;

			case 'c':
				$y = ($imageOutputSize['height'] - $geometry['height']) / 2;
				break;

			case 'b':
				$y = $imageOutputSize['height'] - $geometry['height'];
				break;

			default:
				$y = 0;
				break;
		}

		return new Point($x, $y);
	}
}
