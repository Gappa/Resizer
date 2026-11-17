<?php
declare(strict_types=1);

namespace Nelson\Resizer\Image;

use Imagick;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Imagick\Image as ImagickImage;
use Nelson\Resizer\Dimensions;
use Nelson\Resizer\Exceptions\DimensionsTooLargeException;
use Nelson\Resizer\Geometry;
use Nelson\Resizer\Helpers;
use Nelson\Resizer\ResizerConfig;

/**
 * Opens the source image and transforms it: EXIF orientation, resize, crop.
 * @internal
 */
final class ImageProcessor
{
	public function __construct(
		private readonly ImageLoader $loader,
		private readonly ResizerConfig $config,
	) {
	}


	/**
	 * @param string $outputFormat an animation is kept only for formats that can hold it
	 */
	public function process(string $sourcePath, Geometry $geometry, string $outputFormat): ImageInterface
	{
		$image = $this->loader->open($sourcePath, $geometry, $outputFormat);
		$animated = ImageLoader::isAnimated($image);

		// before the size is read - a rotation by 90° swaps the dimensions (photos only, not animations)
		if (!$animated) {
			$this->autoOrient($image);
		}

		$imageCurSize = $image->getSize();

		$sourceDimensions = new Dimensions(
			Helpers::getPositiveInt($imageCurSize->getWidth()),
			Helpers::getPositiveInt($imageCurSize->getHeight()),
		);

		$imageOutputSize = $geometry->calculateNewSize($sourceDimensions);
		$this->assertOutputDimensionsAllowed($imageOutputSize);

		// the same size (e.g. no upscaling): resampling would only blur the image and cost time
		if (
			$imageOutputSize->getWidth() !== $sourceDimensions->getWidth()
			|| $imageOutputSize->getHeight() !== $sourceDimensions->getHeight()
		) {
			$image->resize(new Box($imageOutputSize->getWidth(), $imageOutputSize->getHeight()));

			// Imagine resizes coalesced frames and deconstructs them back to deltas, the iterator stays at the last
			// (possibly smaller) frame - the first frame has the full size the crop is checked against
			if ($animated && $image instanceof ImagickImage) {
				$image->getImagick()->setFirstIterator();
			}
		}

		if ($geometry->getResizerParams()->isCrop()) {
			$cropBox = $geometry->getCropBox($sourceDimensions);
			$image->crop(
				$geometry->getCropPoint($imageOutputSize, $cropBox),
				new Box($cropBox->getWidth(), $cropBox->getHeight()),
			);
		}

		return $image;
	}


	private function assertOutputDimensionsAllowed(Dimensions $dimensions): void
	{
		// a crop may need an intermediate image larger in one dimension, so only the area is limited
		if ($dimensions->getWidth() * $dimensions->getHeight() > $this->config->getMaxWidth() * $this->config->getMaxHeight()) {
			throw new DimensionsTooLargeException('Output dimensions exceed the limit.');
		}
	}


	/**
	 * Rotates/flips the image by its EXIF orientation (photos from phones and cameras), the tag itself
	 * is removed by strip() or reset, so the image is never rotated twice. The orientation is read
	 * from the EXIF metadata (needs ext-exif), Imagick reads it on its own as a fallback.
	 * Same transformations as Imagine\Filter\Basic\Autorotate.
	 */
	private function autoOrient(ImageInterface $image): void
	{
		$orientation = $image->metadata()['ifd0.Orientation'] ?? null;
		$imagick = $image instanceof ImagickImage ? $image->getImagick() : null;

		if ($orientation === null && $imagick !== null) {
			$orientation = $imagick->getImageOrientation();
		}

		[$flip, $angle] = match (is_numeric($orientation) ? (int) $orientation : 0) {
			2 => [true, 0], // top-right
			3 => [false, 180], // bottom-right
			4 => [true, 180], // bottom-left
			5 => [true, -90], // left-top
			6 => [false, 90], // right-top
			7 => [true, 90], // right-bottom
			8 => [false, -90], // left-bottom
			default => [false, 0], // top-left or unknown
		};

		if ($flip) {
			$image->flipHorizontally();
		}

		if ($angle !== 0) {
			$image->rotate($angle);
		}

		// the pixels are upright now, metadata kept with strip: false must not rotate the image again
		$imagick?->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
	}
}
