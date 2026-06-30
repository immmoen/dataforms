<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\RecordFileMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\FieldService;
use OCA\Dataforms\Service\RegisterService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The field/schema service: creation with type-specific config across all 20
 * types, the immutable machine name, uniqueness of the machine name, defaults,
 * the mandatory flag, soft-delete (name tombstone) and reordering.
 */
class FieldServiceTest extends TestCase {
	private FieldMapper&MockObject $mapper;
	private RegisterService&MockObject $registerService;
	private RecordValueMapper&MockObject $valueMapper;
	private RecordFileMapper&MockObject $fileMapper;
	private RecordRefMapper&MockObject $refMapper;
	private ITimeFactory&MockObject $time;
	private FieldService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(FieldMapper::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->valueMapper = $this->createMock(RecordValueMapper::class);
		$this->fileMapper = $this->createMock(RecordFileMapper::class);
		$this->refMapper = $this->createMock(RecordRefMapper::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->registerService->method('findManageable')->willReturn(new Register());
		$this->registerService->method('find')->willReturn(new Register());
		$this->mapper->method('maxPosition')->willReturn(2);
		$this->mapper->method('machineNameExists')->willReturn(false);
		$this->service = new FieldService(
			$this->mapper, $this->registerService, $this->valueMapper,
			$this->fileMapper, $this->refMapper, $this->time,
		);
	}

	/** Create a field and return the entity handed to the mapper. */
	private function created(array $data): Field {
		$captured = null;
		$this->mapper->method('insert')->willReturnCallback(function (Field $f) use (&$captured) {
			$captured = $f;
			return $f;
		});
		$this->service->create('alice', 5, $data);
		return $captured;
	}

	/** Decoded config JSON of a created field. */
	private function config(array $data): array {
		return json_decode($this->created($data)->getConfig(), true);
	}

	// ---- create: validation ----------------------------------------------

	public function testCreateRejectsUnknownType(): void {
		$this->expectException(ValidationException::class);
		$this->service->create('alice', 5, ['type' => 'nope', 'label' => 'X']);
	}

	public function testCreateRequiresALabel(): void {
		$this->expectException(ValidationException::class);
		$this->service->create('alice', 5, ['type' => 'text', 'label' => '  ']);
	}

	public function testCreateRejectsAnInvalidMachineName(): void {
		$this->expectException(ValidationException::class);
		$this->service->create('alice', 5, ['type' => 'text', 'label' => 'X', 'machineName' => '9bad']);
	}

	// ---- create: machine name + position + flags --------------------------

	public function testCreateSlugifiesLabelAndSetsPositionAndFlags(): void {
		$f = $this->created(['type' => 'text', 'label' => 'Café Name!', 'mandatory' => true, 'unique' => true, 'default' => 'd']);
		$this->assertSame('caf_name', $f->getMachineName());
		$this->assertSame(3, $f->getPosition()); // maxPosition(2) + 1
		$this->assertTrue($f->getMandatory());
		$this->assertTrue($f->getIsUnique());
		$this->assertSame('d', $f->getDefaultValue());
	}

	public function testSlugifyPrefixesWhenLabelHasNoLeadingLetter(): void {
		$this->assertSame('field_123', $this->created(['type' => 'text', 'label' => '123'])->getMachineName());
	}

	public function testCreateNormalisesAnExplicitMachineName(): void {
		$this->assertSame('my_field', $this->created(['type' => 'text', 'label' => 'X', 'machineName' => 'my_field'])->getMachineName());
	}

	public function testCreateAppendsSuffixWhenMachineNameTaken(): void {
		$this->mapper = $this->createMock(FieldMapper::class);
		$this->mapper->method('maxPosition')->willReturn(0);
		// 'title' exists, 'title_2' free.
		$this->mapper->method('machineNameExists')->willReturnCallback(fn ($r, $n) => $n === 'title');
		$this->service = new FieldService($this->mapper, $this->registerService, $this->valueMapper, $this->fileMapper, $this->refMapper, $this->time);
		$this->assertSame('title_2', $this->created(['type' => 'text', 'label' => 'Title'])->getMachineName());
	}

	// ---- create: type-specific config (encodeConfig) ----------------------

	public function testSelectConfigDedupesOptionsAndKeepsGrouping(): void {
		$c = $this->config(['type' => 'select', 'label' => 'S', 'config' => [
			'options' => ['a', ' a ', 'b', ''], 'allowOther' => true, 'groupPattern' => '^x',
		]]);
		$this->assertSame(['a', 'b'], $c['options']);
		$this->assertTrue($c['allowOther']);
		$this->assertSame('^x', $c['groupPattern']);
	}

	public function testNumberConfigClampsDecimalsAndCurrencyDefaultsToTwo(): void {
		$num = $this->config(['type' => 'number', 'label' => 'N', 'config' => ['min' => '1', 'max' => '9', 'decimals' => 99]]);
		$this->assertEquals(1, $num['min']);
		$this->assertEquals(9, $num['max']);
		$this->assertSame(6, $num['decimals']); // clamped to max 6
		$cur = $this->config(['type' => 'currency', 'label' => 'C', 'config' => []]);
		$this->assertSame(2, $cur['decimals']);
	}

	public function testTextMaxLengthConfig(): void {
		$this->assertSame(50, $this->config(['type' => 'text', 'label' => 'T', 'config' => ['maxLength' => 50]])['maxLength']);
	}

	public function testRelationConfigRequiresTargetAndValidatesOnDelete(): void {
		$c = $this->config(['type' => 'relation', 'label' => 'R', 'config' => [
			'targetRegisterId' => 9, 'displayField' => 'name', 'multiple' => true, 'onDelete' => 'cascade',
		]]);
		$this->assertSame(9, $c['targetRegisterId']);
		$this->assertSame('cascade', $c['onDelete']);
		$this->assertTrue($c['multiple']);
		// invalid onDelete falls back to null
		$this->assertSame('null', $this->config(['type' => 'relation', 'label' => 'R', 'config' => ['targetRegisterId' => 9, 'onDelete' => 'bogus']])['onDelete']);
	}

	public function testRelationWithoutTargetIsRejected(): void {
		$this->expectException(ValidationException::class);
		$this->service->create('alice', 5, ['type' => 'relation', 'label' => 'R', 'config' => []]);
	}

	public function testComputedRequiresAnExpression(): void {
		$this->assertSame('1+1', $this->config(['type' => 'computed', 'label' => 'C', 'config' => ['expression' => '1+1']])['expression']);
		$this->expectException(ValidationException::class);
		$this->service->create('alice', 5, ['type' => 'computed', 'label' => 'C', 'config' => []]);
	}

	public function testAutoKindValidatedAndHelpStored(): void {
		$this->assertSame('sequence', $this->config(['type' => 'auto', 'label' => 'A', 'config' => ['kind' => 'sequence']])['kind']);
		$this->assertSame('created_at', $this->config(['type' => 'auto', 'label' => 'A', 'config' => ['kind' => 'bogus']])['kind']);
		$this->assertSame('Help me', $this->config(['type' => 'text', 'label' => 'T', 'config' => ['help' => 'Help me']])['help']);
	}

	public function testEveryDeclaredTypeIsAccepted(): void {
		$this->mapper->method('insert')->willReturnArgument(0);
		foreach (FieldService::TYPES as $type) {
			$config = match ($type) {
				'relation' => ['targetRegisterId' => 9],
				'computed' => ['expression' => 'x'],
				default => [],
			};
			$this->service->create('alice', 5, ['type' => $type, 'label' => 'L', 'config' => $config]);
		}
		$this->assertCount(20, FieldService::TYPES);
	}

	// ---- update / delete / reorder ----------------------------------------

	public function testUpdateChangesLabelConfigFlagsButNotMachineName(): void {
		$existing = (new Field());
		$existing->setId(7);
		$existing->setRegisterId(5);
		$existing->setType('text');
		$existing->setMachineName('locked');
		$this->mapper->method('find')->willReturn($existing);
		$this->mapper->method('update')->willReturnArgument(0);

		$updated = $this->service->update('alice', 7, ['label' => 'New', 'config' => ['maxLength' => 12], 'mandatory' => true, 'unique' => true, 'default' => null, 'machineName' => 'hacked']);
		$this->assertSame('New', $updated->getLabel());
		$this->assertTrue($updated->getMandatory());
		$this->assertNull($updated->getDefaultValue());
		$this->assertSame('locked', $updated->getMachineName()); // immutable
		$this->assertSame(12, json_decode($updated->getConfig(), true)['maxLength']);
	}

	public function testUpdateRejectsAnEmptyLabel(): void {
		$existing = new Field();
		$existing->setId(7);
		$existing->setRegisterId(5);
		$existing->setType('text');
		$this->mapper->method('find')->willReturn($existing);
		$this->expectException(ValidationException::class);
		$this->service->update('alice', 7, ['label' => '   ']);
	}

	public function testUpdateMapsMissingFieldToNotFound(): void {
		$this->mapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->expectException(NotFoundException::class);
		$this->service->update('alice', 7, ['label' => 'New']);
	}

	public function testDeleteTombstonesAndCleansChildValues(): void {
		$existing = new Field();
		$existing->setId(7);
		$existing->setRegisterId(5);
		$this->mapper->method('find')->willReturn($existing);
		$this->time->method('getTime')->willReturn(4242);
		$this->valueMapper->expects($this->once())->method('deleteByField')->with(7);
		$this->fileMapper->expects($this->once())->method('deleteForField')->with(7);
		$this->refMapper->expects($this->once())->method('deleteForField')->with(7);
		$captured = null;
		$this->mapper->method('update')->willReturnCallback(function (Field $f) use (&$captured) {
			$captured = $f;
			return $f;
		});
		$this->service->delete('alice', 7);
		$this->assertSame(4242, $captured->getDeletedAt());
	}

	public function testReorderAssignsPositionsToKnownIdsOnly(): void {
		$a = new Field();
		$a->setId(1);
		$a->setRegisterId(5);
		$b = new Field();
		$b->setId(2);
		$b->setRegisterId(5);
		$this->mapper->method('findByRegister')->willReturn([$a, $b]);
		$updated = [];
		$this->mapper->method('update')->willReturnCallback(function (Field $f) use (&$updated) {
			$updated[$f->getId()] = $f->getPosition();
			return $f;
		});
		$this->service->reorder('alice', 5, [2, 1, 999]); // 999 unknown → ignored
		$this->assertSame(0, $updated[2]);
		$this->assertSame(1, $updated[1]);
		$this->assertArrayNotHasKey(999, $updated);
	}

	public function testListForRegisterReadsThroughTheGate(): void {
		$this->mapper->method('findByRegister')->with(5)->willReturn([new Field()]);
		$this->assertCount(1, $this->service->listForRegister('alice', 5));
	}
}
