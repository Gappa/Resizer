<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Imagine\Image\Point;
use Nelson\Resizer\Exceptions\IncompatibleResizerParamsException;

final class Geometry
{
	private ResizerParams $resizerParams;


	/**
	 * @param bool $allowUpscale false: the output is never bigger than the source (forced dimensions excepted),
	 *     a crop box bigger than the source is scaled down keeping its aspect ratio
	 */
	public function __construct(
		?string $rawParams,
		private readonly bool $allowUpscale = true,
	) {
		$this->resizerParams = (new ResizerParamsParser($rawParams))->getParams();
	}


	public function getResizerParams(): ResizerParams
	{
		return $this->resizerParams;
	}


	public function calculateNewSize(Dimensions $input): Dimensions
	{
		// Shortcut
		$rp = $this->resizerParams;

		// This should not happen, has to be one or the other
		if ($rp->isCrop() && $rp->isIfResize()) {
			throw new IncompatibleResizerParamsException('Crop and IfResize can not be used together.');
		}

		if ($rp->isCrop() && !$rp->hasBothDimensions()) {
			throw new IncompatibleResizerParamsException('Crop requires both width and height.');
		}

		// No dimensions set, use fallback
		if ($rp->hasNoDimensions()) {
			return $input;
		}

		// If params force a dimension, use them without any calculation
		if ($rp->getForceDimensions() && $rp->hasBothDimensions()) {
			return new Dimensions(
				Helpers::getPositiveInt($rp->getWidth()),
				Helpers::getPositiveInt($rp->getHeight()),
			);
		}

		// Crop: scale so the image covers the whole crop box. Computed directly from the input dimensions
		// and clamped, so rounding can never make the image smaller than the crop box.
		if ($rp->isCrop()) {
			$cropBox = $this->getCropBox($input);
			$scale = max($cropBox->getWidth() / $input->getWidth(), $cropBox->getHeight() / $input->getHeight());

			return new Dimensions(
				max($cropBox->getWidth(), (int) round($input->getWidth() * $scale)),
				max($cropBox->getHeight(), (int) round($input->getHeight() * $scale)),
			);
		}

		// -------------------------------

		$inputRatio = $input->getRatio();

		if ($rp->hasBothDimensions()) {
			// possibly wanted AR
			$desiredRatio = $rp->getWidth() / $rp->getHeight();
		} else {
			$desiredRatio = $inputRatio;
		}

		// output width will respect the params
		if ($desiredRatio <= $inputRatio && $rp->hasWidth()) {
			$outputRatio = $rp->getWidth() / $input->getWidth();
		}
		// output height will respect the params
		elseif ($rp->hasHeight()) {
			$outputRatio = $rp->getHeight() / $input->getHeight();
		} else {
			$outputRatio = $inputRatio;
		}

		// an extreme aspect ratio must not round a side down to zero
		$output = new Dimensions(
			max(1, (int) round($input->getWidth() * $outputRatio)),
			max(1, (int) round($input->getHeight() * $outputRatio)),
		);

		// If the image is smaller than the desired size, hijack the process
		if ($rp->isIfResize()
			&& (
				$rp->getWidth() > $input->getWidth() && $rp->getHeight() > $input->getHeight()
				||
				$rp->getWidth() > $input->getWidth() && !$rp->hasHeight()
				||
				$rp->getHeight() > $input->getHeight() && !$rp->hasWidth()
			)
		) {
			$output = new Dimensions($input->getWidth(), $input->getHeight());
		}

		if (!$this->allowUpscale && ($output->getWidth() > $input->getWidth() || $output->getHeight() > $input->getHeight())) {
			$output = new Dimensions($input->getWidth(), $input->getHeight());
		}

		return $output;
	}


	/**
	 * Size of the crop box: as requested, or scaled down to fit the source (keeping its aspect ratio)
	 * when upscaling is not allowed.
	 */
	public function getCropBox(Dimensions $source): Dimensions
	{
		$width = Helpers::getPositiveInt($this->resizerParams->getWidth());
		$height = Helpers::getPositiveInt($this->resizerParams->getHeight());

		if (
			$this->allowUpscale
			|| $this->resizerParams->getForceDimensions() // forced dimensions are always respected
			|| ($width <= $source->getWidth() && $height <= $source->getHeight())
		) {
			return new Dimensions($width, $height);
		}

		$factor = min($source->getWidth() / $width, $source->getHeight() / $height);

		return new Dimensions(
			min($source->getWidth(), max(1, (int) round($width * $factor))),
			min($source->getHeight(), max(1, (int) round($height * $factor))),
		);
	}


	/**
	 * Dimensions of the final thumbnail: the crop box for a crop, otherwise the resized image.
	 */
	public function getOutputDimensions(Dimensions $source): Dimensions
	{
		$resized = $this->calculateNewSize($source); // validates the params too

		if (!$this->resizerParams->isCrop() || $this->resizerParams->getForceDimensions()) {
			return $resized;
		}

		return $this->getCropBox($source);
	}


	/**
	 * Minimum size (a square side) the source has to be decoded at, so a JPEG decoder can scale it down
	 * while decoding (libjpeg DCT scaling, ImageMagick "jpeg:size") - much faster for large photos.
	 * Twice the output size keeps the quality of the final resize. Both orientations are considered,
	 * the EXIF orientation is not known before decoding. Null when no reduction is possible.
	 */
	public function getDecodeSizeHint(Dimensions $source): ?int
	{
		$longest = 0;

		foreach ([$source, new Dimensions($source->getHeight(), $source->getWidth())] as $dimensions) {
			$output = $this->calculateNewSize($dimensions);
			$longest = max($longest, $output->getWidth(), $output->getHeight());
		}

		$side = 2 * $longest;

		// libjpeg scales by 1/2 at least
		return $side * 2 <= min($source->getWidth(), $source->getHeight()) ? $side : null;
	}


	/**
	 * @param Dimensions $resized the image scaled by calculateNewSize()
	 * @param Dimensions|null $cropBox from getCropBox(), the requested size by default
	 */
	public function getCropPoint(Dimensions $resized, ?Dimensions $cropBox = null): Point
	{
		$overflowX = max(0, $resized->getWidth() - ($cropBox?->getWidth() ?? (int) $this->resizerParams->getWidth()));
		$overflowY = max(0, $resized->getHeight() - ($cropBox?->getHeight() ?? (int) $this->resizerParams->getHeight()));

		$x = match ($this->resizerParams->getHorizontal()) {
			'c' => intdiv($overflowX, 2),
			'r' => $overflowX,
			default => 0,
		};

		$y = match ($this->resizerParams->getVertical()) {
			'c' => intdiv($overflowY, 2),
			'b' => $overflowY,
			default => 0,
		};

		return new Point($x, $y);
	}
}
