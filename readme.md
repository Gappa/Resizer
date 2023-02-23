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

Order of parameters:

1. Image file. `string`
2. Dimensions. `string`
3. Format. `string`

## Dimensions

Allowed variants:

- `100x100` - width and height must be equal or less, resized according to AR.
- `x100` - height must be equal or less.
- `100x` - width must be equal or less.

Modificators:

- Cropping:
	- width: `l` - left, `c` - center, `r` - right.
	- height: `t` - top, `c` - center, `b` - bottom.
- Conditional resize:
	- `ifresize-100x200` - do not resize if the source is smaller.
- Force dimensions:
	- `100x200!` - resize to these dimensions, regardless of AR.

Formats:

- The format parameter can be used to switch between image file formats, e.g. `<source srcset="">` in `<picture>` tag for converting jpegs to WEBP/AVIF.

## Types

Insert the src manually:

- `<img src="{rlink 'test.jpg', '200x100'}">`

Links can also be absolute, this uses separate tag:

- `<img src="{rlinkabs 'test.jpg', 'l400xc200'}">`
- `<a href="{rlinkabs 'test.jpg', 'l400xc200'}" target="_blank">Link to image</a>`
