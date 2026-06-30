<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Event\RecordDeletedEvent;
use OCA\Dataforms\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * delete(): the soft-delete inside a transaction, with referential-integrity
 * enforcement (block / cascade / null on-delete policies) applied atomically
 * before the row is tombstoned. Frozen before the #8 split.
 */
class RecordServiceDeleteTest extends RecordServiceTestCase {
	/**
	 * @param array<int,Record> $extra additional records resolvable by find()
	 */
	private function arrangeDelete(Record $record, array $extra = []): void {
		$byId = [$record->getId() => $record] + $extra;
		$this->recordMapper->method('find')->willReturnCallback(static function (int $id) use ($byId): Record {
			if (!isset($byId[$id])) {
				throw new DoesNotExistException('gone');
			}
			return $byId[$id];
		});
		$this->registerService->method('find')->willReturn($this->register());
		$this->registerService->method('findWritable')->willReturn($this->register());
	}

	public function testDeleteSoftDeletesAndDispatches(): void {
		$record = $this->record(8);
		$this->arrangeDelete($record);
		$this->refMapper->method('findReferencingTarget')->willReturn([]); // nothing points here

		$updated = null;
		$this->recordMapper->method('update')->willReturnCallback(static function (Record $r) use (&$updated): Record {
			$updated = $r;
			return $r;
		});

		$this->service->delete('alice', 8);

		$this->assertNotNull($updated);
		$this->assertSame(1_700_000_000, $updated->getDeletedAt(), 'soft-deleted with the current time');
		$this->assertInstanceOf(RecordDeletedEvent::class, $this->dispatched[0]);
	}

	public function testBlockPolicyRefusesDeletionAndRollsBack(): void {
		$record = $this->record(8);
		$this->arrangeDelete($record);
		// One referencing row, via field 77 whose onDelete policy is 'block'.
		$this->refMapper->method('findReferencingTarget')->willReturn([
			['id' => 1, 'record_id' => 99, 'field_id' => 77],
		]);
		$this->fieldMapper->method('find')->willReturn($this->field('relation', 'parent', ['onDelete' => 'block'], false, 77));

		$rolled = false;
		$this->db->method('rollBack')->willReturnCallback(static function () use (&$rolled): void {
			$rolled = true;
		});
		$this->recordMapper->expects($this->never())->method('update'); // never tombstoned

		try {
			$this->service->delete('alice', 8);
			$this->fail('expected a block policy to refuse deletion');
		} catch (ValidationException $e) {
			$this->assertStringContainsString('referenced by other records', $e->getMessage());
		}
		$this->assertTrue($rolled);
		$this->assertSame([], $this->dispatched);
	}

	public function testCascadePolicySoftDeletesReferencingRecords(): void {
		$record = $this->record(8);
		// The referencing record (#99) is fetched and soft-deleted too.
		$referencing = $this->record(99);
		$this->arrangeDelete($record, [99 => $referencing]);
		$this->refMapper->method('findReferencingTarget')->willReturn([
			['id' => 1, 'record_id' => 99, 'field_id' => 77],
		]);
		$this->fieldMapper->method('find')->willReturn($this->field('relation', 'parent', ['onDelete' => 'cascade'], false, 77));

		$tombstoned = [];
		$this->recordMapper->method('update')->willReturnCallback(static function (Record $r) use (&$tombstoned): Record {
			$tombstoned[$r->getId()] = $r->getDeletedAt();
			return $r;
		});
		$droppedRefsForRecord = [];
		$this->refMapper->method('deleteForRecord')->willReturnCallback(static function (int $rid) use (&$droppedRefsForRecord): void {
			$droppedRefsForRecord[] = $rid;
		});

		$this->service->delete('alice', 8);

		// Both the target and the cascaded referencing record are tombstoned.
		$this->assertSame(1_700_000_000, $tombstoned[8]);
		$this->assertSame(1_700_000_000, $tombstoned[99]);
		$this->assertContains(99, $droppedRefsForRecord, 'cascaded record has its outgoing refs cleared');
		$this->assertInstanceOf(RecordDeletedEvent::class, $this->dispatched[0]);
	}

	public function testCascadeToleratesAlreadyGoneReferencingRecord(): void {
		$record = $this->record(8);
		$this->arrangeDelete($record);
		$this->refMapper->method('findReferencingTarget')->willReturn([
			['id' => 1, 'record_id' => 99, 'field_id' => 77],
		]);
		$this->fieldMapper->method('find')->willReturn($this->field('relation', 'parent', ['onDelete' => 'cascade'], false, 77));
		// #99 no longer exists (arrangeDelete's find throws for it) — the cascade
		// must swallow the DoesNotExist and still tombstone #8.
		$this->recordMapper->method('update')->willReturnArgument(0);

		$this->service->delete('alice', 8);
		$this->assertInstanceOf(RecordDeletedEvent::class, $this->dispatched[0]);
	}

	public function testNullPolicyDropsDanglingReferences(): void {
		$record = $this->record(8);
		$this->arrangeDelete($record);
		$this->refMapper->method('findReferencingTarget')->willReturn([
			['id' => 1, 'record_id' => 99, 'field_id' => 77],
		]);
		// Field config without onDelete defaults to 'null'.
		$this->fieldMapper->method('find')->willReturn($this->field('relation', 'parent', [], false, 77));
		$this->recordMapper->method('update')->willReturnArgument(0);

		$droppedTargets = [];
		$this->refMapper->method('deleteRefsToTarget')->willReturnCallback(static function (int $target, int $fieldId) use (&$droppedTargets): void {
			$droppedTargets[] = [$target, $fieldId];
		});

		$this->service->delete('alice', 8);

		// The references pointing at #8 via field 77 are dropped; #8 stays soft-deleted.
		$this->assertContains([8, 77], $droppedTargets);
		$this->assertInstanceOf(RecordDeletedEvent::class, $this->dispatched[0]);
	}

	public function testUnknownPolicyFieldConfigTreatedAsNull(): void {
		// A referencing field whose definition has since been deleted: the lookup
		// throws, the policy falls back to 'null', and the delete still succeeds.
		$record = $this->record(8);
		$this->arrangeDelete($record);
		$this->refMapper->method('findReferencingTarget')->willReturn([
			['id' => 1, 'record_id' => 99, 'field_id' => 77],
		]);
		$this->fieldMapper->method('find')->willThrowException(new DoesNotExistException('field gone'));
		$this->recordMapper->method('update')->willReturnArgument(0);

		$dropped = false;
		$this->refMapper->method('deleteRefsToTarget')->willReturnCallback(static function () use (&$dropped): void {
			$dropped = true;
		});

		$this->service->delete('alice', 8);
		$this->assertTrue($dropped);
	}
}
