<?php
declare(strict_types=1);

namespace Nelson\Resizer\DI;

final class ResizerConfig
{

	/** @var string */
	public $library;

	/** @var bool */
	public $absoluteUrls = false;

	/** @var bool */
	public $interlace = true;

	/** @var string */
	public $wwwDir;

	/** @var string */
	public $tempDir;

	/** @var string */
	public $cache = '/resizer/';

	/** @var int */
	public $qualityWebp;

	/** @var int */
	public $qualityJpeg;

	/** @var int */
	public $compressionPng;

	/** @var bool */
	public $upgradeJpg2Webp = true;
}
