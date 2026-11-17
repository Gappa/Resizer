<?php
declare(strict_types=1);

namespace Nelson\Resizer\Image;

use Error;
use Imagick;
use ImagickException;
use Imagine\Factory\ClassFactoryInterface;
use Imagine\Image\AbstractImagine;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\PaletteInterface;
use Imagine\Imagick\Image as ImagickImage;
use Imagine\Imagick\Imagine as ImagickImagine;
use Nelson\Resizer\Dimensions;
use Nelson\Resizer\Geometry;
use Nelson\Resizer\Helpers;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;

/**
 * Opens source images. With Imagick: JPEGs are scaled down while decoding ("jpeg:size"), animations are
 * decoded as full canvas frames (or only the first frame when they can't or must not be kept).
 * @internal
 */
final class ImageLoader
{
	public function __construct(
		private readonly AbstractImagine $imagine,
		private readonly ResizerConfig $config,
	) {
	}


	public static function isAnimated(ImageInterface $image): bool
	{
		return $image instanceof ImagickImage && $image->getImagick()->getNumberImages() > 1;
	}


	/**
	 * @param string $outputFormat an animation is kept only for formats that can hold it
	 */
	public function open(string $path, Geometry $geometry, string $outputFormat): ImageInterface
	{
		$imagine = $this->imagine;

		if (!$imagine instanceof ImagickImagine) {
			return $imagine->open($path); // GD reads the first frame of animations only
		}

		$isJpeg = in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), Resizer::FORMAT_SUFFIXES_JPG, true);
		$hint = $isJpeg ? $this->getJpegDecodeSizeHint($path, $geometry) : null;

		if (!$isJpeg || $hint !== null) {
			try {
				$imagick = new Imagick;

				if ($hint !== null) {
					$imagick->setOption('jpeg:size', $hint . 'x' . $hint);
				}

				// An animation (GIF, WEBP, APNG, ...) is kept only when the output format can hold it
				// and it fits the memory limit, otherwise only the first frame is decoded.
				$firstFrameOnly = !$isJpeg && (
					!in_array($outputFormat, Resizer::ANIMATION_FORMATS, true)
					|| $this->exceedsAnimationLimit($path)
				);
				$imagick->readImage($firstFrameOnly ? $path . '[0]' : $path);

				// Frames may cover only a part of the canvas (GIF deltas, even the first one) - make each a full
				// canvas frame as displayed, so they can be resized/cropped. The iterator at the first frame.
				if ($imagick->getNumberImages() > 1 || str_ends_with(strtolower($path), '.gif')) {
					$imagick = $imagick->coalesceImages();
				}

				$imagick->setFirstIterator();

				// the same as Imagine\Imagick\Imagine::open(), its palette detection is private
				$palette = (fn(Imagick $imagick): PaletteInterface => $this->createPalette($imagick))
					->call($imagine, $imagick);

				return $imagine->getClassFactory()->createImage(
					ClassFactoryInterface::HANDLE_IMAGICK,
					$imagick,
					$palette,
					$imagine->getMetadataReader()->readFile($path),
				);
			} catch (ImagickException | Error) {
				// Imagine opens it again and reports the error consistently
				// (Error: the private Imagine method changed in a new version)
			}
		}

		return $imagine->open($path);
	}


	/**
	 * Frames × canvas size over the limit, from the headers only (pingImage). Unreadable = exceeding.
	 */
	private function exceedsAnimationLimit(string $path): bool
	{
		try {
			$ping = new Imagick;
			$ping->pingImage($path);
			$frames = $ping->getNumberImages();

			if ($frames < 2) {
				return false;
			}

			$ping->setFirstIterator();
			$page = $ping->getImagePage();
			$width = max($page['width'], $ping->getImageWidth());
			$height = max($page['height'], $ping->getImageHeight());

			return $frames * $width * $height > $this->config->getMaxAnimationPixels();
		} catch (ImagickException) {
			return true;
		}
	}


	/**
	 * @return positive-int|null
	 */
	private function getJpegDecodeSizeHint(string $path, Geometry $geometry): ?int
	{
		$size = @getimagesize($path); // reads only the header

		if ($size === false || $size[2] !== IMAGETYPE_JPEG || $size[0] < 1 || $size[1] < 1) {
			return null;
		}

		$hint = $geometry->getDecodeSizeHint(new Dimensions($size[0], $size[1]));
		return $hint === null ? null : Helpers::getPositiveInt($hint);
	}
}
