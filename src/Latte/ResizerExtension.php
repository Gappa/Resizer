<?php
declare(strict_types=1);

namespace Nelson\Resizer\Latte;

use Latte\Extension;

final class ResizerExtension extends Extension
{
	public function getTags(): array
	{
		return [
			'rlink' => [RlinkNode::class, 'create'],
		];
	}
}
