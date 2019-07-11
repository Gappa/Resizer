# Installation

1. Composer: `composer require nelson/resizer`
2. Register:
	```
	extensions:
		resizer: Nelson\Resizer\DI\ResizerExtension
	```
3. Config:

	These are the default values. 
	Empty values are required.
	```
	resizer:
   	paths:
   		wwwDir: '%wwwDir%'
   		storage: 
   		assets: 
   		cache: '%wwwDir%/cache/images/'
   	library: 'Imagick'
   	cacheNS: 'resizer'
   	absoluteUrls: false
   	interlace: true
   		jpeg_quality: 75
   		webp_quality: 75
   		png_compression_level: 9
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

## Usage

Insert the src manually:

- `<img src="{rlink 'test.jpg', '200x100'}">`

Or there are two new macros - `rsrc` and `rhref`:

- `<img n:rsrc="'test.jpg', 'l400xc200'">`
- `<a n:rhref="'test.jpg', 'l400xc200'" target="_blank">Link to image</a>`

Links can also be absolute, the usage is just like everywhere in Nette - `//`.

Beware of the usage in macro, the slashes need to be outside of the string:

- `<img n:rsrc="//'test.jpg', 'l400xc200'">`
- `<a n:rhref="//'test.jpg', 'l400xc200'" target="_blank">Link to image</a>`
