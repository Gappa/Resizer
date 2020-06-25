<?php
declare(strict_types=1);

namespace Nelson\Resizer;

interface IResizer
{
	public function process(string $path, ?string $params, ?string $format): ?string;

	public function getSourceImagePath(string $path): string;
}
