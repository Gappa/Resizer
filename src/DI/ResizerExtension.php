<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

use Exception;
use Gmagick;
use Imagick;
use Latte\Engine;
use Nelson\Resizer\Latte\ResizerExtension as LatteResizerExtension;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nette\Application\IPresenterFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Definition;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class ResizerExtension extends CompilerExtension
{
	public const PRESENTER_MAPPING = 'Resizer';
	public const PRESENTER = 'Resize';
	public const ACTION = 'default';


	public function getConfigSchema(): Schema
	{
		return Expect::from(new ResizerConfigDTO, [
			'library' => Expect::anyOf('Gd', 'Imagick', 'Gmagick')->default('Imagick'),
			'qualityWebp' => Expect::int(75)->min(0)->max(100),
			'qualityJpeg' => Expect::int(75)->min(0)->max(100),
			'compressionPng' => Expect::int(9)->min(0)->max(9),
		]);
	}


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		/** @var ResizerConfigDTO $config */
		$config = $this->getConfig();
		$config->isWebpSupportedByServer = $this->isWebpSupported($config);
		$config->isAvifSupportedByServer = $this->isAvifSupported($config);

		$builder->addDefinition($this->prefix('config'))
			->setFactory(ResizerConfig::class)
			->setArgument('config', $config);

		$builder->addDefinition($this->prefix('output.format'))
			->setFactory(OutputFormat::class);

		$builder->addDefinition($this->prefix('default'))
			->setType(Resizer::class);
	}


	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		$latteFactoryDef = $this->getLatteFactoryDefinition();
		$latteFactoryDef->addSetup('addFilter', ['resize', [$this->prefix('@default'), 'resize']]);

		// Latte 3.x
		$latteFactoryDef->addSetup('addExtension', [new LatteResizerExtension]);

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


	private function isFormatSupported(ResizerConfigDTO $config, string $gd, string $imagick, string $gmagick): bool
	{
		$support = false;

		switch ($config->library) {
			case 'Gd':
				$support = function_exists('gd_info') && !empty(gd_info()[$gd]);
				break;

			case 'Imagick':
				$support = extension_loaded('imagick') && in_array($imagick, Imagick::queryFormats(), true);
				break;

			case 'Gmagick':
				$support = extension_loaded('gmagick') && in_array($gmagick, (new Gmagick)->queryformats(), true);
				break;
		}

		return $support;
	}


	private function isWebpSupported(ResizerConfigDTO $config): bool
	{
		return $this->isFormatSupported($config, 'WebP Support', 'WEBP', 'WEBP');
	}


	private function isAvifSupported(ResizerConfigDTO $config): bool
	{
		return $this->isFormatSupported($config, 'AVIF Support', 'AVIF', 'AVIF');
	}


	/**
	 * @return ServiceDefinition
	 * @throws Exception
	 */
	private function getLatteFactoryDefinition(): Definition
	{
		$builder = $this->getContainerBuilder();

		$latteFactoryName = 'latte.latteFactory';

		if (!$builder->hasDefinition($latteFactoryName)) {
			throw new Exception(sprintf('Service %s not found.', $latteFactoryName));
		}

		/** @var FactoryDefinition $latteFactory */
		$latteFactory = $builder->getDefinition($latteFactoryName);
		return $latteFactory->getResultDefinition();
	}
}
