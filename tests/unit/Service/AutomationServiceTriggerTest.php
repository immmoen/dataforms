<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\AutomationService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Guards the rule that non-idempotent provisioning actions (Talk room, Deck
 * board) may only run on the 'create' trigger — so a record provisions its
 * workspace exactly once and repeated edits can't re-fire them. The check is
 * pure logic, exercised here through the private assertTriggerAllowed() without
 * needing the service's collaborators.
 */
class AutomationServiceTriggerTest extends TestCase {
	private ReflectionMethod $assert;
	private AutomationService $svc;

	protected function setUp(): void {
		// No constructor deps are touched by assertTriggerAllowed().
		$this->svc = (new \ReflectionClass(AutomationService::class))->newInstanceWithoutConstructor();
		$this->assert = new ReflectionMethod(AutomationService::class, 'assertTriggerAllowed');
		$this->assert->setAccessible(true);
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function blockedProvider(): array {
		return [
			'talk on update' => ['update', 'create_talk_room'],
			'talk on delete' => ['delete', 'create_talk_room'],
			'deck on update' => ['update', 'create_deck_board'],
			'deck on delete' => ['delete', 'create_deck_board'],
		];
	}

	/**
	 * @dataProvider blockedProvider
	 */
	public function testCreateOnlyActionsRejectNonCreateTriggers(string $trigger, string $actionType): void {
		$this->expectException(ValidationException::class);
		$this->assert->invoke($this->svc, $trigger, $actionType);
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function allowedProvider(): array {
		return [
			'talk on create' => ['create', 'create_talk_room'],
			'deck on create' => ['create', 'create_deck_board'],
			'notify on update' => ['update', 'notify'],
			'email on delete' => ['delete', 'email'],
			'webhook on create' => ['create', 'webhook'],
		];
	}

	/**
	 * @dataProvider allowedProvider
	 * @doesNotPerformAssertions
	 */
	public function testAllowedCombinationsPass(string $trigger, string $actionType): void {
		// No exception = allowed.
		$this->assert->invoke($this->svc, $trigger, $actionType);
	}
}
