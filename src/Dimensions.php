<?php

namespace Nelson\Resizer;

class Dimensions
{

	public function __construct(
		private int $width,
		private int $height
	)
	{
	}


	public function getWidth(): int
	{
		return $this->width;
	}


	public function getHeight(): int
	{
		return $this->height;
	}


	public function getRatio(): float
	{
		return $this->width / $this->height;
	}

}
