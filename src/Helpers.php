<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use LogicException;
use Nette\StaticClass;

final class Helpers
{
	use StaticClass;


	/**
	 * @param int|null $int
	 * @return positive-int
	 */
	public static function getPositiveInt(int|null $int): int
	{
		if ($int === null || $int <= 0) {
			throw new LogicException(
				sprintf(
					'%s: Only positive integers are allowed',
					self::class,
				),
			);
		}

		return $int;
	}

}
