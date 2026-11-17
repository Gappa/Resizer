<?php
declare(strict_types=1);

namespace Nelson\Resizer\Access;

use Nette\Http\IResponse;
use Nette\InvalidArgumentException;

/**
 * Result of AccessPolicy::decide(): allow, deny (404/403) or replace with another image.
 */
final readonly class AccessDecision
{
	public const string ALLOW = 'allow';
	public const string DENY = 'deny';
	public const string REPLACE = 'replace';

	/** The more restrictive decision wins when more policies protect the same file */
	private const array SEVERITY = [self::ALLOW => 0, self::REPLACE => 1, self::DENY => 2];


	/**
	 * @param self::ALLOW|self::DENY|self::REPLACE $type
	 */
	private function __construct(
		public string $type,
		public int $code = IResponse::S200_OK,
		public ?string $replacement = null,
	) {
	}


	public static function allow(): self
	{
		return new self(self::ALLOW);
	}


	/**
	 * @param int $code 404 (default) does not reveal the image exists, 403 does
	 */
	public static function deny(int $code = IResponse::S404_NotFound): self
	{
		if ($code < 400 || $code > 499) {
			throw new InvalidArgumentException('Code of a denied access must be 4xx.');
		}

		return new self(self::DENY, $code);
	}


	/**
	 * The given image (path relative to wwwDir, e.g. "assets/img/locked.png") is served instead,
	 * resized with the same params, so the layout is kept. It is not checked by the policies.
	 */
	public static function replace(string $file): self
	{
		if ($file === '') {
			throw new InvalidArgumentException('Replacement image must not be empty.');
		}

		return new self(self::REPLACE, replacement: $file);
	}


	public function isAllowed(): bool
	{
		return $this->type === self::ALLOW;
	}


	public function isDenied(): bool
	{
		return $this->type === self::DENY;
	}


	public function isReplaced(): bool
	{
		return $this->type === self::REPLACE;
	}


	public function isMoreRestrictiveThan(self $other): bool
	{
		return self::SEVERITY[$this->type] > self::SEVERITY[$other->type];
	}
}
