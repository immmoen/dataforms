<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Event\RecordCreatedEvent;
use OCA\Dataforms\Exception\ValidationException;

/**
 * create() / createForImport(): validate + compute, the atomic multi-table
 * write (record header + values + files + refs + history), and the create
 * event — frozen before the #8 split.
 */
class RecordServiceCreateTest extends RecordServiceTestCase {
	/** Wire recordMapper->insert to assign an id, and seq lookup. */
	private function expectInsertAssigns(int $id, int $maxSeq = 0): void {
		$this->recordMapper->method('maxSeqForRegister')->willReturn($maxSeq);
		$this->recordMapper->method('insert')->willReturnCallback(static function (Record $r) use ($id): Record {
			$r->setId($id);
			return $r;
		});
	}

	public function testCreateWritesEveryTypedValueAndReturnsTheDto(): void {
		$fields = [
			$this->field('text', 'title'),
			$this->field('number', 'qty'),
			$this->field('boolean', 'done'),
		];
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->expectInsertAssigns(42, 6);

		// Capture the per-(record,field) value writes.
		$writes = [];
		$this->valueMapper->method('insertValue')
			->willReturnCallback(function (int $recordId, int $fieldId, string $column, $value) use (&$writes): void {
				$writes[] = [$recordId, $fieldId, $column, $value];
			});
		// Read-back for the returned DTO.
		$this->valueMapper->method('findByRecordIds')->willReturn([42 => [
			$this->valueRow($fields[0], 'Laptop') + ['record_id' => 42],
			$this->valueRow($fields[1], 3) + ['record_id' => 42],
			$this->valueRow($fields[2], true) + ['record_id' => 42],
		]]);

		$dto = $this->service->create('alice', 5, ['title' => 'Laptop', 'qty' => 3, 'done' => true]);

		$this->assertSame(42, $dto['id']);
		$this->assertSame('Laptop', $dto['values']['title']);
		$this->assertSame(3.0, $dto['values']['qty']);
		$this->assertTrue($dto['values']['done']);

		// One typed write per field, to the correct column. seq is max+1.
		$this->assertContains([42, $fields[0]->getId(), 'value_string', 'Laptop'], $writes);
		$this->assertContains([42, $fields[1]->getId(), 'value_number', 3.0], $writes);
		$this->assertContains([42, $fields[2]->getId(), 'value_bool', 1], $writes);
	}

	public function testCreateIsAtomicAndDispatchesAfterCommit(): void {
		$order = [];
		$this->db->method('beginTransaction')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'begin';
		});
		$this->db->method('commit')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'commit';
		});
		$this->eventDispatcher->method('dispatchTyped')->willReturnCallback(function (object $e) use (&$order): void {
			$order[] = 'dispatch';
			$this->dispatched[] = $e;
		});

		$fields = [$this->field('text', 'title'), $this->field('auto', 'ref', ['kind' => 'sequence'])];
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->expectInsertAssigns(7, 2);
		$this->valueMapper->method('findByRecordIds')->willReturn([7 => []]);

		$this->service->create('alice', 5, ['title' => 'x']);

		// The write commits before the event ever fires (no half-written dispatch).
		$this->assertSame(['begin', 'commit', 'dispatch'], $order);
		$this->assertInstanceOf(RecordCreatedEvent::class, $this->dispatched[0]);
		// The event payload carries the resolved sequence auto value (3 = seq).
		$this->assertSame('3', $this->dispatched[0]->getValues()['ref']);
	}

	public function testCreateRollsBackAndDoesNotDispatchOnWriteFailure(): void {
		$rolled = false;
		$this->db->method('rollBack')->willReturnCallback(static function () use (&$rolled): void {
			$rolled = true;
		});
		$this->db->expects($this->never())->method('commit');

		$fields = [$this->field('text', 'title')];
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->recordMapper->method('maxSeqForRegister')->willReturn(0);
		$this->recordMapper->method('insert')->willThrowException(new \RuntimeException('db down'));

		try {
			$this->service->create('alice', 5, ['title' => 'x']);
			$this->fail('expected the write failure to propagate');
		} catch (\RuntimeException $e) {
			$this->assertSame('db down', $e->getMessage());
		}
		$this->assertTrue($rolled, 'a mid-write failure must roll back');
		$this->assertSame([], $this->dispatched, 'no event on a rolled-back write');
	}

	public function testCreateRejectsInvalidFieldConfig(): void {
		$fields = [$this->field('email', 'contact')];
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->recordMapper->expects($this->never())->method('insert');

		try {
			$this->service->create('alice', 5, ['contact' => 'not-an-email']);
			$this->fail('expected ValidationException');
		} catch (ValidationException $e) {
			$this->assertArrayHasKey('contact', $e->getErrors());
		}
	}

	public function testHiddenFieldValueIsNotPersisted(): void {
		// A show-rule hides `secret` unless title === 'open'; here it stays hidden,
		// so its submitted value must be dropped (authoritative server-side).
		$fields = [$this->field('text', 'title'), $this->field('text', 'secret')];
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->rules = [
			['effect' => 'show', 'target' => 'secret', 'conditions' => ['logic' => 'and', 'rules' => [['field' => 'title', 'op' => 'eq', 'value' => 'open']]]],
		];
		$this->expectInsertAssigns(9);
		$this->valueMapper->method('findByRecordIds')->willReturn([9 => []]);

		$writes = [];
		$this->valueMapper->method('insertValue')
			->willReturnCallback(static function (int $r, int $f, string $c, $v) use (&$writes): void {
				$writes[] = $c;
			});

		$this->service->create('alice', 5, ['title' => 'closed', 'secret' => 'leak']);

		// Only the visible title is written; the hidden field's value is suppressed.
		$this->assertSame(['value_string'], $writes);
	}

	public function testComputedFieldIsEvaluatedServerSide(): void {
		$fields = [
			$this->field('number', 'price'),
			$this->field('number', 'qty'),
			$this->field('computed', 'total', ['expression' => 'price * qty']),
		];
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->expectInsertAssigns(11);
		$this->valueMapper->method('findByRecordIds')->willReturn([11 => []]);

		$writes = [];
		$this->valueMapper->method('insertValue')
			->willReturnCallback(static function (int $r, int $fid, string $c, $v) use (&$writes): void {
				$writes[$fid] = $v;
			});

		$this->service->create('alice', 5, ['price' => 4, 'qty' => 3, 'total' => 999]);

		// The submitted `total` is ignored; the expression result is stored. A
		// computed field has no typed column of its own, so it lands in
		// value_string as the stringified result '12' (frozen behaviour).
		$this->assertSame('12', $writes[$fields[2]->getId()]);
	}

	public function testComputedFieldExpressionErrorStoresNull(): void {
		$fields = [$this->field('computed', 'broken', ['expression' => 'nope('])];
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->expectInsertAssigns(12);
		$this->valueMapper->method('findByRecordIds')->willReturn([12 => []]);

		$wrote = false;
		$this->valueMapper->method('insertValue')->willReturnCallback(static function () use (&$wrote): void {
			$wrote = true;
		});

		$this->service->create('alice', 5, []);
		// A null computed value writes no row (empty -> skipped).
		$this->assertFalse($wrote);
	}

	public function testCreateForImportWritesWithoutTransactionOrEvent(): void {
		$this->db->expects($this->never())->method('beginTransaction');
		$fields = [$this->field('text', 'title')];
		$this->recordMapper->method('maxSeqForRegister')->willReturn(0);
		$this->recordMapper->method('insert')->willReturnCallback(static function (Record $r): Record {
			$r->setId(3);
			return $r;
		});

		$this->service->createForImport('alice', 5, $fields, ['title' => 'bulk']);

		$this->assertSame([], $this->dispatched, 'a bulk import must not fire per-row events');
	}
}
