<?php
declare(strict_types=1);

namespace Nelson\Resizer;

class ResizerParams
{

	/**
	 * @param positive-int|null $width
	 * @param positive-int|null $height
	 * @param int<0,100>|null $quality
	 */
	public function __construct(
		private readonly bool $ifresize,
		private readonly ?string $horizontal,
		private readonly ?string $vertical,
		private readonly bool $forceDimensions,
		private readonly ?string $horizontalMargin,
		private readonly ?string $verticalMargin,
		private readonly ?int $width,
		private readonly ?int $height,
		private readonly ?int $quality,
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


	/** @return positive-int|null */
	public function getWidth(): ?int
	{
		return $this->width;
	}


	public function hasWidth(): bool
	{
		return $this->width !== null;
	}


	/** @return int<0,100>|null */
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


	/** @return positive-int|null */
	public function getQuality(): ?int
	{
		return $this->quality;
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
