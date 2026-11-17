<?php
declare(strict_types=1);

namespace Nelson\Resizer\Image;

use Imagine\Image\ImageInterface;
use Imagine\Imagick\Image as ImagickImage;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nette\Utils\FileSystem;
use RuntimeException;

/**
 * Encodes and writes a thumbnail: quality and encoder options, metadata stripping, progressive JPEG,
 * animations; atomically (a temporary file renamed).
 * @internal
 */
final class ThumbnailWriter
{
	public function __construct(
		private readonly ResizerConfig $config,
	) {
	}


	/**
	 * @param string $path the format is derived from its extension
	 * @param int<0, 100>|null $quality from the params, null for the configured defaults
	 */
	public function write(ImageInterface $thumbnail, string $path, ?int $quality): void
	{
		$format = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		$options = $this->config->getOptions($quality);

		if (ImageLoader::isAnimated($thumbnail) && $thumbnail instanceof ImagickImage) {
			// Imagine would flatten the frames into one image otherwise; the frame delays are kept (delay null)
			$imagick = $thumbnail->getImagick();
			$imagick->setFirstIterator();
			$options += [
				'animated' => true,
				'format' => $format,
				'animated.loops' => max(0, $imagick->getImageIterations()),
			];
		}

		if ($this->config->isStrip()) {
			// remove all comments & metadata
			$thumbnail->strip();
		}

		// progressive JPEG; PNG interlacing (Adam7) makes files bigger, WEBP/AVIF/JXL ignore it
		if ($this->config->isInterlace() && in_array($format, Resizer::FORMAT_SUFFIXES_JPG, true)) {
			$thumbnail->interlace(ImageInterface::INTERLACE_LINE);
		}

		// encoder speed/effort, Imagine has no options for it
		if ($thumbnail instanceof ImagickImage) {
			foreach ($this->config->getImagickEncoderOptions($format) as $option => $value) {
				$thumbnail->getImagick()->setOption($option, $value);
			}
		}

		FileSystem::createDir(dirname($path));

		// Write to a temporary file first and rename it, so a partially written thumbnail is never served
		// (in static mode the file may be served directly by the web server as soon as it exists).
		// The temporary name keeps the extension, Imagine derives the output format from it.
		$tempPath = dirname($path) . DIRECTORY_SEPARATOR
			. '.tmp-' . bin2hex(random_bytes(6)) . '-' . basename($path);

		try {
			$thumbnail->save($tempPath, $options);
			// plain rename() is atomic and replaces the target (FileSystem::rename() deletes it first)
			if (!@rename($tempPath, $path)) {
				throw new RuntimeException(sprintf("Unable to write thumbnail '%s'.", $path));
			}
		} finally {
			if (is_file($tempPath)) {
				@unlink($tempPath);
			}
		}
	}
}
