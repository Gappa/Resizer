<?php
declare(strict_types=1);

namespace Nelson\Resizer\Access;

/**
 * Asks all registered AccessPolicy services. A file no policy protects is public (no change in behavior).
 * When more policies protect a file, the most restrictive decision wins (deny > replace > allow),
 * among equally restrictive ones the first registered.
 */
final class AccessGuard
{
	/** @var array<string, bool> */
	private array $protected = [];


	/**
	 * @param list<AccessPolicy> $policies
	 */
	public function __construct(
		private readonly array $policies = [],
	) {
	}


	public function hasPolicies(): bool
	{
		return $this->policies !== [];
	}


	public function isProtected(string $file): bool
	{
		$file = self::normalize($file);

		if (!isset($this->protected[$file])) {
			$this->protected[$file] = array_any($this->policies, static fn(AccessPolicy $policy): bool => $policy->protects($file));
		}

		return $this->protected[$file];
	}


	/**
	 * Decision for the current user, null when the file is public.
	 */
	public function decide(string $file): ?AccessDecision
	{
		$file = self::normalize($file);
		$result = null;

		foreach ($this->policies as $policy) {
			if (!$policy->protects($file)) {
				continue;
			}

			$decision = $policy->decide($file);

			if ($result === null || $decision->isMoreRestrictiveThan($result)) {
				$result = $decision;
			}
		}

		return $result;
	}


	/** Requests may have a leading slash, policies get paths relative to wwwDir */
	private static function normalize(string $file): string
	{
		return ltrim($file, '/');
	}
}
