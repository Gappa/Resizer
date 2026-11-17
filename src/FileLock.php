<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use Nette\IOException;
use Nette\Utils\FileSystem;

/**
 * Exclusive flock() of a key (e.g. a thumbnail path), waits for other processes holding it.
 * @internal
 */
final class FileLock
{
	/** @param resource $handle */
	private function __construct(
		private $handle,
		private readonly string $path,
	) {
	}


	/**
	 * Null when the lock file can't be created - the caller continues without the lock.
	 */
	public static function acquire(string $dir, string $key): ?self
	{
		$path = rtrim($dir, '/\\') . '/' . md5($key) . '.lock';

		try {
			FileSystem::createDir(dirname($path));
		} catch (IOException) {
			return null;
		}

		$handle = @fopen($path, 'c');

		if ($handle === false) {
			return null;
		}

		if (!flock($handle, LOCK_EX)) {
			fclose($handle);
			return null;
		}

		return new self($handle, $path);
	}


	public function release(): void
	{
		// removed while still locked: the waiting processes hold their handles and find the work done,
		// a race with a new process can at worst do the work twice
		@unlink($this->path);
		flock($this->handle, LOCK_UN);
		fclose($this->handle);
	}
}
