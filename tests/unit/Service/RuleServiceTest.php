<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Rule;
use OCA\Dataforms\Db\RuleMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\RegisterService;
use OCA\Dataforms\Service\RuleService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * RuleService at the service seam: the manage-gate, effect/target validation,
 * the enabled-only definition projection fed to the evaluator, and the
 * definition JSON encode/decode round-trip. The mapper and RegisterService are
 * mocked; rule semantics are proven elsewhere (the shared-fixture parity suite).
 */
class RuleServiceTest extends TestCase {
	private RuleMapper&MockObject $mapper;
	private RegisterService&MockObject $registerService;
	private RuleService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(RuleMapper::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->service = new RuleService($this->mapper, $this->registerService);
	}

	private function rule(int $id, string $effect, string $target, bool $enabled = true, ?array $def = null): Rule {
		$r = new Rule();
		$r->setId($id);
		$r->setRegisterId(5);
		$r->setEffect($effect);
		$r->setTarget($target);
		$r->setEnabled($enabled);
		$r->setDefinition($def === null ? '{}' : json_encode($def));
		return $r;
	}

	public function testListForRegisterReadGatesThenReturnsRules(): void {
		$this->registerService->expects($this->once())->method('find')->with('alice', 5);
		$rules = [$this->rule(1, 'show', 'a')];
		$this->mapper->method('findByRegister')->with(5)->willReturn($rules);
		$this->assertSame($rules, $this->service->listForRegister('alice', 5));
	}

	public function testDefinitionsForRegisterReturnsOnlyEnabledRulesAsArrays(): void {
		$this->mapper->method('findByRegister')->willReturn([
			$this->rule(1, 'compute', 'risk', true, ['expression' => 'a * b']),
			$this->rule(2, 'show', 'extra', false), // disabled — excluded
		]);
		$defs = $this->service->definitionsForRegister(5);
		$this->assertCount(1, $defs);
		$this->assertSame('compute', $defs[0]['effect']);
		$this->assertSame('a * b', $defs[0]['expression']);
	}

	public function testCreateValidatesEffectAndTarget(): void {
		$this->registerService->method('findManageable');

		try {
			$this->service->create('alice', 5, ['effect' => 'bogus', 'target' => 't']);
			$this->fail('expected unknown-effect rejection');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('Unknown rule effect', $e->getMessage());
		}

		try {
			$this->service->create('alice', 5, ['effect' => 'show', 'target' => '  ']);
			$this->fail('expected empty-target rejection');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('target field is required', $e->getMessage());
		}
	}

	public function testCreatePersistsTheEncodedRule(): void {
		$this->registerService->expects($this->once())->method('findManageable')->with('alice', 5);
		$captured = null;
		$this->mapper->method('insert')->willReturnCallback(function (Rule $r) use (&$captured): Rule {
			$captured = $r;
			$r->setId(7);
			return $r;
		});

		$out = $this->service->create('alice', 5, [
			'effect' => 'validate',
			'target' => 'score',
			'validation' => ['kind' => 'range', 'min' => 0, 'max' => 10],
			'position' => 3,
			'enabled' => false,
		]);

		$this->assertSame(7, $out->getId());
		$this->assertSame('validate', $captured->getEffect());
		$this->assertSame('score', $captured->getTarget());
		$this->assertSame(3, $captured->getPosition());
		$this->assertFalse($captured->getEnabled());
		// The definition carries the validation payload (and null holes).
		$def = json_decode((string)$captured->getDefinition(), true);
		$this->assertSame(['kind' => 'range', 'min' => 0, 'max' => 10], $def['validation']);
		$this->assertNull($def['expression']);
	}

	public function testUpdateAppliesOnlyProvidedFields(): void {
		$existing = $this->rule(7, 'show', 'old', true, ['conditions' => ['logic' => 'and', 'rules' => []]]);
		$this->mapper->method('find')->with(7)->willReturn($existing);
		$this->registerService->expects($this->once())->method('findManageable')->with('alice', 5);
		$this->mapper->method('update')->willReturnArgument(0);

		$out = $this->service->update('alice', 7, ['target' => 'new', 'effect' => 'require', 'enabled' => false, 'position' => 9]);

		$this->assertSame('new', $out->getTarget());
		$this->assertSame('require', $out->getEffect());
		$this->assertFalse($out->getEnabled());
		$this->assertSame(9, $out->getPosition());
	}

	public function testUpdateIgnoresBlankTargetAndUnknownEffect(): void {
		$existing = $this->rule(7, 'show', 'keep');
		$this->mapper->method('find')->willReturn($existing);
		$this->registerService->method('findManageable');
		$this->mapper->method('update')->willReturnArgument(0);

		$out = $this->service->update('alice', 7, ['target' => '   ', 'effect' => 'nonsense']);
		$this->assertSame('keep', $out->getTarget()); // blank target ignored
		$this->assertSame('show', $out->getEffect()); // unknown effect ignored
	}

	public function testDeleteRemovesAfterManageGate(): void {
		$existing = $this->rule(7, 'show', 't');
		$this->mapper->method('find')->willReturn($existing);
		$this->registerService->expects($this->once())->method('findManageable')->with('alice', 5);
		$this->mapper->expects($this->once())->method('delete')->with($existing);
		$this->service->delete('alice', 7);
	}

	public function testMissingRuleMapsToNotFound(): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->expectException(NotFoundException::class);
		$this->service->delete('alice', 999);
	}
}
