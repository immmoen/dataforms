<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Service\WorkflowSettings;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The admin-configurable automation settings: action enablement, service-account
 * gating, and the operational limits/defaults (which fall back to the former
 * hardcoded constants when an instance is unconfigured).
 */
class WorkflowSettingsTest extends TestCase {

	/**
	 * @param array<string,string> $strings
	 * @param array<string,int> $ints
	 */
	private function settings(array $strings = [], array $ints = []): WorkflowSettings {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $strings[$k] ?? $d);
		$cfg->method('getValueInt')->willReturnCallback(static fn (string $a, string $k, int $d = 0): int => $ints[$k] ?? $d);
		return new WorkflowSettings($cfg);
	}

	public function testDefaultsWhenUnconfigured(): void {
		$s = $this->settings();
		$this->assertSame([], $s->disabledActions());
		$this->assertSame(50, $s->maxFolders());
		$this->assertSame(200, $s->maxCreated());
		$this->assertSame(100, $s->maxParticipants());
		$this->assertSame(60, $s->calendarDefaultDuration());
		$this->assertSame(['To do', 'Doing', 'Done'], $s->defaultDeckColumns());
		$this->assertTrue($s->isActionEnabled('webhook'));
	}

	public function testDisabledActionsParsed(): void {
		$s = $this->settings(['automation_disabled' => (string)json_encode(['webhook', 'email'])]);
		$this->assertSame(['webhook', 'email'], $s->disabledActions());
		$this->assertFalse($s->isActionEnabled('webhook'));
		$this->assertTrue($s->isActionEnabled('notify'));
	}

	public function testAvailableActionsHideDisabledAndServiceAccountGated(): void {
		$all = ['notify', 'webhook', 'create_talk_room', 'create_deck_board'];
		$s = $this->settings(['automation_disabled' => (string)json_encode(['webhook'])]);

		// No service account → the cross-app actions are hidden too.
		$this->assertSame(['notify'], $s->availableActions($all, false));
		// With the service account → Talk/Deck appear; the disabled webhook stays gone.
		$this->assertSame(['notify', 'create_talk_room', 'create_deck_board'], $s->availableActions($all, true));
	}

	public function testCustomLimitsAndDeckColumns(): void {
		$s = $this->settings(
			['automation_deck_columns' => 'Backlog, Doing, Review, Done'],
			['automation_max_participants' => 5, 'automation_outbound_timeout' => 25],
		);
		$this->assertSame(5, $s->maxParticipants());
		$this->assertSame(25, $s->outboundTimeout());
		$this->assertSame(['Backlog', 'Doing', 'Review', 'Done'], $s->defaultDeckColumns());
	}

	public function testNonPositiveLimitFallsBackToDefault(): void {
		$s = $this->settings([], ['automation_max_folders' => 0]);
		$this->assertSame(50, $s->maxFolders());
	}

	public function testNeedsServiceAccount(): void {
		$s = $this->settings();
		$this->assertTrue($s->needsServiceAccount('create_talk_room'));
		$this->assertTrue($s->needsServiceAccount('create_deck_board'));
		$this->assertFalse($s->needsServiceAccount('notify'));
	}

	public function testRemainingLimitGetters(): void {
		$s = $this->settings([], ['automation_max_template_files' => 7, 'automation_max_deck_columns' => 3, 'automation_max_created' => 9]);
		$this->assertSame(7, $s->maxTemplateFiles());
		$this->assertSame(3, $s->maxDeckColumns());
		$this->assertSame(9, $s->maxCreated());
	}

	public function testToArraySnapshot(): void {
		$snap = $this->settings()->toArray();
		$this->assertSame([], $snap['disabled']);
		$this->assertSame(50, $snap['limits']['maxFolders']);
		$this->assertSame('To do, Doing, Done', $snap['deckColumns']);
		$this->assertSame(WorkflowSettings::DEFAULTS, $snap['defaults']);
	}

	/** A config mock that records writes/deletes. */
	private function writableSettings(array &$writes, array &$deletes): WorkflowSettings {
		$cfg = $this->createMock(IAppConfig::class);
		$cfg->method('getValueString')->willReturnCallback(static fn (string $a, string $k, string $d = ''): string => $d);
		$cfg->method('getValueInt')->willReturnCallback(static fn (string $a, string $k, int $d = 0): int => $d);
		$cfg->method('setValueString')->willReturnCallback(function (string $a, string $k, string $v) use (&$writes) {
			$writes[$k] = $v;
			return true;
		});
		$cfg->method('setValueInt')->willReturnCallback(function (string $a, string $k, int $v) use (&$writes) {
			$writes[$k] = $v;
			return true;
		});
		$cfg->method('deleteKey')->willReturnCallback(function (string $a, string $k) use (&$deletes): void {
			$deletes[] = $k;
		});
		return new WorkflowSettings($cfg);
	}

	public function testSetDisabledActionsCleansAndStores(): void {
		$writes = [];
		$deletes = [];
		$this->writableSettings($writes, $deletes)->setDisabledActions(['webhook', '', 'webhook', 'email']);
		$this->assertSame(['webhook', 'email'], json_decode($writes['automation_disabled'], true));
	}

	public function testSetLimitsStoresOverridesAndResetsDefaults(): void {
		$writes = [];
		$deletes = [];
		$this->writableSettings($writes, $deletes)->setLimits([
			'maxFolders' => 80,                 // override → stored
			'maxParticipants' => 100,           // equals default → reset (delete)
			'outboundTimeout' => 0,             // non-positive → reset (delete)
			'unknownKey' => 5,                  // ignored
		]);
		$this->assertSame(80, $writes['automation_max_folders']);
		$this->assertContains('automation_max_participants', $deletes);
		$this->assertContains('automation_outbound_timeout', $deletes);
	}

	public function testSetDeckColumnsStoresOrResets(): void {
		$writes = [];
		$deletes = [];
		$s = $this->writableSettings($writes, $deletes);
		$s->setDeckColumns('A, B, C');
		$this->assertSame('A, B, C', $writes['automation_deck_columns']);
		$s->setDeckColumns('To do, Doing, Done'); // the default → reset
		$this->assertContains('automation_deck_columns', $deletes);
	}
}
