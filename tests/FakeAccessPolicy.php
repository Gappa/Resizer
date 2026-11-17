<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\Access\AccessDecision;
use Nelson\Resizer\Access\AccessPolicy;

/**
 * Protects files starting with the prefix, always returns the given decision.
 */
final class FakeAccessPolicy implements AccessPolicy
{
	/** @var list<string> files decide() was called for */
	public array $decided = [];


	public function __construct(
		private readonly string $prefix,
		private readonly AccessDecision $decision,
	) {
	}


	public function protects(string $file): bool
	{
		return str_starts_with($file, $this->prefix);
	}


	public function decide(string $file): AccessDecision
	{
		$this->decided[] = $file;
		return $this->decision;
	}
}
