# Requirements

- PHP 8.5+, Nette 3.3+, Latte 3.1+
- ext-fileinfo and one of ext-imagick (recommended), ext-gd, ext-gmagick
- ext-exif recommended with GD/Gmagick: photos are rotated by their EXIF orientation (Imagick reads it on its own)

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
		interlace: true # progressive JPEGs (other formats are never interlaced, it makes PNGs bigger)
 		strip: true # removes image metadata, color profiles etc.
		cache: '/resizer/'
		publicCacheDir: null # e.g. %wwwDir%/cache/ir - enables the static mode, see below
		qualityAvif: 70 # 0 - 100
		qualityWebp: 70 # 0 - 100
		qualityJpeg: 70 # 0 - 100
		qualityJxl: 70 # 0 - 100 (Imagine clamps it to 9 - 99)
		compressionPng: 9 # 0 - 9
		avifSpeed: null # 0 - 10, higher = faster encoding, slightly bigger files (Imagick only, null = library default)
		jxlEffort: null # 1 - 9, lower = faster encoding, slightly bigger files (Imagick only, null = 7)
		webpMethod: null # 0 - 6, higher = slower encoding, smaller files (Imagick only, null = 4)
		maxWidth: 4000 # max. requested width, larger requests get HTTP 400
		maxHeight: 4000 # max. requested height; the output area is limited to maxWidth * maxHeight
		allowUpscale: false # thumbnails are never bigger than the source (forced dimensions "!" excepted), see below
		placeholderBudget: 10 # max. new placeholders generated per request while rendering, see Placeholders
		sendfile: null # x-sendfile | x-accel-redirect - the web server sends the files, see below
		sendfileInternalUri: null # x-accel-redirect: internal nginx location of the cache directory, e.g. /_resizer/
		secret: null # at least 32 characters, enables signed URLs, see below
		protectedMaxAge: 0 # seconds browsers may cache an allowed protected image, see Restricting access
		upgradeJpg2Webp: true # automatically convert JPEGs to WEBP when the format is supported by the browser & server
		upgradeJpg2Avif: true # automatically convert JPEGs to AVIF when the format is supported by the browser & server
		upgradePng2Webp: true # automatically convert PNGs to WEBP when the format is supported by the browser & server
		upgradePng2Avif: true # automatically convert PNGs to AVIF when the format is supported by the browser & server
		upgradeJpg2Jxl: false # automatically convert JPEGs to JPEG XL when the format is supported by the browser & server
		upgradePng2Jxl: false # automatically convert PNGs to JPEG XL when the format is supported by the browser & server
		upgradeGif2Webp: true # automatically convert GIFs (animations too) to WEBP when supported by the browser & server
		maxAnimationPixels: 25000000 # frames × width × height; bigger animations are reduced to the first frame
		formatPriority: [jxl, webp, avif] # order of the modern formats for upgrades and fallbacks
	```

## Output format

Without an explicit format the output format is negotiated by the `Accept` header of the request
(only explicitly listed types count, `image/*` does not) and the formats the server can write:

- JPEG/PNG source: upgraded to the first format of `formatPriority` the browser accepts and the `upgrade*` option
  is enabled for, otherwise it keeps its format.
- WEBP/AVIF/JPEG XL source: keeps its format when the browser accepts it, otherwise the first format
  of `formatPriority` the browser accepts, finally JPEG.
- GIF keeps its format.

A format missing in `formatPriority` is never used for upgrades or fallbacks (an explicit format still works).
AVIF is last by default, its encoding is slow - with WEBP before AVIF, JPEG/PNG are practically never upgraded
to AVIF (every browser with AVIF support accepts WEBP). Use `[avif, webp]` for the order of 8.x.

## Encoding speed

AVIF encoding is slow with the default settings. With Imagick, `avifSpeed: 8` typically makes it several times faster
for a few percent bigger files - measure on your images, then AVIF may deserve a better place in `formatPriority`.
`jxlEffort` and `webpMethod` work the same way. GD and Gmagick use their defaults.

With Imagick, large JPEG sources are scaled down already while decoding (libjpeg DCT scaling, `jpeg:size`
with twice the output size, so the quality of the final resize is kept) - typically several times faster
and with a fraction of the memory for photos from cameras and phones.

Concurrent requests for the same missing thumbnail (a page full of new images, a CDN fetching in parallel) encode it
only once: the others wait for a lock (in `tempDir/resizer-locks`) and use the result.

## Animations

With Imagick, animated GIF and WEBP sources (and other multi-frame formats) stay animated when the output
is GIF or WEBP - a GIF is upgraded to an animated WEBP by default (`upgradeGif2Webp`), usually much smaller.
The frame delays and the loop count are kept.

GIF frames often cover only the changed part of the image. The frames are therefore first composed to full frames
as displayed (`coalesce`), resized/cropped, and optimized back to deltas - the result looks the same as the source.

The first frame is used when the output format can't hold an animation (JPEG, PNG, AVIF, JPEG XL),
when the animation has more pixels than `maxAnimationPixels` (all frames together - decoding needs the memory),
and always with GD. Animations are detected by the number of frames ImageMagick decodes, not by guessing from the file.

## JPEG XL

Supported by Safari 17+, Chrome 155+ and Firefox 158+ (not by Edge as of 11/2026). Requirements on the server:

- `library: Imagick` with ImageMagick built with libjxl (`Imagick::queryFormats('JXL')`). GD can't write JPEG XL,
  Imagine's Gmagick driver does not support its options.
- `ext-fileinfo` with a libmagic recognizing `image/jxl` for JXL source images (the one bundled with PHP 8.5 does).

The upgrades are disabled by default (`upgradeJpg2Jxl`, `upgradePng2Jxl`), each format is another variant
of every thumbnail in the cache. Quality `-q100` means lossless.

In static mode the format of the link can't depend on the browser, JPEG XL is therefore never chosen
automatically there. Use an explicit format in `<picture>`:

``` latte
<picture>
	<source type="image/jxl" srcset="{rlink 'photo.jpg', '400x', 'jxl'}">
	<img src="{rlink 'photo.jpg', '400x'}" alt="">
</picture>
```

The dynamic responses send `Vary: Accept` - a CDN in front of the resizer must respect it (or normalize the `Accept`
header), otherwise it serves JPEG XL to browsers without its support.

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
	- With `allowUpscale: false` (the default) no image is enlarged, `ifresize` matters only with `allowUpscale: true`.

### Upscaling

With `allowUpscale: false` (the default) a thumbnail is never bigger than its source - an enlarged image is a bigger
file with no added detail. A smaller source is kept at its size, a crop box bigger than the source is scaled down
keeping its aspect ratio (`c800xc1000` on a 500x500 image gives 400x500). Forced dimensions (`100x50!`) are always
respected. `{rsrcset}`, `{rpicture}` and `rsize()` use the real dimensions, so `width`/`height` attributes match.

The thumbnail name does not depend on the option - delete the cache after changing it.
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
- The public directory is never cleaned automatically, use `resizer:clean` (see Cleaning the cache).
- A changed source image is regenerated on the next `/ir/` request, but an existing static file keeps being served
  until it is deleted - `resizer:clean` deletes such stale thumbnails.

# Cleaning the cache

Thumbnails are never deleted automatically. `Nelson\Resizer\CacheCleaner::clean()` deletes:

- orphaned thumbnails - the source image does not exist anymore,
- stale thumbnails - the source image is newer (in static mode the old file would be served until deleted),
- optionally thumbnails generated more than `maxAge` seconds ago (they are generated again on demand),
- leftover temporary and lock files of crashed processes, and empty directories.

Only files named like thumbnails (`<params>.<format>`) in the cache directory are deleted, other files are kept.
With symfony/console (e.g. [contributte/console](https://github.com/contributte/console)) the command is registered:

``` bash
php bin/console resizer:clean --dry-run       # only report
php bin/console resizer:clean --max-age=90    # also thumbnails older than 90 days
php bin/console resizer:clean -v              # list the deleted files
```

Run it e.g. daily by cron and after deploys that change or remove images.

# Sending files by the web server

By default the resize presenter sends the thumbnail by PHP. With `sendfile` the web server sends it, PHP only
sets the headers (`Content-Type`, `Cache-Control`, `ETag`, ...) - the PHP worker is free immediately.
A `304 Not Modified` is still answered by PHP.

Apache with mod_xsendfile (or lighttpd), an absolute path is sent:

``` neon
resizer:
	sendfile: x-sendfile
```

``` apache
XSendFile On
XSendFilePath /path/to/temp/resizer   # the cache directory (publicCacheDir in static mode)
```

nginx, an internal location aliased to the cache directory:

``` neon
resizer:
	sendfile: x-accel-redirect
	sendfileInternalUri: /_resizer/
```

``` nginx
location /_resizer/ {
	internal;
	alias /path/to/temp/resizer/;   # the cache directory (publicCacheDir in static mode)
}
```

# Signed URLs

With `secret` set, every `/ir/` URL must carry a signature (`?s=...`, HMAC-SHA256 of the file and params),
otherwise the presenter responds with HTTP 403. Nobody can then make the server generate thumbnails
with arbitrary params (CPU and disk usage).

``` neon
resizer:
	secret: %resizerSecret% # e.g. from the local config, never commit it
```

- `{rlink}`, `{rlinkabs}` and `ResizerLinkGenerator::link()` sign the links automatically
  (in static mode only the links to not yet generated thumbnails, static files need no signature).
- The format is not signed (only a few supported values are possible), params `null` and `auto` are equal.
- URLs stored in content (rich text) have no signature: render them through `ResizerLinkGenerator::rewriteHtml()`,
  which adds (or fixes) the signature, also without static mode. `restoreDynamicUrls()` signs the restored URLs.
- Changing the secret invalidates all dynamic URLs in the wild (cached pages, e-mails), static files keep working.
- Generate the secret e.g. with `php -r "echo bin2hex(random_bytes(32));"`.

# Restricting access

Images can be restricted e.g. to logged-in users by access policies - services implementing
`Nelson\Resizer\Access\AccessPolicy`, found by type (no config needed, any number of them):

``` php
use Nelson\Resizer\Access\AccessDecision;
use Nelson\Resizer\Access\AccessPolicy;

final class ArchiveAccessPolicy implements AccessPolicy
{
	public function __construct(
		private readonly Nette\Security\User $user,
	) {
	}


	// must not depend on the user: it decides where the thumbnails are cached
	public function protects(string $file): bool
	{
		return str_starts_with($file, 'storage/Archive_Document/');
	}


	// called for protected files only
	public function decide(string $file): AccessDecision
	{
		return $this->user->isLoggedIn()
			? AccessDecision::allow()
			: AccessDecision::replace('assets/img/locked.png');
	}
}
```

``` neon
services:
	- ArchiveAccessPolicy
```

- `AccessDecision::allow()` - the image is served with `Cache-Control: private` (never stored by CDNs or proxies),
  browsers revalidate it every time by default (a cheap 304), so a logout takes effect immediately.
  `protectedMaxAge` lets browsers keep it for the given number of seconds.
- `AccessDecision::deny()` - HTTP 404 (does not reveal the image exists), `deny(403)` for HTTP 403.
- `AccessDecision::replace($file)` - another image (e.g. a lock icon, or random placeholders on a dev machine without
  the real files) is served instead, resized with the same params, so the layout is kept. It is not checked
  by the policies and its signature is not needed. Revalidated every time, the real image is shown right after a login.
- Paths are relative to `wwwDir` without a leading slash, as requested. Non-canonical paths (`a//b`, `./a`, `..`)
  are refused by the resizer, so comparing prefixes is safe. Compare case-sensitively.
- When more policies protect a file, the most restrictive decision wins (deny > replace > allow).
- Links do not change (`{rlink}` etc.), the presenter checks the access on every request. The session is kept open
  until the policies decide (the presenter closes it as soon as possible otherwise).
- Static mode: thumbnails of protected images are never written to `publicCacheDir` (the web server would serve them
  to anyone), they go to the non-public cache directory (`tempDir` + `cache`) and are always linked through the
  presenter. With `x-accel-redirect` PHP sends them (the internal location points to the public cache), `x-sendfile`
  works when `XSendFilePath` allows the non-public cache directory too. Placeholders of protected images are cached
  there as well.

**The source images must not be accessible directly.** The resizer reads them from the filesystem, but the web server
serves anything in `wwwDir` - deny the directories of protected images:

``` apache
# www/storage/Archive_Document/.htaccess
Require all denied
```

``` nginx
location /storage/Archive_Document/ {
	deny all;
}
```

Before protecting existing images in static mode, delete their thumbnails in `publicCacheDir` - existing files are
served by the web server without asking PHP. Images used outside the resizer (rich text with direct links to
the sources, structured data, ...) are not protected by the policies.

# Relative and absolute URLs

Relative:

- `<img src="{rlink 'test.jpg', '200x100'}">`

Absolute, uses diffent tag:

- `<img src="{rlinkabs 'test.jpg', 'l400xc200'}">`
- `<a href="{rlinkabs 'test.jpg', 'l400xc200'}" target="_blank">Link to image</a>`

# Responsive images

`{rsrcset}` prints the value of a `srcset` attribute with width descriptors. The widths are the real widths
of the thumbnails (read from the source header, EXIF orientation included), so e.g. `ifresize-` on a small image
does not produce duplicate entries:

``` latte
<img src="{rlink 'photo.jpg', '800x'}" srcset="{rsrcset 'photo.jpg', ['400x', '800x', '1200x']}" sizes="100vw" alt="">
```

`{rpicture}` prints a whole `<picture>`: a `<source>` for each modern format (`formatPriority` order) the server
supports and the upgrade of the source format is enabled for, and an `<img>` in the original format with `srcset`,
`width` and `height`. The last params are used for the `src`. Named arguments are attributes of the `<img>`
(`sizes` goes to the sources too, `true` = attribute without a value, `null`/`false` = omitted). The format of each
source is explicit, so it works in static mode too - the way to serve JPEG XL there:

``` latte
{rpicture 'photo.jpg', ['400x', '800x', '1200x'], sizes: '(min-width: 800px) 50vw, 100vw', alt: $title, loading: lazy}
```

`rsize()` returns the dimensions of a thumbnail without generating it, e.g. for `width`/`height` attributes
(prevents layout shifts); `null` when they can't be determined:

``` latte
{var $size = rsize('photo.jpg', 'c400xc300')}
<img src="{rlink 'photo.jpg', 'c400xc300'}" width={$size?->getWidth()} height={$size?->getHeight()} alt="">
```

In PHP: `ResizerLinkGenerator::srcset()`, `picture()` and `getDimensions()`.

## Placeholders

A placeholder is shown while a (lazy loaded) image is loading, as the background of the `<img>`:

- `color` - the dominant color of the image (a few bytes),
- `blur` - a blurred 16 px version of the image over the color (≈ 1 KB inline, an SVG blur - no JavaScript).
  Best for hero images and galleries.

``` latte
{rpicture 'photo.jpg', ['400x', '800x'], alt: $title, loading: lazy, placeholder: color}
{rpicture 'photo.jpg', ['c800xc450', 'c1600xc900'], alt: $title, placeholder: blur}

<img src="{rlink 'photo.jpg', '400x'}" n:attr="style: rplaceholder('photo.jpg', '400x', 'blur')" loading="lazy" alt="">
```

`style="{rplaceholder(...)}"` (the whole value is the expression) works as well. Do not mix the expression with
other CSS in the same attribute (`style="{rplaceholder(...)};border:0"`) - Latte then escapes it for CSS
(backslashes before `:`, `#`, ...), which breaks the declaration. Concatenate inside the expression instead:
`style="{rplaceholder('photo.jpg', '400x') . ';border:0'}"`.

The placeholder is made from a tiny thumbnail generated by the resizer (the same crop, EXIF orientation, the first
frame of animations) and cached like any thumbnail. Images with transparency get no placeholder (it would show
through). Rendering generates at most `placeholderBudget` (10) new placeholders per request, other images get
theirs in a next render - a page with many new images is not slowed down. Generate them when an image is uploaded:

``` php
// Nelson\Resizer\Placeholder\PlaceholderGenerator (DI service), with the params used in the templates
$placeholderGenerator->warmUp($file, 'c800xc450');
```

# Security

- Only `jpg`, `jpeg`, `jfif`, `png`, `gif`, `webp`, `avif` and `jxl` source images inside `wwwDir` are processed, the MIME type
  is checked too. Paths with `..` or `.` segments, empty segments (`a//b`), backslashes or control characters
  are refused. Symlinks inside `wwwDir` are followed.
- Access to images can be restricted by access policies, see Restricting access.
- Requested dimensions are limited by `maxWidth`/`maxHeight` (default 4000 px, max. 5 digits in the URL).
- Invalid requests get HTTP 400, missing images HTTP 404, without details of the error.
- Every params combination creates a new cached file. Enable signed URLs (`secret`), so only the params
  used by the application can be requested.
- With Imagick, a restrictive ImageMagick `policy.xml` (resource limits, disabled coders) is recommended as well.

# Upgrading to 9.1

- Image paths with `.` segments or empty segments (`./a.jpg`, `a//b.jpg`, `a.jpg/`) are refused (HTTP 400),
  a leading slash is still allowed.
- New optional constructor argument `AccessGuard` of `Resizer`, `ResizerLinkGenerator` and `ResizePresenter`
  (autowired in DI).

# Upgrading to 9.x

- PHP 8.5, Latte 3.1, nette/application 3.3 are required. Latte 2 is no longer declared as supported (it never worked).
- New options `maxWidth`/`maxHeight` (default 4000) - set them higher if you need larger thumbnails.
- Default `qualityWebp` and `qualityJpeg` are 70 as documented (the config schema used 75).
- The `|resize` Latte filter was removed (it pointed to a non-existent method).
- All resizer exceptions extend `Nelson\Resizer\Exceptions\ResizerException`. Unsupported format throws
  `UnsupportedFormatException`, too large dimensions `DimensionsTooLargeException`.
- `ResizePresenter` returns 400 for invalid requests (was 404) and sends `Vary: Accept` when the format is negotiated.
- Images are no longer enlarged by default (`allowUpscale: false`), set `allowUpscale: true` for the previous behavior
  and delete the thumbnail cache after the upgrade (existing upscaled thumbnails are kept otherwise).
- Crop dimensions may differ by 1px from 8.x (rounding fix), cached thumbnails do not need to be regenerated.
- `ResizerParams::isIfresize()` was renamed to `isIfResize()`.
- GIFs are converted to (animated) WEBP when the browser supports it (`upgradeGif2Webp: true`); animated GIFs
  were flattened into one broken image in 8.x, with Imagick they stay animated now.
- WEBP is preferred over AVIF now (`formatPriority: [jxl, webp, avif]`), set `formatPriority: [avif, webp]`
  for the previous behavior. JPEG XL support is new, disabled by default.
- `OutputFormat` accepts `Nette\Http\IRequest`; the `Accept` header is parsed properly (`q=0` means not supported).
- `ResizePresenter` sends the Content-Type by the extension of the thumbnail (libmagic may not know JPEG XL).
- `IResizer::getOutputDimensions()` was added (implement it in custom resizers).
- New `UrlSigner` service, a new constructor argument of `ResizerLinkGenerator` and `ResizePresenter` (autowired in DI).
  `ResizePresenter` also takes `ResizerConfig` (sendfile).
