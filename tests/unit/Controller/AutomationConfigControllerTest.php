<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\AutomationConfigController;
use OCA\Dataforms\Service\ServiceAccountService;
use OCA\Dataforms\Service\WorkflowSettings;
use OCA\Dataforms\Workflow\ActionRegistry;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * AutomationConfigController: the admin status/save endpoints for instance-wide
 * automation settings. Asserts the per-action enabled flags and that save only
 * stores known action ids (AUT-21).
 */
class AutomationConfigControllerTest extends TestCase {
	private function controller(WorkflowSettings $settings): array {
		$registry = $this->createMock(ActionRegistry::class);
		$registry->method('types')->willReturn(['notify', 'webhook', 'create_talk_room']);
		$account = $this->createMock(ServiceAccountService::class);
		$account->method('isConfigured')->willReturn(false);
		return [new AutomationConfigController($this->createMock(IRequest::class), $settings, $registry, $account), $registry];
	}

	public function testStatusReportsPerActionEnabledFlags(): void {
		$settings = $this->createMock(WorkflowSettings::class);
		$settings->method('disabledActions')->willReturn(['webhook']);
		$settings->method('needsServiceAccount')->willReturnCallback(static fn (string $t) => $t === 'create_talk_room');
		$settings->method('toArray')->willReturn(['limits' => ['maxFolders' => 50], 'deckColumns' => 'To do, Doing, Done', 'defaults' => []]);
		[$controller] = $this->controller($settings);

		$data = $controller->status()->getData();
		$byType = [];
		foreach ($data['actions'] as $a) {
			$byType[$a['type']] = $a;
		}
		$this->assertTrue($byType['notify']['enabled']);
		$this->assertFalse($byType['webhook']['enabled']); // disabled
		$this->assertTrue($byType['create_talk_room']['needsServiceAccount']);
		$this->assertFalse($data['serviceAccountConfigured']);
	}

	public function testSaveStoresOnlyKnownActionIdsAndPersistsLimits(): void {
		$settings = $this->createMock(WorkflowSettings::class);
		$settings->method('toArray')->willReturn(['limits' => [], 'deckColumns' => '', 'defaults' => []]);
		$captured = null;
		$settings->method('setDisabledActions')->willReturnCallback(function (array $types) use (&$captured): void {
			$captured = $types;
		});
		$settings->expects($this->once())->method('setLimits')->with(['maxFolders' => 10]);
		$settings->expects($this->once())->method('setDeckColumns')->with('A, B');
		[$controller] = $this->controller($settings);

		$controller->save(['webhook', 'bogus', 'notify'], ['maxFolders' => 10], 'A, B');
		$this->assertSame(['webhook', 'notify'], $captured); // 'bogus' filtered out
	}
}
