<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

use _HumbugBox9aa570140480\Nette\DI\Definitions\FactoryDefinition;
use Nelson\Resizer\Resizer;
use Nepada\PresenterMapping\PresenterMapper;
use Nette\DI\CompilerExtension;
use Nette\DI\ServiceDefinition;

final class ResizerExtension extends CompilerExtension
{
	/** @var array */
	protected $defaults = [
		'paths' => [
			'wwwDir' => '%wwwDir%',
			'storage' => null,
			'assets' => null,
			'cache' => '%wwwDir%/cache/images/',
		],
		'library' => 'Imagick', // Gd/Imagick/Gmagick
		'cacheNS' => 'resizer',
		'absoluteUrls' => false,
		'interlace' => true, // progressive mode
		'options' => [
			'jpeg_quality' => 75, // 0 - 100
			'png_compression_level' => 7, // 0 - 9
		],
	];


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->validateConfig($this->getContainerBuilder()->expand($this->defaults), $this->config);
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
				->addSetup('addFilter', ['resize', [$this->prefix('@default'), 'resize']])
				->addSetup('Nelson\Resizer\Macros::install(?->getCompiler())', ['@self']);
		}


		// Presenter mappings
		$mapping = ['Base:Resizer' => '\Nelson\Resizer\Presenters\*Presenter'];
		$presenterMapper = $builder->getByType(PresenterMapper::class);

		if ($presenterMapper) {
			/** @var ServiceDefinition $service */
			$service = $builder->getDefinition($presenterMapper);
			$service->addSetup('setMapping', [$mapping]);
		}
	}
}
