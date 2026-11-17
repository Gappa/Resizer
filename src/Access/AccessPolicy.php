<?php
declare(strict_types=1);

namespace Nelson\Resizer\Access;

/**
 * Restricts access to images, e.g. to logged-in users. Register implementations as services,
 * the resizer finds them by type. Paths are relative to wwwDir without a leading slash
 * (e.g. "storage/Archive/uuid_photo.jpg"), as requested - compare them case-sensitively.
 */
interface AccessPolicy
{
	/**
	 * The policy protects the file. Must not depend on the current user or request: it decides
	 * where thumbnails are cached (protected ones never go to the public cache of the static mode)
	 * and it is called while rendering links too.
	 */
	public function protects(string $file): bool;


	/**
	 * Access of the current user to a protected file, called only when protects() returned true.
	 */
	public function decide(string $file): AccessDecision;
}
