<?php
declare(strict_types=1);

namespace Nelson\Resizer;

interface IResizer
{
	public function process(string $path, ?string $params, ?string $format = null): string;

	public function getSourceImagePath(string $path): string;

	public function canUpgradeJpg2Webp(): bool;

	public function isWebpSupportedByServer(): bool;
}
