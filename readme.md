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
		publicCacheDir: null # e.g. %wwwDir%/cache/ir - enables the static mode, see below
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

# Static mode

By default every thumbnail request goes through PHP (`/ir/...`), even when the thumbnail is already cached.
With `publicCacheDir` set (a subdirectory of `wwwDir`), thumbnails are stored there and `{rlink}` / `{rlinkabs}`
link the existing ones directly, so the web server serves them as static files:

``` neon
resizer:
	publicCacheDir: %wwwDir%/cache/ir
```

- Thumbnail exists: `{rlink 'storage/photo.jpg', '200x100'}` → `/cache/ir/storage/photo.jpg/200x100.webp`
- Thumbnail does not exist yet: → `/ir/200x100/storage/photo.jpg?format=webp`, the request generates it into `publicCacheDir`
  and the next render of the page links the static file.

The output format is resolved when the link is generated, not from the image request's `Accept` header
(the URL of a static file can't depend on it). Browser support of WEBP/AVIF is assumed, the format depends only on
the server support and the `upgrade*` options.

Notes:

- With `Nette\Application\LinkGenerator` as `uiControl` (e.g. e-mails) links are always absolute; the static URL uses
  the base URL of the current HTTP request (in CLI the one set by the console), the same as LinkGenerator by default.
- In PHP code use `Nelson\Resizer\ResizerLinkGenerator::link($presenterOrLinkGenerator, $file, $params)`
  (static URL when the thumbnail exists, `/ir/` link otherwise) or `getStaticUrl()`.
- `ResizerLinkGenerator::rewriteHtml($html)` rewrites `/ir/...` URLs in attribute values of rendered HTML
  (e.g. rich text content with stored resizer links): static URL when the thumbnail exists, `?format=` added otherwise.
  Call it on the rendered page, e.g. in an overridden `Presenter::sendTemplate()`.
  With `fillFormat: false` URLs of missing thumbnails are left as they are (for edited content).
- `ResizerLinkGenerator::restoreDynamicUrls($html)` is the reverse (static URL → `/ir/<params>/<file>`), use it
  before storing edited HTML (e.g. a rich text editor showing static URLs), so the content doesn't depend on the public cache.
- Thumbnails are written to a temporary file and renamed, a partially written file is never served.
- The public directory is never cleaned automatically, delete its content when needed (e.g. on deploy).

# Relative and absolute URLs

Relative:

- `<img src="{rlink 'test.jpg', '200x100'}">`

Absolute, uses diffent tag:

- `<img src="{rlinkabs 'test.jpg', 'l400xc200'}">`
- `<a href="{rlinkabs 'test.jpg', 'l400xc200'}" target="_blank">Link to image</a>`
