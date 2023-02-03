<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Imagine\Image\Point;
use Nelson\Resizer\Exceptions\IncompatibleResizerParamsException;
use Nette\SmartObject;

final class Geometry
{
	use SmartObject;

	private ResizerParams $resizerParams;


	public function __construct(string $rawParams)
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

		$output = new Dimensions(
			Helpers::getPositiveInt((int) round($input->getWidth() * $outputRatio)),
			Helpers::getPositiveInt((int) round($input->getHeight() * $outputRatio)),
		);

		// Need to scale up to make room for the crop again
		if ($rp->isCrop()) {
			if ($output->getWidth() === $rp->getWidth()) {
				$width = $output->getWidth() * $rp->getHeight() / $output->getHeight();
				$height = Helpers::getPositiveInt($rp->getHeight());
			} else {
				$width = Helpers::getPositiveInt($rp->getWidth());
				$height = $output->getHeight() * $rp->getWidth() / $output->getWidth();
			}

			$output = new Dimensions(
				Helpers::getPositiveInt((int) round($width)),
				Helpers::getPositiveInt((int) round($height)),
			);
		}

		// If the image is smaller than the desired size, hijack the process
		if ($rp->isIfresize()
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
		switch ($this->resizerParams->getHorizontal()) {
			case 'c':
				$x = ($source->getWidth() - $this->resizerParams->getWidth()) / 2;
				break;

			case 'r':
				$x = $source->getWidth() - $this->resizerParams->getWidth();
				break;

			case 'l':
			default:
				$x = 0;
				break;
		}

		switch ($this->resizerParams->getVertical()) {
			case 'c':
				$y = ($source->getHeight() - $this->resizerParams->getHeight()) / 2;
				break;

			case 'b':
				$y = $source->getHeight() - $this->resizerParams->getHeight();
				break;

			case 't':
			default:
				$y = 0;
				break;
		}

		return new Point((int) $x, (int) $y);
	}
}
