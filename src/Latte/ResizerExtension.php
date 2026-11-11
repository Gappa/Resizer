<?php
declare(strict_types=1);

namespace Nelson\Resizer\Latte;

use Latte\Extension;
use Nelson\Resizer\ResizerLinkGenerator;

final class ResizerExtension extends Extension
{
	public function __construct(
		private readonly ?ResizerLinkGenerator $linkGenerator = null,
	)
	{
	}


	/** @return array<string, array<int, class-string|string>> */
	public function getTags(): array
	{
		return [
			'rlink' => [RlinkNode::class, 'create'],
			'rlinkabs' => [RlinkNode::class, 'create'],
		];
	}


	/** @return array<string, mixed> */
	public function getProviders(): array
	{
		return $this->linkGenerator === null
			? []
			: [RlinkNode::PROVIDER => $this->linkGenerator];
	}
}
