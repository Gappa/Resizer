<?php

namespace Nelson\Resizer\DI;

use Latte\Engine;
use Nelson\Resizer as Module;
use Nelson\Resizer\Resizer;
use Nepada\PresenterMapping\PresenterMapper;
use Nette\Bridges\ApplicationLatte\ILatteFactory;
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
		'options' => [
			'jpeg_quality' => 75, // 0 - 100
			'png_compression_level' => 7, // 0 - 9
		],
	];


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);
		$resizer = $builder->addDefinition($this->prefix('default'))
			->setClass(Resizer::class)
			->addSetup('setup', [$config]);
	}


	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		$registerToLatte = function (ServiceDefinition $def) {
			$def->addSetup('addFilter', ['resize', [$this->prefix('@default'), 'resize']]);
		};

		$latteFactoryService = $builder->getByType(ILatteFactory::class);
		if (!$latteFactoryService || !self::isOfType($builder->getDefinition($latteFactoryService)->getClass(), Engine::class)) {
			$latteFactoryService = 'nette.latteFactory';
		}

		if ($builder->hasDefinition($latteFactoryService) && self::isOfType($builder->getDefinition($latteFactoryService)->getClass(), Engine::class)) {
			$registerToLatte($builder->getDefinition($latteFactoryService));
		}

		if ($builder->hasDefinition('nette.latte')) {
			$registerToLatte($builder->getDefinition('nette.latte'));
		}

		$this->applyMapping();
	}


	private function applyMapping(): void
	{
		$builder = $this->getContainerBuilder();

		$mapping = ['Base:Resizer' => Module::class . '\Presenters\*Presenter'];
		$presenterMapper = $builder->getByType(PresenterMapper::class);
		$builder->getDefinition($presenterMapper)
			->addSetup('setMapping', [$mapping]);
	}


	private static function isOfType(string $class, string $type): bool
	{
		return $class === $type || is_subclass_of($class, $type);
	}
}
