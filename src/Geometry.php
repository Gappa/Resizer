<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Imagine\Image\Point;
use Nette\InvalidArgumentException;
use Nette\SmartObject;

class Geometry
{
	use SmartObject;

	/**
	 * @param array|string|null $geometry
	 * @return array|null
	 */
	public static function parseGeometry($geometry): ?array
	{
		if (is_array($geometry)) {
			return $geometry;
		} elseif ($geometry === null) {
			return null;
		} elseif (is_string($geometry) && strlen($geometry) === 0) {
			return null;
		}

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

		return null;
	}


	public static function calculateNewSize(array $srcSize, ?array $geometry): array
	{
		// Geometry is empty, use fallback
		if (empty($geometry)) {
			return $srcSize;
		}

		$desiredSize = $dstSize = [
			'width' => $geometry['width'],
			'height' => $geometry['height'],
		];

		// No params are set, use the image's dimensions
		if (!array_filter($geometry)) {
			return $srcSize;
		}

		// If params force a dimension, use them
		if (!empty($geometry['suffix']) || (strpos($geometry['suffix'], '!') !== false)) {
			return $desiredSize;
		}

		// This should not happen, has to be one or the other
		if (static::isCrop($geometry) && static::isIfResize($geometry)) {
			throw new InvalidArgumentException('Crop and IfResize can not be used together.');
		}

		// -------------------------------

		$srcRatio = $srcSize['width'] / $srcSize['height']; // real AR
		if (!empty($desiredSize['width']) && !empty($desiredSize['height'])) {
			$desiredRatio = $desiredSize['width'] / $desiredSize['height']; // possibly wanted AR
		} else {
			$desiredRatio = $srcRatio;
		}

		if ($desiredRatio <= $srcRatio && !empty($desiredSize['width'])) { // output width will respect the params
			$outputRatio = $desiredSize['width'] / $srcSize['width'];
		} elseif (!empty($desiredSize['height'])) { // output height will respect the params
			$outputRatio = $desiredSize['height'] / $srcSize['height'];
		} else {
			$outputRatio = $srcRatio;
		}

		$dstSize['width'] = round($srcSize['width'] * $outputRatio);
		$dstSize['height'] = round($srcSize['height'] * $outputRatio);

		// Need to scale up to make room for the crop again
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
			&& (
				$geometry['width'] > $srcSize['width'] && $geometry['height'] > $srcSize['height']
				|| $geometry['width'] > $srcSize['width'] && $geometry['height'] == 0
				|| $geometry['height'] > $srcSize['height'] && $geometry['width'] == 0
			)
		) {
			$dstSize['width'] = $srcSize['width'];
			$dstSize['height'] = $srcSize['height'];
		}

		return $dstSize;
	}


	public static function isCrop(?array $geometry): bool
	{
		return !empty($geometry['horizontal']) && !empty($geometry['vertical']);
	}


	public static function isIfResize(array $geometry): bool
	{
		return !empty($geometry['ifresize']);
	}


	public static function getCropPoint(?array $geometry, array $imageOutputSize): Point
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

		return new Point((int) $x, (int) $y);
	}
}
