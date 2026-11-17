<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Imagine\Image\Point;
use Nelson\Resizer\Exceptions\IncompatibleResizerParamsException;

final class Geometry
{
	private ResizerParams $resizerParams;


	public function __construct(?string $rawParams)
	{
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
			$cropWidth = Helpers::getPositiveInt($rp->getWidth());
			$cropHeight = Helpers::getPositiveInt($rp->getHeight());
			$scale = max($cropWidth / $input->getWidth(), $cropHeight / $input->getHeight());

			return new Dimensions(
				max($cropWidth, (int) round($input->getWidth() * $scale)),
				max($cropHeight, (int) round($input->getHeight() * $scale)),
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

		return $output;
	}


	public function getCropPoint(Dimensions $source): Point
	{
		$overflowX = max(0, $source->getWidth() - (int) $this->resizerParams->getWidth());
		$overflowY = max(0, $source->getHeight() - (int) $this->resizerParams->getHeight());

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
