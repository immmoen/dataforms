<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\History;
use OCA\Dataforms\Db\Record;

/**
 * Residual branches of the records core: the auto-field sort remap variants,
 * auto-value derivation edge kinds, the memoised cross-register read check, the
 * file-name cache and its failure fallback, best-effort history, and the
 * rollback-of-the-rollback guard. Frozen before the #8 split.
 */
class RecordServiceEdgeTest extends RecordServiceTestCase {
	private string $capturedSort = '';

	private function arrangeListCapturingSort(array $fields): void {
		$this->registerService->method('find')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->recordMapper->method('countByRegister')->willReturn(0);
		$this->valueMapper->method('findByRecordIds')->willReturn([]);
		$this->recordMapper->method('findByRegister')->willReturnCallback(
			function (int $r, int $l, int $o, string $sort) : array {
				$this->capturedSort = $sort;
				return [];
			}
		);
	}

	/**
	 * @return iterable<string,array{string,string}>
	 */
	public static function autoSortKinds(): iterable {
		yield 'created_at' => ['created_at', 'created'];
		yield 'updated_at' => ['updated_at', 'updated'];
		yield 'created_by' => ['created_by', 'created_by'];
		yield 'unknown kind defaults to seq' => ['something_else', 'seq'];
	}

	/**
	 * @dataProvider autoSortKinds
	 */
	public function testAutoFieldSortRemapsToRecordColumn(string $kind, string $expectedColumn): void {
		$this->arrangeListCapturingSort([$this->field('auto', 'meta', ['kind' => $kind])]);
		$this->service->list('alice', 5, 50, 0, 'meta', 'DESC', '', []);
		$this->assertSame($expectedColumn, $this->capturedSort);
	}

	public function testAutoValueResolvesUpdatedAtAndUnknownKind(): void {
		$updated = $this->field('auto', 'edited', ['kind' => 'updated_at']);
		$unknown = $this->field('auto', 'mystery', ['kind' => 'nope']);
		$record = $this->record(3);
		$record->setUpdated(1_700_086_400);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn([$updated, $unknown]);
		$this->valueMapper->method('findByRecordIds')->willReturn([3 => []]);

		$dto = $this->service->get('alice', 3);

		$this->assertSame(gmdate('Y-m-d\TH:i', 1_700_086_400), $dto['values']['edited']);
		$this->assertNull($dto['values']['mystery'], 'an unknown auto kind resolves to null');
	}

	public function testCrossRegisterReadCheckIsMemoisedAcrossRelationFields(): void {
		// Two relation fields targeting the SAME register: the read gate must be
		// evaluated once (the second lookup hits the per-call cache).
		$a = $this->field('relation', 'a', ['targetRegisterId' => 9, 'multiple' => false]);
		$b = $this->field('relation', 'b', ['targetRegisterId' => 9, 'multiple' => false]);
		$name = $this->field('text', 'name', null, false, 200);
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);

		$findCalls = [];
		$this->registerService->method('find')->willReturnCallback(function (string $uid, int $registerId) use (&$findCalls) {
			$findCalls[] = $registerId;
			return $this->register();
		});
		$this->fieldMapper->method('findByRegister')->willReturnCallback(
			static fn (int $reg): array => $reg === 9 ? [$name] : [$a, $b]
		);
		$this->valueMapper->method('findByRecordIds')->willReturn([]);
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [
				['field_id' => $a->getId(), 'target_record_id' => 50, 'position' => 0],
				['field_id' => $b->getId(), 'target_record_id' => 50, 'position' => 0],
			],
		]);

		$this->service->get('alice', 3);

		// register 5 (read gate) once + register 9 once — NOT twice for the two relations.
		$this->assertSame([5, 9], $findCalls);
	}

	public function testFileNameCacheReusesAndFailureFallsBackToPlaceholder(): void {
		$doc = $this->field('file', 'doc');
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn([$doc]);
		$this->valueMapper->method('findByRecordIds')->willReturn([]);
		$this->fileMapper->method('findByRecordIds')->willReturn([
			3 => [
				['field_id' => $doc->getId(), 'file_id' => 500, 'position' => 0],
				['field_id' => $doc->getId(), 'file_id' => 500, 'position' => 1], // same id → cache hit
				['field_id' => $doc->getId(), 'file_id' => 999, 'position' => 2], // getById throws
			],
		]);

		$folder = $this->createMock(\OCP\Files\Folder::class);
		$lookups = 0;
		$folder->method('getById')->willReturnCallback(function (int $id) use (&$lookups) {
			$lookups++;
			if ($id === 999) {
				throw new \RuntimeException('storage unavailable');
			}
			$node = $this->createMock(\OCP\Files\Node::class);
			$node->method('getName')->willReturn('shared.pdf');
			return [$node];
		});
		$this->rootFolder->method('getUserFolder')->willReturn($folder);

		$dto = $this->service->get('alice', 3);

		$names = array_column($dto['values']['doc'], 'name');
		$this->assertSame(['shared.pdf', 'shared.pdf', 'file #999'], $names);
		$this->assertSame(2, $lookups, 'the repeated file id is resolved once (memoised), the failing one once');
	}

	public function testReadableRelationWithNoTargetsResolvesEmptyLabels(): void {
		// Relation field present and target register readable, but the record has
		// no references — exercises the empty-id short-circuit in label resolution.
		$links = $this->field('relation', 'links', ['targetRegisterId' => 9, 'multiple' => true]);
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturnCallback(
			static fn (int $reg): array => $reg === 9 ? [] : [$links]
		);
		$this->valueMapper->method('findByRecordIds')->willReturn([]);
		$this->refMapper->method('findByRecordIds')->willReturn([]); // no refs

		$dto = $this->service->get('alice', 3);
		$this->assertSame([], $dto['values']['links'], 'a multi relation with no targets is an empty list');
	}

	public function testHistoryFailureNeverBlocksTheWrite(): void {
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn([$this->field('text', 'title')]);
		$this->recordMapper->method('maxSeqForRegister')->willReturn(0);
		$this->recordMapper->method('insert')->willReturnCallback(static function (Record $r): Record {
			$r->setId(1);
			return $r;
		});
		$this->valueMapper->method('findByRecordIds')->willReturn([1 => []]);
		// History is best-effort: a logging failure must not fail the create.
		$this->historyMapper->method('insert')->willThrowException(new \RuntimeException('audit table locked'));

		$dto = $this->service->create('alice', 5, ['title' => 'x']);

		$this->assertSame(1, $dto['id']);
		$this->assertNotEmpty($this->dispatched, 'the create still committed and dispatched');
	}

	public function testRollbackFailureDoesNotMaskTheOriginalError(): void {
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn([$this->field('text', 'title')]);
		$this->recordMapper->method('maxSeqForRegister')->willReturn(0);
		$this->recordMapper->method('insert')->willThrowException(new \RuntimeException('primary failure'));
		// Even the rollback fails — the original write error must still surface.
		$this->db->method('rollBack')->willThrowException(new \RuntimeException('rollback failure'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('primary failure');
		$this->service->create('alice', 5, ['title' => 'x']);
	}
}
