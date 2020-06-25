<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

final class ResizerConfig
{

	/**
	 * Gd|Imagick|Gmagick
	 * @var string
	 */
	public $library = 'Imagick';

	/** @var string */
	public $cacheNS = 'resizer';

	/** @var bool */
	public $absoluteUrls = false;

	/**
	 * Progressive mode
	 * @var bool
	 */
	public $interlace = true;

	/** @var string */
	public $wwwDir;

	/** @var string */
	public $tempDir;

	/** @var string */
	public $cache = '/resizer/';

	/**
	 * 0 - 100
	 * @var int
	 */
	public $qualityWebp = 75;

	/**
	 * 0 - 100
	 * @var int
	 */
	public $qualityJpeg = 75;

	/**
	 * 0 - 9
	 * @var int
	 */
	public $compressionPng = 9;

}
