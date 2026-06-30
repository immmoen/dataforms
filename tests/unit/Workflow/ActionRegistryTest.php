<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Workflow\ActionRegistry;
use OCA\Dataforms\Workflow\ApplyTemplateAction;
use OCA\Dataforms\Workflow\CalendarEventAction;
use OCA\Dataforms\Workflow\CreateDeckBoardAction;
use OCA\Dataforms\Workflow\CreateTalkRoomAction;
use OCA\Dataforms\Workflow\EmailAction;
use OCA\Dataforms\Workflow\NotifyAction;
use OCA\Dataforms\Workflow\ProvisionFoldersAction;
use OCA\Dataforms\Workflow\SetFieldAction;
use OCA\Dataforms\Workflow\WebhookAction;
use PHPUnit\Framework\TestCase;

/**
 * ActionRegistry: maps action_type → IAction. All nine actions are registered
 * and resolvable by type; an unknown type resolves to null.
 */
class ActionRegistryTest extends TestCase {
	private function registry(): ActionRegistry {
		// Each constructor arg is type-hinted to a concrete action; pass a stub of
		// each via the real classes' types is overkill — instead build from doubles.
		return new ActionRegistry(
			$this->stub(NotifyAction::class, 'notify'),
			$this->stub(EmailAction::class, 'email'),
			$this->stub(SetFieldAction::class, 'set_field'),
			$this->stub(WebhookAction::class, 'webhook'),
			$this->stub(ProvisionFoldersAction::class, 'provision_folders'),
			$this->stub(CalendarEventAction::class, 'add_calendar_event'),
			$this->stub(ApplyTemplateAction::class, 'apply_template'),
			$this->stub(CreateTalkRoomAction::class, 'create_talk_room'),
			$this->stub(CreateDeckBoardAction::class, 'create_deck_board'),
		);
	}

	/** @template T @param class-string<T> $class @return T */
	private function stub(string $class, string $type) {
		$m = $this->createMock($class);
		$m->method('getType')->willReturn($type);
		return $m;
	}

	public function testRegistersAllNineActionsAndResolvesByType(): void {
		$registry = $this->registry();
		$types = $registry->types();
		$this->assertCount(9, $types);
		foreach (['notify', 'email', 'set_field', 'webhook', 'provision_folders', 'add_calendar_event', 'apply_template', 'create_talk_room', 'create_deck_board'] as $t) {
			$this->assertContains($t, $types);
			$this->assertSame($t, $registry->get($t)->getType());
		}
		$this->assertNull($registry->get('nope'));
	}
}
