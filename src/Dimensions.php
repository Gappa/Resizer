<?php

namespace Nelson\Resizer;

class Dimensions
{

	private int $width;
	private int $height;


	public function __construct(
		int $width,
		int $height
	)
	{
		$this->width = $width;
		$this->height = $height;
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
