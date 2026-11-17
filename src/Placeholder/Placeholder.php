<?php
declare(strict_types=1);

namespace Nelson\Resizer\Placeholder;

use Nelson\Resizer\Dimensions;

/**
 * Preview of a thumbnail shown while it is (lazy) loading: its dominant color and a tiny version of it.
 */
final class Placeholder
{
	/** A plain background color (a few bytes) */
	public const string COLOR = 'color';

	/** A blurred tiny image over the color (≈ 1 KB inline) */
	public const string BLUR = 'blur';

	public const array MODES = [self::COLOR, self::BLUR];


	/**
	 * @param string $color dominant color "#rrggbb"
	 * @param string $imageDataUri the tiny image as a data URI
	 * @param bool $transparent the image has transparent parts - a background would show through them
	 */
	public function __construct(
		public readonly string $color,
		public readonly string $imageDataUri,
		public readonly Dimensions $size,
		public readonly bool $transparent,
	) {
	}


	/**
	 * Inline CSS for the <img> (the loaded image covers the background). Empty for transparent images.
	 * @param self::COLOR|self::BLUR $mode
	 */
	public function getStyle(string $mode = self::COLOR): string
	{
		if ($this->transparent) {
			return '';
		}

		if ($mode === self::BLUR) {
			return sprintf('background:%s url("%s") 0 0/100%% 100%% no-repeat', $this->color, $this->getBlurredImageDataUri());
		}

		return 'background-color:' . $this->color;
	}


	/**
	 * SVG with the tiny image blurred by feGaussianBlur - a smooth blur without JavaScript at any size;
	 * the alpha transfer keeps the edges opaque (no blurred-out border).
	 */
	public function getBlurredImageDataUri(): string
	{
		$svg = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none">'
			. '<filter id="b" color-interpolation-filters="sRGB"><feGaussianBlur stdDeviation="1"/>'
			. '<feComponentTransfer><feFuncA type="discrete" tableValues="1 1"/></feComponentTransfer></filter>'
			. '<image width="%1$d" height="%2$d" preserveAspectRatio="none" filter="url(#b)" href="%3$s"/></svg>',
			$this->size->getWidth(),
			$this->size->getHeight(),
			$this->imageDataUri,
		);

		return 'data:image/svg+xml;base64,' . base64_encode($svg);
	}
}
