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
		absoluteUrls: false
		interlace: true # for progressive JPEGs
		cache: '/resizer/'
		qualityWebp: 70 # 0 - 100
		qualityJpeg: 70 # 0 - 100
		compressionPng: 9 # 0 - 9
	```	

# Usage

Order of parameters:

1. Image file. `string`
2. Dimensions. `string`
3. From assets? `bool`
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

- The fourth parameter can be used to switch between image file formats, e.g. `<source srcset="">` in `<picture>` tag for converting jpegs to webps.

## Types

Insert the src manually:

- `<img src="{rlink 'test.jpg', '200x100'}">`

Links can also be absolute, the usage is just like everywhere in Nette - `//`.

Beware of the usage in macro, the slashes need to be outside of the string:

- `<img src="{rlink //'test.jpg', 'l400xc200'}">`
- `<a href="{rlink //'test.jpg', 'l400xc200'}" target="_blank">Link to image</a>`
