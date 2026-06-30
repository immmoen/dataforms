<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Controller;

use OCA\Dataforms\Controller\RuleController;
use OCA\Dataforms\Db\Rule;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\RuleService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class RuleControllerTest extends TestCase {
	private RuleService $service;
	private ISession $appSession;
	private RuleController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(RuleService::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$this->appSession = $this->createMock(ISession::class);
		$this->controller = new RuleController($this->createMock(IRequest::class), $this->service, $session, $this->appSession);
	}

	public function testIndexReleasesTheSessionLockAndLists(): void {
		$this->appSession->expects($this->once())->method('close');
		$this->service->method('listForRegister')->with('alice', 2)->willReturn([['id' => 1]]);
		$this->assertSame([['id' => 1]], $this->controller->index(2)->getData());
	}

	public function testCreatePassesTheCompactedRuleAndReturnsCreated(): void {
		$this->service->expects($this->once())->method('create')
			->with('alice', 2, [
				'effect' => 'show', 'target' => 'f', 'conditions' => null, 'value' => null,
				'expression' => null, 'validation' => null, 'enabled' => true,
			])->willReturn(new Rule());
		$res = $this->controller->create(2, 'show', 'f');
		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
	}

	public function testCreateMapsValidation(): void {
		$this->service->method('create')->willThrowException(new ValidationException('Unknown rule effect: x'));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create(2, 'x', 'f')->getStatus());
	}

	public function testUpdateFiltersNullsAndUpdates(): void {
		$this->service->expects($this->once())->method('update')
			->with('alice', 9, ['effect' => 'require', 'enabled' => false])->willReturn(new Rule());
		$this->controller->update(9, 'require', null, null, null, null, null, false);
	}

	public function testDestroy(): void {
		$this->service->expects($this->once())->method('delete')->with('alice', 9);
		$this->assertSame([], $this->controller->destroy(9)->getData());
	}
}
