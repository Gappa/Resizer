<?php
declare(strict_types=1);

namespace Nelson\Resizer;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Deletes thumbnails that are not needed anymore: orphaned (the source image was deleted), stale (the source
 * image changed - in static mode the old file would be served until deleted) and optionally the ones older
 * than a max age; plus leftover temporary and lock files and empty directories.
 * Only files named like thumbnails ("<params>.<format>") are deleted, other files in the cache directory are kept.
 */
final class CacheCleaner
{
	/** Temporary and lock files older than this are leftovers of crashed processes */
	private const int TEMPORARY_MAX_AGE = 3600;


	public function __construct(
		private readonly ResizerConfig $config,
	) {
	}


	/**
	 * @param int|null $maxAge seconds - thumbnails generated earlier are deleted too (regenerated on demand)
	 */
	public function clean(bool $dryRun = false, ?int $maxAge = null): CleanupResult
	{
		$result = new CleanupResult($dryRun);
		$cacheDir = realpath($this->config->getCacheDir());
		$wwwDir = realpath($this->config->getWwwDir());

		if ($cacheDir !== false && $wwwDir !== false && $cacheDir !== $wwwDir) {
			$this->cleanThumbnails($result, $cacheDir, $wwwDir, $maxAge);

			if (!$dryRun) {
				$this->removeEmptyDirectories($cacheDir);
			}
		}

		$this->cleanLocks($result);

		return $result;
	}


	private function cleanThumbnails(CleanupResult $result, string $cacheDir, string $wwwDir, ?int $maxAge): void
	{
		$now = time();

		/** @var SplFileInfo $file */
		foreach ($this->iterate($cacheDir, RecursiveIteratorIterator::LEAVES_ONLY) as $file) {
			if (!$file->isFile() || $file->isLink()) {
				continue;
			}

			$name = $file->getFilename();
			$mtime = (int) $file->getMTime();

			if (str_starts_with($name, '.tmp-')) {
				if ($mtime < $now - self::TEMPORARY_MAX_AGE) {
					$result->temporary++;
					$this->delete($result, $file);
				}

				continue;
			}

			// <cache>/<source path>/<params>.<format>
			$sourcePath = strtr(substr($file->getPath(), strlen($cacheDir)), '\\', '/');

			if ($sourcePath === '' || !self::isThumbnailName($name)) {
				continue; // not ours
			}

			$source = $wwwDir . $sourcePath;

			if (!is_file($source)) {
				$result->orphaned++;
				$this->delete($result, $file);
			} elseif ($mtime < (int) filemtime($source)) {
				$result->stale++;
				$this->delete($result, $file);
			} elseif ($maxAge !== null && $mtime < $now - $maxAge) {
				$result->expired++;
				$this->delete($result, $file);
			} else {
				$result->kept++;
			}
		}
	}


	/**
	 * Lock files of crashed processes (normally removed right after the thumbnail is generated).
	 */
	private function cleanLocks(CleanupResult $result): void
	{
		$lockDir = rtrim($this->config->getTempDir(), '/\\') . '/resizer-locks';

		foreach (glob($lockDir . '/*.lock') ?: [] as $path) {
			$file = new SplFileInfo($path);

			if ((int) $file->getMTime() >= time() - self::TEMPORARY_MAX_AGE) {
				continue;
			}

			// not held by a running process
			$handle = @fopen($path, 'r');

			if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
				if ($handle !== false) {
					fclose($handle);
				}

				continue;
			}

			$result->temporary++;
			$this->delete($result, $file);
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}


	private function delete(CleanupResult $result, SplFileInfo $file): void
	{
		$result->deleted[] = $file->getPathname();
		$result->freedBytes += (int) $file->getSize();

		if (!$result->dryRun) {
			@unlink($file->getPathname());
		}
	}


	private function removeEmptyDirectories(string $cacheDir): void
	{
		/** @var SplFileInfo $dir */
		foreach ($this->iterate($cacheDir, RecursiveIteratorIterator::CHILD_FIRST) as $dir) {
			if ($dir->isDir() && !$dir->isLink()) {
				@rmdir($dir->getPathname()); // fails for non-empty directories
			}
		}
	}


	/**
	 * @param RecursiveIteratorIterator::* $mode
	 * @return RecursiveIteratorIterator<RecursiveDirectoryIterator>
	 */
	private function iterate(string $dir, int $mode): RecursiveIteratorIterator
	{
		return new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
			$mode,
		);
	}


	private static function isThumbnailName(string $name): bool
	{
		$params = pathinfo($name, PATHINFO_FILENAME);
		$format = pathinfo($name, PATHINFO_EXTENSION);

		return isset(Resizer::MIME_TYPES[$format])
			&& preg_match(ResizerParamsParser::PATTERN, $params) === 1;
	}
}
