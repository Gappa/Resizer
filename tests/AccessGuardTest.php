<?php
declare(strict_types=1);

namespace Nelson\Resizer\Tests;

use Nelson\Resizer\Access\AccessDecision;
use Nelson\Resizer\Access\AccessGuard;
use Nette\Http\IResponse;
use Nette\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccessGuardTest extends TestCase
{
	public function testWithoutPolicies(): void
	{
		$guard = new AccessGuard;

		$this->assertFalse($guard->hasPolicies());
		$this->assertFalse($guard->isProtected('storage/private/a.jpg'));
		$this->assertNull($guard->decide('storage/private/a.jpg'));
	}


	public function testPublicFileIsNotDecided(): void
	{
		$policy = new FakeAccessPolicy('storage/private/', AccessDecision::deny());
		$guard = new AccessGuard([$policy]);

		$this->assertTrue($guard->hasPolicies());
		$this->assertFalse($guard->isProtected('storage/public/a.jpg'));
		$this->assertNull($guard->decide('storage/public/a.jpg'));
		$this->assertSame([], $policy->decided, 'decide() is called for protected files only');
	}


	public function testLeadingSlashIsRemoved(): void
	{
		$policy = new FakeAccessPolicy('storage/private/', AccessDecision::allow());
		$guard = new AccessGuard([$policy]);

		$this->assertTrue($guard->isProtected('/storage/private/a.jpg'));
		$this->assertTrue($guard->decide('/storage/private/a.jpg')?->isAllowed());
		$this->assertSame(['storage/private/a.jpg'], $policy->decided);
	}


	/** @return iterable<string, array{list<AccessDecision>, string}> */
	public static function provideMostRestrictive(): iterable
	{
		yield 'allow' => [[AccessDecision::allow()], AccessDecision::ALLOW];
		yield 'allow and replace' => [[AccessDecision::allow(), AccessDecision::replace('locked.png')], AccessDecision::REPLACE];
		yield 'deny and allow' => [[AccessDecision::deny(), AccessDecision::allow()], AccessDecision::DENY];
		yield 'replace, deny, allow' => [[AccessDecision::replace('locked.png'), AccessDecision::deny(), AccessDecision::allow()], AccessDecision::DENY];
	}


	/** @param list<AccessDecision> $decisions */
	#[DataProvider('provideMostRestrictive')]
	public function testMostRestrictiveWins(array $decisions, string $expected): void
	{
		$policies = array_map(static fn(AccessDecision $decision): FakeAccessPolicy => new FakeAccessPolicy('storage/', $decision), $decisions);
		// a policy not protecting the file has no say
		$policies[] = new FakeAccessPolicy('other/', AccessDecision::deny());

		$this->assertSame($expected, (new AccessGuard($policies))->decide('storage/a.jpg')?->type);
	}


	public function testFirstOfEquallyRestrictiveWins(): void
	{
		$guard = new AccessGuard([
			new FakeAccessPolicy('storage/', AccessDecision::replace('first.png')),
			new FakeAccessPolicy('storage/', AccessDecision::replace('second.png')),
		]);

		$this->assertSame('first.png', $guard->decide('storage/a.jpg')?->replacement);
	}


	public function testDecisions(): void
	{
		$this->assertTrue(AccessDecision::allow()->isAllowed());
		$this->assertSame(IResponse::S404_NotFound, AccessDecision::deny()->code);
		$this->assertSame(IResponse::S403_Forbidden, AccessDecision::deny(IResponse::S403_Forbidden)->code);
		$this->assertTrue(AccessDecision::deny()->isDenied());
		$this->assertTrue(AccessDecision::replace('locked.png')->isReplaced());
		$this->assertSame('locked.png', AccessDecision::replace('locked.png')->replacement);
	}


	public function testDenyNeeds4xx(): void
	{
		$this->expectException(InvalidArgumentException::class);
		AccessDecision::deny(IResponse::S200_OK);
	}


	public function testReplacementMustNotBeEmpty(): void
	{
		$this->expectException(InvalidArgumentException::class);
		AccessDecision::replace('');
	}
}
