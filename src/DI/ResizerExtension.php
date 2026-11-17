<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

use Exception;
use Gmagick;
use Imagick;
use Nelson\Resizer\Latte\ResizerExtension as LatteResizerExtension;
use Nelson\Resizer\OutputFormat;
use Nelson\Resizer\Presenters\ResizePresenter;
use Nelson\Resizer\Resizer;
use Nelson\Resizer\ResizerConfig;
use Nelson\Resizer\ResizerLinkGenerator;
use Nelson\Resizer\UrlSigner;
use Nette\Application\IPresenterFactory;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\FactoryDefinition;
use Nette\DI\Definitions\ServiceDefinition;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

final class ResizerExtension extends CompilerExtension
{
	public const string PRESENTER_MAPPING = 'Resizer';
	public const string PRESENTER = 'Resize';
	public const string ACTION = 'default';


	public function getConfigSchema(): Schema
	{
		return Expect::from(new ResizerConfigDTO, [
			'library' => Expect::anyOf('Gd', 'Imagick', 'Gmagick')->default('Imagick'),
			'wwwDir' => Expect::string()->required(),
			'tempDir' => Expect::string()->required(),
			'qualityAvif' => Expect::int(70)->min(0)->max(100),
			'qualityWebp' => Expect::int(70)->min(0)->max(100),
			'qualityJpeg' => Expect::int(70)->min(0)->max(100),
			'qualityJxl' => Expect::int(70)->min(0)->max(100),
			'compressionPng' => Expect::int(9)->min(0)->max(9),
			'formatPriority' => Expect::listOf(Expect::anyOf('jxl', 'webp', 'avif'))
				->default(['jxl', 'webp', 'avif'])
				->mergeDefaults(false)
				->assert(static fn(mixed $list): bool => is_array($list) && count($list) === count(array_unique($list)), 'Formats must not repeat'),
			'maxWidth' => Expect::int(4000)->min(1),
			'maxHeight' => Expect::int(4000)->min(1),
		]);
	}


	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();
		/** @var ResizerConfigDTO $config */
		$config = $this->getConfig();
		$config->isWebpSupportedByServer = $this->isWebpSupported($config);
		$config->isAvifSupportedByServer = $this->isAvifSupported($config);
		$config->isJxlSupportedByServer = $this->isJxlSupported($config);

		$builder->addDefinition($this->prefix('config'))
			->setFactory(ResizerConfig::class)
			->setArgument('config', $config);

		$builder->addDefinition($this->prefix('output.format'))
			->setFactory(OutputFormat::class);

		$builder->addDefinition($this->prefix('default'))
			->setType(Resizer::class);

		$builder->addDefinition($this->prefix('urlSigner'))
			->setFactory(UrlSigner::class);

		$builder->addDefinition($this->prefix('linkGenerator'))
			->setFactory(ResizerLinkGenerator::class);

		$builder->addDefinition(null)->setType(ResizePresenter::class);
	}


	public function beforeCompile(): void
	{
		$builder = $this->getContainerBuilder();

		$latteFactoryDef = $this->getLatteFactoryDefinition();
		$latteFactoryDef->addSetup('addExtension', [
			new Statement(LatteResizerExtension::class, [$this->prefix('@linkGenerator')]),
		]);

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


	/**
	 * @param string|null $gd key in gd_info(), null when GD can't write the format
	 * @param string|null $gmagick format name, null when Imagine's Gmagick driver does not support it
	 */
	private function isFormatSupported(ResizerConfigDTO $config, ?string $gd, string $imagick, ?string $gmagick): bool
	{
		return match ($config->library) {
			'Gd' => $gd !== null && function_exists('gd_info') && !empty(gd_info()[$gd]),
			'Imagick' => extension_loaded('imagick') && in_array($imagick, Imagick::queryFormats(), true),
			'Gmagick' => $gmagick !== null && extension_loaded('gmagick') && in_array($gmagick, (new Gmagick)->queryformats(), true),
			default => false,
		};
	}


	/**
	 * JPEG XL: GD can't write it and Imagine's Gmagick driver has no JXL options (quality would be ignored),
	 * so only Imagick with ImageMagick built with libjxl.
	 */
	private function isJxlSupported(ResizerConfigDTO $config): bool
	{
		return $this->isFormatSupported($config, null, 'JXL', null);
	}


	private function isWebpSupported(ResizerConfigDTO $config): bool
	{
		return $this->isFormatSupported($config, 'WebP Support', 'WEBP', 'WEBP');
	}


	private function isAvifSupported(ResizerConfigDTO $config): bool
	{
		return $this->isFormatSupported($config, 'AVIF Support', 'AVIF', 'AVIF');
	}


	/** @throws Exception */
	private function getLatteFactoryDefinition(): ServiceDefinition
	{
		$builder = $this->getContainerBuilder();

		$latteFactoryName = 'latte.latteFactory';

		if (!$builder->hasDefinition($latteFactoryName)) {
			throw new Exception(sprintf('Service %s not found.', $latteFactoryName));
		}

		$latteFactory = $builder->getDefinition($latteFactoryName);
		$resultDefinition = $latteFactory instanceof FactoryDefinition ? $latteFactory->getResultDefinition() : null;

		if (!$resultDefinition instanceof ServiceDefinition) {
			throw new Exception(sprintf('Service %s is not a factory of a Latte engine.', $latteFactoryName));
		}

		return $resultDefinition;
	}
}
