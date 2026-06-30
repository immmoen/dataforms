<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\History;
use OCA\Dataforms\Event\RecordUpdatedEvent;
use OCA\Dataforms\Exception\ForbiddenException;

/**
 * update(): the dangerous replace-then-reinsert path. Covers the own/manage
 * gate, the atomic header-update + value-replacement + history diff, the
 * change summary, and the update event (frozen before the #8 split).
 */
class RecordServiceUpdateTest extends RecordServiceTestCase {
	/** @var History[] */
	private array $loggedHistory = [];

	protected function setUp(): void {
		parent::setUp();
		$this->loggedHistory = [];
		$this->historyMapper->method('insert')->willReturnCallback(function (History $h): History {
			$this->loggedHistory[] = $h;
			return $h;
		});
	}

	public function testUpdateReplacesValuesAndDiffsHistory(): void {
		$fields = [$this->field('text', 'title'), $this->field('text', 'note')];
		$record = $this->record(8);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);

		// before-snapshot: title="old", note="keep"; after: title="new", note="keep".
		$before = [8 => [
			$this->valueRow($fields[0], 'old') + ['record_id' => 8],
			$this->valueRow($fields[1], 'keep') + ['record_id' => 8],
		]];
		$after = [8 => [
			$this->valueRow($fields[0], 'new') + ['record_id' => 8],
			$this->valueRow($fields[1], 'keep') + ['record_id' => 8],
		]];
		// findByRecordIds is called: before snapshot, after snapshot, then the DTO read-back.
		$this->valueMapper->method('findByRecordIds')->willReturnOnConsecutiveCalls($before, $after, $after);

		$replaced = false;
		$this->valueMapper->method('deleteByRecord')->willReturnCallback(static function () use (&$replaced): void {
			$replaced = true;
		});

		$dto = $this->service->update('alice', 8, ['title' => 'new', 'note' => 'keep']);

		$this->assertTrue($replaced, 'update() clears the old values before re-inserting');
		$this->assertSame('new', $dto['values']['title']);

		// Exactly one field changed → "Changed <label>" and the changed-field list.
		$updateEntry = array_values(array_filter($this->loggedHistory, static fn (History $h) => $h->getAction() === 'update'))[0];
		$this->assertSame('Changed Title', $updateEntry->getSummary());
		$this->assertSame(['fields' => ['Title']], json_decode((string)$updateEntry->getDetail(), true));

		$this->assertInstanceOf(RecordUpdatedEvent::class, $this->dispatched[0]);
		$this->assertSame(['Title'], $this->dispatched[0]->getChangedFields());
	}

	public function testUpdateSummaryPluralisesMultipleChanges(): void {
		$fields = [$this->field('text', 'a'), $this->field('text', 'b')];
		$record = $this->record(8);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);

		$before = [8 => [$this->valueRow($fields[0], 'a1') + ['record_id' => 8], $this->valueRow($fields[1], 'b1') + ['record_id' => 8]]];
		$after = [8 => [$this->valueRow($fields[0], 'a2') + ['record_id' => 8], $this->valueRow($fields[1], 'b2') + ['record_id' => 8]]];
		$this->valueMapper->method('findByRecordIds')->willReturnOnConsecutiveCalls($before, $after, $after);

		$this->service->update('alice', 8, ['a' => 'a2', 'b' => 'b2']);

		$updateEntry = array_values(array_filter($this->loggedHistory, static fn (History $h) => $h->getAction() === 'update'))[0];
		$this->assertSame('Changed 2 fields', $updateEntry->getSummary());
	}

	public function testUpdateWithNoValueChangeSummarisesAsEdited(): void {
		$fields = [$this->field('text', 'title')];
		$record = $this->record(8);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);

		$same = [8 => [$this->valueRow($fields[0], 'x') + ['record_id' => 8]]];
		$this->valueMapper->method('findByRecordIds')->willReturnOnConsecutiveCalls($same, $same, $same);

		$this->service->update('alice', 8, ['title' => 'x']);

		$updateEntry = array_values(array_filter($this->loggedHistory, static fn (History $h) => $h->getAction() === 'update'))[0];
		$this->assertSame('Edited record', $updateEntry->getSummary());
		$this->assertNull($updateEntry->getDetail(), 'no changed fields → null detail');
	}

	public function testUpdateRollsBackAndDoesNotDispatchOnFailure(): void {
		$rolled = false;
		$this->db->method('rollBack')->willReturnCallback(static function () use (&$rolled): void {
			$rolled = true;
		});
		$this->db->expects($this->never())->method('commit');

		$fields = [$this->field('text', 'title')];
		$record = $this->record(8);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->valueMapper->method('findByRecordIds')->willReturn([8 => []]);
		$this->recordMapper->method('update')->willThrowException(new \RuntimeException('boom'));

		$this->expectException(\RuntimeException::class);
		try {
			$this->service->update('alice', 8, ['title' => 'x']);
		} finally {
			$this->assertTrue($rolled);
			$this->assertSame([], $this->dispatched);
		}
	}

	public function testNonOwnerNonManagerIsForbidden(): void {
		$fields = [$this->field('text', 'title')];
		$record = $this->record(8, 5, 'alice'); // created by alice
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->registerService->method('isManager')->willReturn(false);

		$this->expectException(ForbiddenException::class);
		$this->service->update('bob', 8, ['title' => 'x']); // bob is neither owner nor manager
	}
}
