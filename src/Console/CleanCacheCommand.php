<?php
declare(strict_types=1);

namespace Nelson\Resizer\Console;

use Nelson\Resizer\CacheCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * php bin/console resizer:clean [--dry-run] [--max-age=<days>]
 * Registered by the DI extension when symfony/console is installed (e.g. with contributte/console).
 */
#[AsCommand(name: self::NAME, description: 'Deletes orphaned, stale and optionally old thumbnails')]
final class CleanCacheCommand extends Command
{
	public const string NAME = 'resizer:clean';


	public function __construct(
		private readonly CacheCleaner $cleaner,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be deleted')
			->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Delete thumbnails generated more than <days> ago too');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$maxAge = $input->getOption('max-age');

		if ($maxAge !== null && (!is_string($maxAge) || !ctype_digit($maxAge))) {
			$output->writeln('<error>--max-age must be a number of days.</error>');
			return self::INVALID;
		}

		$result = $this->cleaner->clean(
			dryRun: (bool) $input->getOption('dry-run'),
			maxAge: $maxAge === null ? null : (int) $maxAge * 86400,
		);

		if ($output->isVerbose()) {
			foreach ($result->deleted as $path) {
				$output->writeln($path);
			}
		}

		$output->writeln(sprintf(
			'%s %d files (%.1f MB): %d orphaned, %d stale, %d expired, %d temporary; %d thumbnails kept.',
			$result->dryRun ? 'Would delete' : 'Deleted',
			$result->getDeletedCount(),
			$result->freedBytes / 1024 / 1024,
			$result->orphaned,
			$result->stale,
			$result->expired,
			$result->temporary,
			$result->kept,
		));

		return self::SUCCESS;
	}
}
