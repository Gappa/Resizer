# Installation

1. Composer: `composer require nelson/resizer`
2. Register:
	```
	extensions:
		resizer: Nelson\Resizer\DI\ResizerExtension
		- Nepada\Bridges\PresenterMappingDI\PresenterMappingExtension
	```
3. Config:
	```
	resizer:
		paths:
			wwwDir: %wwwDir%
	```

# Usage

Order of parameters:

1. Image file. `string`
2. Dimensions. `string`
3. From assets? `bool`

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

## Types

### Internal

- Does insert computed dimensions, but runs in the same thread as the webpage. Will be very slow on first run and even might run out of memory and/or time out.
- `{=$image, $dimensions, $alt, $title, $id, $class, $useAssets|resizer}`

Only the first two arguments (`$image`, `$dimensions`) are required.

### External

Insert the src manually:

- `<img src="{plink :Base:Resizer:Resize: 'test.jpg', '200x100'}">`

Or there are two new macros - `rsrc` and `rhref`:

- `<img n:rsrc="'test.jpg', 'l400xc200'">`
- `<a n:rhref="'test.jpg', 'l400xc200'" target="_blank">Link to image</a>`

Links can also be absolute, the usage is just like everywhere in Nette - `//`.

Beware of the usage in macro, the slashes need to be outside of the string:

- `<img n:rsrc="//'test.jpg', 'l400xc200'">`
- `<a n:rhref="//'test.jpg', 'l400xc200'" target="_blank">Link to image</a>`
