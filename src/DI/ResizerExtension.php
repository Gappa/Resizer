<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

use Latte\Engine;
use Nelson\Resizer\Resizer;
use Nepada\PresenterMapping\PresenterMapper;
use Nette\Bridges\ApplicationLatte\ILatteFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Processor;
use Nette\Schema\Schema;

final class ResizerExtension extends CompilerExtension
{

	// /** @var array */
	// protected $defaults = [
	// 	'paths' => [
	// 		'wwwDir' => '%wwwDir%',
	// 		'storage' => null,
	// 		'assets' => null,
	// 		'cache' => '%wwwDir%/cache/images/',
	// 	],
	// 	'library' => 'Imagick', // Gd/Imagick/Gmagick
	// 	'cacheNS' => 'resizer',
	// 	'absoluteUrls' => false,
	// 	'interlace' => true, // progressive mode
	// 	'options' => [
	// 		'jpeg_quality' => 75, // 0 - 100
	// 		'png_compression_level' => 7, // 0 - 9
	// 	],
	// ];


	public function getConfigSchema(): Schema
	{
		return Expect::structure([
			'paths' => Expect::structure([
				'wwwDir' => Expect::string()->required(),
				'storage' => Expect::string('/storage/'),
				'assets' => Expect::string('/assets/'),
				'cache' => Expect::string('/cache/images/'),
			]),
			'library' => Expect::string('Imagick'), // Gd/Imagick/Gmagick
			'cacheNS' => Expect::string('resizer'),
			'absoluteUrls' => Expect::bool(false),
			'interlace' => Expect::bool(true), // progressive mode
			'options' => Expect::structure([
				'jpeg_quality' => Expect::int(75)->min(0)->max(100),
				'png_compression_level' => Expect::int(9)->min(0)->max(9), // 0 - 9
			]),
		]);
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
				->addSetup('addFilter', ['resize', [$this->prefix('@default'), 'resize']]);
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
