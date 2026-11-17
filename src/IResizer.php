<?php
declare(strict_types=1);

namespace Nelson\Resizer;

interface IResizer
{
	public function process(string $path, ?string $params, ?string $format = null): string;

	public function getSourceImagePath(string $path): string;

	public function getThumbnailPath(string $path, ?string $params, ?string $format = null): string;

	public function thumbnailExists(string $path): bool;

	/**
	 * Dimensions of the thumbnail without generating it, null when the source or params are invalid
	 * or the source dimensions can't be read.
	 */
	public function getOutputDimensions(string $path, ?string $params): ?Dimensions;

}
