<?php
declare(strict_types=1);

namespace Nelson\Resizer;

class ResizerParams
{
	public function __construct(
		private bool $ifresize,
		private ?string $horizontal,
		private ?string $vertical,
		private bool $forceDimensions,
		private ?string $horizontalMargin,
		private ?string $verticalMargin,
		private ?int $width,
		private ?int $height
	) {
	}


	public function isIfresize(): bool
	{
		return $this->ifresize;
	}


	public function getHorizontal(): ?string
	{
		return $this->horizontal;
	}


	public function getVertical(): ?string
	{
		return $this->vertical;
	}


	public function getForceDimensions(): bool
	{
		return $this->forceDimensions;
	}


	public function getHorizontalMargin(): ?string
	{
		return $this->horizontalMargin;
	}


	public function getVerticalMargin(): ?string
	{
		return $this->verticalMargin;
	}


	public function getWidth(): ?int
	{
		return $this->width;
	}


	public function hasWidth(): bool
	{
		return $this->width !== null;
	}


	public function getHeight(): ?int
	{
		return $this->height;
	}


	public function hasHeight(): bool
	{
		return $this->height !== null;
	}


	public function hasBothDimensions(): bool
	{
		return $this->width !== null && $this->height !== null;
	}


	public function hasOneDimension(): bool
	{
		return $this->width !== null || $this->height !== null;
	}


	public function hasNoDimensions(): bool
	{
		return $this->width === null && $this->height === null;
	}


	public function isCrop(): bool
	{
		return
			($this->horizontal !== null && strlen($this->horizontal) === 1)
			&&
			($this->vertical !== null && strlen($this->vertical) === 1)
		;
	}
}
