<?php

namespace Nelson\Resizer;

use Nette\Utils\Html;

interface IResizer
{

	public function resize(
		string $path,
		string $irParams = null,
		string $alt = null,
		string $title = null,
		string $class = null,
		string $id = null,
		bool $useAssets = false
	): Html;


	public function send(
		string $path,
		?string $params,
		bool $useAssets
	): ?array;
}
