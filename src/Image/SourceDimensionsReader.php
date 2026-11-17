<?php
declare(strict_types=1);

namespace Nelson\Resizer\Image;

use Imagick;
use ImagickException;
use Imagine\Image\AbstractImagine;
use Imagine\Imagick\Imagine as ImagickImagine;
use Nelson\Resizer\Dimensions;

/**
 * Dimensions of source images as displayed (EXIF orientation applied), read from the header only.
 * @internal
 */
final class SourceDimensionsReader
{
	/** @var array<string, Dimensions|null> by path */
	private array $cache = [];


	public function __construct(
		private readonly AbstractImagine $imagine,
	) {
	}


	/**
	 * Null when the dimensions can't be read. Cached per instance.
	 */
	public function read(string $fullPath): ?Dimensions
	{
		if (array_key_exists($fullPath, $this->cache)) {
			return $this->cache[$fullPath];
		}

		$dimensions = null;
		$size = @getimagesize($fullPath);

		if ($size !== false) {
			$orientation = null;

			if ($size[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
				$exif = @exif_read_data($fullPath, 'IFD0');
				$orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;
			}

			$dimensions = self::createOrientedDimensions($size[0], $size[1], $orientation);
		} elseif ($this->imagine instanceof ImagickImagine) {
			// formats getimagesize() does not know (JPEG XL)
			try {
				$imagick = new Imagick;
				$imagick->pingImage($fullPath);
				$dimensions = self::createOrientedDimensions(
					$imagick->getImageWidth(),
					$imagick->getImageHeight(),
					$imagick->getImageOrientation(),
				);
			} catch (ImagickException) {
			}
		}

		return $this->cache[$fullPath] = $dimensions;
	}


	/**
	 * Displayed dimensions - EXIF orientations 5-8 rotate by 90°. Null for invalid dimensions.
	 */
	private static function createOrientedDimensions(int $width, int $height, mixed $orientation): ?Dimensions
	{
		if ($width < 1 || $height < 1) {
			return null;
		}

		return in_array($orientation, [5, 6, 7, 8], true)
			? new Dimensions($height, $width)
			: new Dimensions($width, $height);
	}
}
