# Installation

1. Composer: `composer require nelson/resizer`
2. Register:
	``` neon
	extensions:
		resizer: Nelson\Resizer\DI\ResizerExtension
	```
3. Config:

	This is the bare minimum required:
	``` neon
	resizer:
 		library: 'Imagick' # Imagick|Gmagick|Gd
		wwwDir: %wwwDir%
		tempDir: %tempDir%
	```

	Other options with their defaults:
	``` neon
	resizer:
		interlace: true # for progressive JPEGs
 		strip: true # removes image metadata, color profiles etc.
		cache: '/resizer/'
		qualityAvif: 70 # 0 - 100
		qualityWebp: 70 # 0 - 100
		qualityJpeg: 70 # 0 - 100
		compressionPng: 9 # 0 - 9
		upgradeJpg2Webp: true # automatically convert JPEGs to WEBP when the format is supported by the browser & server
		upgradeJpg2Avif: true # automatically convert JPEGs to AVIF when the format is supported by the browser & server
		upgradePng2Webp: true # automatically convert PNGs to WEBP when the format is supported by the browser & server
		upgradePng2Avif: true # automatically convert PNGs to AVIF when the format is supported by the browser & server
	```

There is also an upgrade/downgrade logic for AVIF & WEBP. Most preferred is the original AVIF/WEBP, then each other, finally JPEG.

AVIF is preferred over WEBP in case both upgrades are enabled.

# Usage

Order of parameters is dependant on the router, the default is:

1. Dimensions & modifiers. `string`
2. Image file. `string`
3. Format. `string`

## Dimensions & modifiers

Syntax:
	- `[ifresize-][[cropX]width]x[[cropY]height][forceDimensions][-quality]`

### Dimensions:

- `100x100` - width and height must be equal or less, resized according to AR.
- `x100` - height must be equal or less.
- `100x` - width must be equal or less.
- `100x50!` - dimensions are forced without respecting ifresize and AR.
- Cropping:
	- width: `l` - left, `c` - center, `r` - right.
	- height: `t` - top, `c` - center, `b` - bottom.
 	- Example: `c100xc100` 	

### Modifiers:

- `ifresize`
	- Example: `ifresize-100x200`
	- If the image is smaller than the desired width and/or height, image will not be enlarged.
- Quality
  	- Example: `x-q50` - sets just the quality without any resize.
  	- For WEBP/AVIF quality set to `100` means lossless compression.
 
## Format:

- The format parameter can be used to switch between image file formats, e.g. `<source srcset="">` in `<picture>` tag for converting jpegs to WEBP/AVIF.

# Relative and absolute URLs

Relative:

- `<img src="{rlink 'test.jpg', '200x100'}">`

Absolute, uses diffent tag:

- `<img src="{rlinkabs 'test.jpg', 'l400xc200'}">`
- `<a href="{rlinkabs 'test.jpg', 'l400xc200'}" target="_blank">Link to image</a>`
