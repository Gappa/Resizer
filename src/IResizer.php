<?php

/**
 * @package    Nelson
 * @subpackage Resizer
 * @author     Pavel Linhart <pavel.linhart@minion.cz>
 * @copyright  2017 Minion Interactive s.r.o.
 */

namespace Nelson\Resizer;

use Nette\Utils\Html;

interface IResizer
{

	/**
	 * @param  string $path
	 * @param  string|NULL $irParams
	 * @param  string|NULL $alt
	 * @param  string|NULL $title
	 * @param  string|NULL $class
	 * @param  string|NULL $id
	 * @return Html
	 */
	public function resize(
		$path,
		$irParams = null,
		$alt = null,
		$title = null,
		$class = null,
		$id = null
	);

	/**
	 * @param  string $path
	 * @param  array|NULL $params
	 * @param  bool $useAssets
	 * @return array Contains information about the resized image
	 */
	public function send($path, $params, $useAssets);
}
