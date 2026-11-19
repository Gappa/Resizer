<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\CacheCleaner;
use Nelson\Resizer\Console\CleanCacheCommand;
use Nelson\Resizer\DI\ResizerConfigDTO;
use Nelson\Resizer\ResizerConfig;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CacheCleanerTest extends TestCase
{
	private const string TEMP_DIR = __DIR__ . '/../temp';
	private const string CACHE_DIR = self::TEMP_DIR . '/resizer-clean';
	private const string PUBLIC_DIR = __DIR__ . '/_public';
	private const string LOCK = self::TEMP_DIR . '/resizer-locks/cache-cleaner-test.lock';
	private const int DAY = 86400;


	protected function setUp(): void
	{
		parent::setUp();
		$now = time();

		// sources
		self::write(self::PUBLIC_DIR . '/source.png', $now - 100 * self::DAY);
		self::write(self::PUBLIC_DIR . '/changed.png', $now);

		$cache = self::CACHE_DIR . '/_public';
		self::write("$cache/source.png/x100.png", $now); // kept
		self::write("$cache/source.png/x200.webp", $now - 40 * self::DAY); // expired with max age 30 days
		self::write("$cache/changed.png/x100.png", $now - 3600); // stale, the source is newer
		self::write("$cache/deleted.png/c10xc10.jpg", $now); // orphaned, the source does not exist
		self::write("$cache/source.png/.tmp-abc-x100.png", $now - 7200); // leftover temporary file
		self::write("$cache/source.png/.tmp-def-x100.png", $now); // being written right now
		self::write("$cache/source.png/readme.txt", $now - 100 * self::DAY); // not a thumbnail
		self::write(self::CACHE_DIR . '/x100.png', $now - 100 * self::DAY); // no source path
		self::write(self::LOCK, $now - 7200, ''); // leftover lock
	}


	protected function tearDown(): void
	{
		parent::tearDown();
		FileSystem::delete(self::CACHE_DIR);
		FileSystem::delete(self::PUBLIC_DIR);
		FileSystem::delete(self::LOCK);
	}


	public function testClean(): void
	{
		$result = $this->createCleaner()->clean(maxAge: 30 * self::DAY);

		$this->assertFalse($result->dryRun);
		$this->assertSame(
			['orphaned' => 1, 'stale' => 1, 'expired' => 1, 'temporary' => 2, 'kept' => 1],
			['orphaned' => $result->orphaned, 'stale' => $result->stale, 'expired' => $result->expired, 'temporary' => $result->temporary, 'kept' => $result->kept],
		);
		$this->assertSame(5, $result->getDeletedCount());
		$this->assertSame(16, $result->freedBytes); // 4 files with "data", the lock is empty

		$cache = self::CACHE_DIR . '/_public';
		$this->assertFileExists("$cache/source.png/x100.png");
		$this->assertFileExists("$cache/source.png/.tmp-def-x100.png");
		$this->assertFileExists("$cache/source.png/readme.txt");
		$this->assertFileExists(self::CACHE_DIR . '/x100.png');
		$this->assertFileDoesNotExist("$cache/source.png/x200.webp");
		$this->assertFileDoesNotExist("$cache/changed.png/x100.png");
		$this->assertFileDoesNotExist("$cache/source.png/.tmp-abc-x100.png");
		$this->assertFileDoesNotExist(self::LOCK);
		// empty directories are removed, the cache directory itself is kept
		$this->assertDirectoryDoesNotExist("$cache/deleted.png");
		$this->assertDirectoryDoesNotExist("$cache/changed.png");
		$this->assertDirectoryExists(self::CACHE_DIR);
		// the sources are untouched
		$this->assertFileExists(self::PUBLIC_DIR . '/source.png');
	}


	public function testStaticModeCleansProtectedCacheToo(): void
	{
		// in static mode thumbnails of protected images are in the non-public cache directory (tempDir + cache)
		self::write(self::PUBLIC_DIR . '/ir/_public/deleted.png/x100.png', time());

		$result = $this->createCleaner(publicCacheDir: self::PUBLIC_DIR . '/ir')->clean();

		$this->assertSame(2, $result->orphaned);
		$this->assertFileDoesNotExist(self::PUBLIC_DIR . '/ir/_public/deleted.png/x100.png');
		$this->assertFileDoesNotExist(self::CACHE_DIR . '/_public/deleted.png/c10xc10.jpg');
	}


	public function testCleanWithoutMaxAge(): void
	{
		$result = $this->createCleaner()->clean();

		$this->assertSame(0, $result->expired);
		$this->assertSame(2, $result->kept);
		$this->assertFileExists(self::CACHE_DIR . '/_public/source.png/x200.webp');
	}


	public function testDryRun(): void
	{
		$result = $this->createCleaner()->clean(dryRun: true, maxAge: 30 * self::DAY);

		$this->assertTrue($result->dryRun);
		$this->assertSame(5, $result->getDeletedCount());
		$this->assertCount(5, $result->deleted);

		foreach ($result->deleted as $path) {
			$this->assertFileExists($path);
		}

		$this->assertDirectoryExists(self::CACHE_DIR . '/_public/deleted.png');
	}


	public function testLockedLockIsKept(): void
	{
		$handle = fopen(self::LOCK, 'r');
		$this->assertNotFalse($handle);
		flock($handle, LOCK_EX);

		try {
			$result = $this->createCleaner()->clean();
			$this->assertSame(1, $result->temporary); // only the temporary file
			$this->assertFileExists(self::LOCK);
		} finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}


	public function testCacheDirSameAsWwwDirIsNotCleaned(): void
	{
		// misconfiguration: the cache directory is the web root - nothing there may be treated as thumbnails
		$cleaner = $this->createCleaner(tempDir: __DIR__ . '/..', cache: '/tests');
		$result = $cleaner->clean(maxAge: 0);

		$this->assertSame(0, $result->orphaned + $result->stale + $result->expired);
		$this->assertFileExists(__DIR__ . '/fixtures/test.png');
	}


	public function testCommand(): void
	{
		$tester = new CommandTester(new CleanCacheCommand($this->createCleaner()));

		$this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true, '--max-age' => '30']));
		$this->assertStringContainsString('Would delete 5 files', $tester->getDisplay());
		$this->assertStringContainsString('1 orphaned, 1 stale, 1 expired, 2 temporary; 1 thumbnails kept', $tester->getDisplay());
		$this->assertFileExists(self::CACHE_DIR . '/_public/deleted.png/c10xc10.jpg');

		$this->assertSame(Command::SUCCESS, $tester->execute([]));
		$this->assertStringContainsString('Deleted 4 files', $tester->getDisplay());
		$this->assertFileDoesNotExist(self::CACHE_DIR . '/_public/deleted.png/c10xc10.jpg');

		$this->assertSame(Command::INVALID, $tester->execute(['--max-age' => 'week']));
	}


	private function createCleaner(?string $tempDir = null, string $cache = '/resizer-clean/', ?string $publicCacheDir = null): CacheCleaner
	{
		$dto = new ResizerConfigDTO;
		$dto->tempDir = $tempDir ?? self::TEMP_DIR;
		$dto->wwwDir = __DIR__;
		$dto->library = 'Gd';
		$dto->cache = $cache;
		$dto->publicCacheDir = $publicCacheDir;

		return new CacheCleaner(new ResizerConfig($dto));
	}


	private static function write(string $path, int $mtime, string $content = 'data'): void
	{
		FileSystem::write($path, $content);
		touch($path, $mtime);
	}
}
