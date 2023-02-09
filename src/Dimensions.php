<?php
declare(strict_types=1);

namespace Nelson\Resizer;

class Dimensions
{
	/**
	 * @param positive-int $width
	 * @param positive-int $height
	 */
	public function __construct(
		private readonly int $width,
		private readonly int $height
	) {
	}


	/** @return positive-int */
	public function getWidth(): int
	{
		return $this->width;
	}


	/** @return positive-int */
	public function getHeight(): int
	{
		return $this->height;
	}


	public function getRatio(): float
	{
		return $this->width / $this->height;
	}
}
