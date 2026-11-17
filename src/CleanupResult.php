<?php
declare(strict_types=1);

namespace Nelson\Resizer;

/**
 * Result of CacheCleaner::clean().
 */
final class CleanupResult
{
	/** Thumbnails whose source image does not exist anymore */
	public int $orphaned = 0;

	/** Thumbnails older than their source image */
	public int $stale = 0;

	/** Thumbnails older than the max age */
	public int $expired = 0;

	/** Leftover temporary and lock files */
	public int $temporary = 0;

	/** Thumbnails kept */
	public int $kept = 0;

	public int $freedBytes = 0;

	/** @var list<string> paths of the deleted (or to be deleted in a dry run) files */
	public array $deleted = [];


	public function __construct(
		public readonly bool $dryRun,
	) {
	}


	public function getDeletedCount(): int
	{
		return $this->orphaned + $this->stale + $this->expired + $this->temporary;
	}
}
