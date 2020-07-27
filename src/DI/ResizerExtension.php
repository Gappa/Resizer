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
		return Expect::from(new ResizerConfig, [
			'library' => Expect::anyOf('Gd', 'Imagick', 'Gmagick')->default('Imagick'),
			'qualityWebp' => Expect::int(75)->min(0)->max(100),
			'qualityJpeg' => Expect::int(75)->min(0)->max(100),
			'compressionPng' => Expect::int(9)->min(0)->max(9),
		]);
	}


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig();

		$builder->addDefinition($this->prefix('default'))
			->setType(Resizer::class)
			->setArgument('config', $config)
			->setArgument('isWebpSupportedByServer', $this->isWebpSupported($config));
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


	private function isWebpSupported(ResizerConfig $config): bool
	{
		$support = false;

		switch ($config->library) {
			case 'Gd':
				$support = function_exists('gd_info') && !empty(gd_info()['WebP Support']);
				break;

			case 'Imagick':
				$support = extension_loaded('imagick') && in_array('WEBP', \Imagick::queryformats());
				break;

			case 'Gmagick':
				$support = extension_loaded('gmagick') && in_array('WEBP', (new \Gmagick)->queryformats());
				break;
		}

		return $support;
	}
}
