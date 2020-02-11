<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

use Nelson\Resizer\Resizer;
use Nette\Application\IPresenterFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class ResizerExtension extends CompilerExtension
{

	/** @var string */
	public const PRESENTER_MAPPING = 'Resizer';

	/** @var string */
	public const PRESENTER = 'Resize';

	/** @var string */
	public const ACTION = 'default';


	public function getConfigSchema(): Schema
	{
		return Expect::from(new ResizerConfig);
	}


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$builder->addDefinition($this->prefix('default'))
			->setType(Resizer::class)
			->addSetup('setup', [$config]);
	}


	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		// Latte filter
		$latteFactoryName = 'latte.latteFactory';
		if ($builder->hasDefinition($latteFactoryName)) {
			/** @var FactoryDefinition $latteFactory */
			$latteFactory = $builder->getDefinition($latteFactoryName);
			$latteFactory
				->getResultDefinition()
				->addSetup('addFilter', ['resize', [$this->prefix('@default'), 'resize']])
				->addSetup('Nelson\Resizer\Macros::install(?->getCompiler())', ['@self']);
		}

		// Presenter mappings
		$mapping = [self::PRESENTER_MAPPING => '\Nelson\Resizer\Presenters\*Presenter'];
		$presenterMapper = $builder->getByType(IPresenterFactory::class);

		if ($presenterMapper) {
			/** @var ServiceDefinition $service */
			$service = $builder->getDefinition($presenterMapper);
			$service->addSetup('setMapping', [$mapping]);
		}
	}


	public static function getResizerLink(?bool $absolute = true): string
	{
		return ($absolute ? ':' : '') . self::PRESENTER_MAPPING . ':' . self::PRESENTER . ':';
	}
}
