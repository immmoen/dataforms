<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\FieldValue;
use OCA\Dataforms\Service\RecordRelationService;
use OCA\Dataforms\Service\RegisterService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * RecordRelationService at its own seam (#8 split): relation storage with the
 * cross-register integrity gate, read-side label resolution behind the read
 * gate, and the on-delete policies. Exercised independently of RecordService.
 */
class RecordRelationServiceTest extends TestCase {
	private RecordRefMapper&MockObject $refMapper;
	private RecordMapper&MockObject $recordMapper;
	private FieldMapper&MockObject $fieldMapper;
	private RecordValueMapper&MockObject $valueMapper;
	private RegisterService&MockObject $registerService;
	private ITimeFactory&MockObject $time;
	private RecordRelationService $service;

	protected function setUp(): void {
		$this->refMapper = $this->createMock(RecordRefMapper::class);
		$this->recordMapper = $this->createMock(RecordMapper::class);
		$this->fieldMapper = $this->createMock(FieldMapper::class);
		$this->valueMapper = $this->createMock(RecordValueMapper::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1_700_000_000);
		$this->service = new RecordRelationService($this->refMapper, $this->recordMapper, $this->fieldMapper, $this->valueMapper, $this->registerService, $this->time);
	}

	/** @param array<string,mixed>|null $config */
	private function field(string $type, string $machineName, ?array $config = null, int $id = 100): Field {
		$f = new Field();
		$f->setId($id);
		$f->setType($type);
		$f->setMachineName($machineName);
		$f->setLabel(ucfirst($machineName));
		$f->setConfig($config === null ? null : json_encode($config));
		return $f;
	}

	private function valueRow(Field $field, $logical): array {
		$row = ['field_id' => $field->getId(), 'value_string' => null, 'value_number' => null, 'value_datetime' => null, 'value_bool' => null, 'value_file_id' => null, 'value_ref_record_id' => null];
		$p = FieldValue::toStorage($field->getType(), $logical);
		if ($p['column'] !== '') {
			$row[$p['column']] = $p['value'];
		}
		return $row;
	}

	public function testStoreRefsRejectsTargetOutsideRegister(): void {
		$links = $this->field('relation', 'links', ['targetRegisterId' => 9], 100);
		$this->recordMapper->method('existingIdsInRegister')->willReturn([50]); // 51 invalid
		$this->refMapper->expects($this->never())->method('insertRef');
		$this->expectException(ValidationException::class);
		$this->service->storeRefs(1, [$links], ['links' => [50, 51]]);
	}

	public function testResolveRelationsAnonymisesUnreadableTarget(): void {
		$parent = $this->field('relation', 'parent', ['targetRegisterId' => 9, 'multiple' => false], 100);
		$this->registerService->method('find')->willThrowException(new NotFoundException('no'));
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [['field_id' => 100, 'target_record_id' => 50, 'position' => 0]],
		]);
		$out = $this->service->resolveRelations('alice', [$parent], [['id' => 3, 'values' => []]]);
		$this->assertSame(['id' => 50, 'label' => '#50'], $out[0]['values']['parent']);
	}

	public function testResolveRelationsLabelsReadableTarget(): void {
		$parent = $this->field('relation', 'parent', ['targetRegisterId' => 9, 'displayField' => 'name', 'multiple' => false], 100);
		$name = $this->field('text', 'name', null, 200);
		$this->registerService->method('find'); // readable
		$this->fieldMapper->method('findByRegister')->willReturn([$name]);
		$this->valueMapper->method('findByRecordIds')->willReturn([50 => [$this->valueRow($name, 'Acme')]]);
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [['field_id' => 100, 'target_record_id' => 50, 'position' => 0]],
		]);
		$out = $this->service->resolveRelations('alice', [$parent], [['id' => 3, 'values' => []]]);
		$this->assertSame(['id' => 50, 'label' => 'Acme'], $out[0]['values']['parent']);
	}

	public function testReferentialIntegrityBlockThrows(): void {
		$this->refMapper->method('findReferencingTarget')->willReturn([['id' => 1, 'record_id' => 9, 'field_id' => 77]]);
		$this->fieldMapper->method('find')->willReturn($this->field('relation', 'p', ['onDelete' => 'block'], 77));
		$this->expectException(ValidationException::class);
		$this->service->enforceReferentialIntegrity(5);
	}

	public function testReferentialIntegrityCascadeSoftDeletesAndNullDrops(): void {
		// cascade: the referencing record is soft-deleted; then dangling refs dropped.
		$this->refMapper->method('findReferencingTarget')->willReturn([['id' => 1, 'record_id' => 9, 'field_id' => 77]]);
		$this->fieldMapper->method('find')->willReturn($this->field('relation', 'p', ['onDelete' => 'cascade'], 77));
		$referencing = new Record();
		$referencing->setId(9);
		$this->recordMapper->method('find')->willReturn($referencing);

		$tombstoned = null;
		$this->recordMapper->method('update')->willReturnCallback(static function (Record $r) use (&$tombstoned): Record {
			$tombstoned = $r->getDeletedAt();
			return $r;
		});
		$dropped = [];
		$this->refMapper->method('deleteRefsToTarget')->willReturnCallback(static function (int $t, int $f) use (&$dropped): void {
			$dropped[] = [$t, $f];
		});

		$this->service->enforceReferentialIntegrity(5);
		$this->assertSame(1_700_000_000, $tombstoned);
		$this->assertContains([5, 77], $dropped);
	}

	public function testReferentialIntegrityNoReferencesIsNoop(): void {
		$this->refMapper->method('findReferencingTarget')->willReturn([]);
		$this->refMapper->expects($this->never())->method('deleteRefsToTarget');
		$this->service->enforceReferentialIntegrity(5);
		$this->addToAssertionCount(1);
	}

	public function testLabelsFallBackWhenFieldLookupMissing(): void {
		// Referencing field definition gone → policy falls back to 'null', drops refs.
		$this->refMapper->method('findReferencingTarget')->willReturn([['id' => 1, 'record_id' => 9, 'field_id' => 77]]);
		$this->fieldMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$dropped = false;
		$this->refMapper->method('deleteRefsToTarget')->willReturnCallback(static function () use (&$dropped): void {
			$dropped = true;
		});
		$this->service->enforceReferentialIntegrity(5);
		$this->assertTrue($dropped);
	}

	public function testLabelsForRecordsFallsBackToFirstTextField(): void {
		$name = $this->field('select', 'name', null, 200);
		$this->fieldMapper->method('findByRegister')->willReturn([$this->field('number', 'n', null, 201), $name]);
		$this->valueMapper->method('findByRecordIds')->willReturn([50 => [$this->valueRow($name, 'Chosen')]]);
		$labels = $this->service->labelsForRecords(9, [50], ''); // no displayField → first text-like
		$this->assertSame('Chosen', $labels[50]);
		$this->assertSame([], $this->service->labelsForRecords(9, [], 'name')); // empty short-circuit
	}
}
