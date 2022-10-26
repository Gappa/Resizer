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
		wwwDir: %wwwDir%
		tempDir: %tempDir%
	```

	Other options with their defaults:
	``` neon
	resizer:
		library: 'Imagick' # Imagick|Gmagick|Gd
		interlace: true # for progressive JPEGs
		cache: '/resizer/'
		qualityWebp: 70 # 0 - 100
		qualityJpeg: 70 # 0 - 100
		compressionPng: 9 # 0 - 9
		upgradeJpg2Webp: true # automatically convert JPEGs to WEBP when the format is supported by the browser & server
	```

# Usage

Order of parameters:

1. Image file. `string`
2. Dimensions. `string`
4. Format. `string`

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

- The format parameter can be used to switch between image file formats, e.g. `<source srcset="">` in `<picture>` tag for converting jpegs to webps.

## Types

Insert the src manually:

- `<img src="{rlink 'test.jpg', '200x100'}">`

Links can also be absolute, this uses separate tag:

- `<img src="{rlinkabs 'test.jpg', 'l400xc200'}">`
- `<a href="{rlinkabs 'test.jpg', 'l400xc200'}" target="_blank">Link to image</a>`
