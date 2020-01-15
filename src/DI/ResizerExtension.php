<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

use Nelson\Resizer\Resizer;
use Nette\Application\IPresenterFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\ServiceDefinition;

final class ResizerExtension extends CompilerExtension
{

	/** @var string */
	public const PRESENTER_MAPPING = 'Resizer';

	/** @var string */
	public const PRESENTER = 'Resize';

	/** @var string */
	public const ACTION = 'default';

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
			'webp_quality' => 75, // 0 - 100
			'jpeg_quality' => 75, // 0 - 100
			'png_compression_level' => 9, // 0 - 9
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
			/** @var ServiceDefinition $latteFactory */
			$latteFactory = $builder->getDefinition($latteFactoryName);
			$latteFactory
				->addSetup('addFilter', ['resize', [$this->prefix('@default'), 'resize']])
				->addSetup('Nelson\Resizer\Macros::install(?->getCompiler())', ['@self']);
		}

		// Presenter mappings
		$mapping = [self::PRESENTER_MAPPING => '\Nelson\Resizer\Presenters\*Presenter'];
		$presenterMapper = $builder->getByType(IPresenterFactory::class);
		/** @var ServiceDefinition $service */
		$service = $builder->getDefinition($presenterMapper);
		$service->addSetup('setMapping', [$mapping]);
	}


	public static function getResizerLink(?bool $absolute = true): string
	{
		return ($absolute ? ':' : '') . self::PRESENTER_MAPPING . ':' . self::PRESENTER . ':';
	}
}
