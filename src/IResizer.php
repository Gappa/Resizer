<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nette\Utils\Html;

interface IResizer
{
	public function process(
		string $path,
		?string $params,
		bool $useAssets,
		?string $format
	): ?array;
}
