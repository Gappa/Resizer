<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nette\Utils\Html;

interface IResizer
{
	/** @deprecated */
	public function resize(
		string $path,
		string $irParams = null,
		string $alt = null,
		string $title = null,
		string $class = null,
		string $id = null,
		bool $useAssets = false
	): Html;

	public function process(
		string $path,
		?string $params,
		bool $useAssets,
		?string $format
	): ?array;
}
