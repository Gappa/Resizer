<?php
declare(strict_types=1);

namespace Nelson\Resizer\Latte;

use Latte\Extension;
use Nelson\Resizer\Dimensions;
use Nelson\Resizer\Placeholder\Placeholder;
use Nelson\Resizer\ResizerLinkGenerator;

final class ResizerExtension extends Extension
{
	public function __construct(
		private readonly ?ResizerLinkGenerator $linkGenerator = null,
	) {
	}


	/** @return array<string, array<int, class-string|string>> */
	public function getTags(): array
	{
		return [
			'rlink' => [RlinkNode::class, 'create'],
			'rlinkabs' => [RlinkNode::class, 'create'],
			'rsrcset' => [RlinkNode::class, 'create'],
			'rpicture' => [RlinkNode::class, 'create'],
		];
	}


	/** @return array<string, callable> */
	public function getFunctions(): array
	{
		return [
			// dimensions of a thumbnail for width/height attributes: {var $size = rsize($file, '400x')}
			'rsize' => fn(string $file, ?string $params = null): ?Dimensions => $this->linkGenerator?->getDimensions($file, $params),
			// inline style of a placeholder: <img n:attr="style: rplaceholder($file, '400x', 'blur')">
			'rplaceholder' => fn(string $file, ?string $params = null, string $mode = Placeholder::COLOR): string => $this->linkGenerator?->getPlaceholderStyle($file, $params, $mode) ?? '',
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
