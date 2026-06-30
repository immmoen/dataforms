<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\History;
use OCA\Dataforms\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * get() / history() and the read-side resolution they drive: relation labels
 * (with the cross-register read gate), file names via the Files API, auto-field
 * derivation in the DTO, and the display-label fallbacks. Frozen before #8.
 */
class RecordServiceReadTest extends RecordServiceTestCase {
	/**
	 * Make fieldMapper->findByRegister and valueMapper->findByRecordIds answer
	 * per register / per record-id set.
	 *
	 * @param array<int,Field[]> $fieldsByRegister
	 * @param array<int,array<int,array<string,mixed>>> $valuesByRecord recordId => rows
	 */
	private function arrangeStores(array $fieldsByRegister, array $valuesByRecord): void {
		$this->fieldMapper->method('findByRegister')->willReturnCallback(
			static fn (int $registerId): array => $fieldsByRegister[$registerId] ?? []
		);
		$this->valueMapper->method('findByRecordIds')->willReturnCallback(
			static function (array $ids) use ($valuesByRecord): array {
				$out = [];
				foreach ($ids as $id) {
					if (isset($valuesByRecord[$id])) {
						$out[$id] = $valuesByRecord[$id];
					}
				}
				return $out;
			}
		);
	}

	public function testGetResolvesScalarAndAutoFields(): void {
		$title = $this->field('text', 'title');
		$ref = $this->field('auto', 'ref', ['kind' => 'sequence']);
		$record = $this->record(3);
		$record->setSeq(42);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->arrangeStores([5 => [$title, $ref]], [3 => [$this->valueRow($title, 'Hello') + ['record_id' => 3]]]);

		$dto = $this->service->get('alice', 3);

		$this->assertSame('Hello', $dto['values']['title']);
		$this->assertSame('42', $dto['values']['ref'], 'auto field derived from record metadata');
	}

	public function testGetMissingRecordThrowsNotFound(): void {
		$this->recordMapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->expectException(NotFoundException::class);
		$this->service->get('alice', 999);
	}

	public function testGetResolvesSingleRelationLabelWhenTargetReadable(): void {
		$parent = $this->field('relation', 'parent', ['targetRegisterId' => 9, 'displayField' => 'name', 'multiple' => false]);
		$name = $this->field('text', 'name', null, false, 200);
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register()); // readable everywhere
		$this->arrangeStores(
			[5 => [$parent], 9 => [$name]],
			[50 => [$this->valueRow($name, 'Acme') + ['record_id' => 50]]],
		);
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [['field_id' => $parent->getId(), 'target_record_id' => 50, 'position' => 0]],
		]);

		$dto = $this->service->get('alice', 3);

		$this->assertSame(['id' => 50, 'label' => 'Acme'], $dto['values']['parent']);
	}

	public function testGetReturnsMultipleRelationAsList(): void {
		$parent = $this->field('relation', 'tags', ['targetRegisterId' => 9, 'displayField' => 'name', 'multiple' => true]);
		$name = $this->field('text', 'name', null, false, 200);
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->arrangeStores(
			[5 => [$parent], 9 => [$name]],
			[
				50 => [$this->valueRow($name, 'A') + ['record_id' => 50]],
				51 => [$this->valueRow($name, 'B') + ['record_id' => 51]],
			],
		);
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [
				['field_id' => $parent->getId(), 'target_record_id' => 50, 'position' => 0],
				['field_id' => $parent->getId(), 'target_record_id' => 51, 'position' => 1],
			],
		]);

		$dto = $this->service->get('alice', 3);

		$this->assertSame([
			['id' => 50, 'label' => 'A'],
			['id' => 51, 'label' => 'B'],
		], $dto['values']['tags']);
	}

	public function testRelationLabelIsAnonymisedWhenTargetRegisterUnreadable(): void {
		$parent = $this->field('relation', 'parent', ['targetRegisterId' => 9, 'displayField' => 'name', 'multiple' => false]);
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		// Readable for the record's own register (5) but NOT for the target (9).
		$this->registerService->method('find')->willReturnCallback(function (string $uid, int $registerId) {
			if ($registerId === 9) {
				throw new NotFoundException('no access');
			}
			return $this->register();
		});
		$this->arrangeStores([5 => [$parent]], []);
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [['field_id' => $parent->getId(), 'target_record_id' => 50, 'position' => 0]],
		]);

		$dto = $this->service->get('alice', 3);

		// No label leak from a register the user cannot read — bare "#id".
		$this->assertSame(['id' => 50, 'label' => '#50'], $dto['values']['parent']);
	}

	public function testGetResolvesFileNamesViaFilesApi(): void {
		$doc = $this->field('file', 'doc');
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->arrangeStores([5 => [$doc]], []);
		$this->fileMapper->method('findByRecordIds')->willReturn([
			3 => [
				['field_id' => $doc->getId(), 'file_id' => 500, 'position' => 0],
				['field_id' => $doc->getId(), 'file_id' => 501, 'position' => 1], // unresolvable
			],
		]);
		$this->rootFolder->method('getUserFolder')->willReturn($this->userFolderResolving([500 => 'report.pdf']));

		$dto = $this->service->get('alice', 3);

		$this->assertSame([
			['id' => 500, 'name' => 'report.pdf'],
			['id' => 501, 'name' => 'file #501'], // placeholder for the inaccessible file
		], $dto['values']['doc']);
	}

	public function testLabelFallsBackToFirstTextLikeFieldWhenNoDisplayConfigured(): void {
		// displayField empty → pick the first text/longtext/email/select field.
		$parent = $this->field('relation', 'parent', ['targetRegisterId' => 9, 'multiple' => false]);
		$code = $this->field('number', 'code', null, false, 201);
		$label = $this->field('select', 'label', null, false, 202);
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->arrangeStores(
			[5 => [$parent], 9 => [$code, $label]],
			[50 => [$this->valueRow($label, 'Chosen') + ['record_id' => 50]]],
		);
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [['field_id' => $parent->getId(), 'target_record_id' => 50, 'position' => 0]],
		]);

		$dto = $this->service->get('alice', 3);
		$this->assertSame('Chosen', $dto['values']['parent']['label']);
	}

	public function testLabelFallsBackToFirstFieldWhenNoTextLikeField(): void {
		// No text-like field at all → the first field is used as the display.
		$parent = $this->field('relation', 'parent', ['targetRegisterId' => 9, 'multiple' => false]);
		$count = $this->field('number', 'count', null, false, 201);
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->arrangeStores(
			[5 => [$parent], 9 => [$count]],
			[50 => [$this->valueRow($count, 7) + ['record_id' => 50]]],
		);
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [['field_id' => $parent->getId(), 'target_record_id' => 50, 'position' => 0]],
		]);

		$dto = $this->service->get('alice', 3);
		$this->assertSame('7', $dto['values']['parent']['label']);
	}

	public function testLabelIsHashIdWhenTargetRegisterHasNoFields(): void {
		$parent = $this->field('relation', 'parent', ['targetRegisterId' => 9, 'multiple' => false]);
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());
		$this->arrangeStores([5 => [$parent], 9 => []], []); // target register has no fields
		$this->refMapper->method('findByRecordIds')->willReturn([
			3 => [['field_id' => $parent->getId(), 'target_record_id' => 50, 'position' => 0]],
		]);

		$dto = $this->service->get('alice', 3);
		$this->assertSame(['id' => 50, 'label' => '#50'], $dto['values']['parent']);
	}

	public function testHistoryDecoratesEntriesAndDecodesDetail(): void {
		$record = $this->record(3);
		$this->recordMapper->method('find')->willReturn($record);
		$this->registerService->method('find')->willReturn($this->register());

		$withDetail = new History();
		$withDetail->setId(1);
		$withDetail->setAction('update');
		$withDetail->setUserId('alice');
		$withDetail->setSummary('Changed Title');
		$withDetail->setDetail(json_encode(['fields' => ['Title']]));
		$withDetail->setCreated(1_690_000_000);

		$noDetail = new History();
		$noDetail->setId(2);
		$noDetail->setAction('create');
		$noDetail->setUserId('alice');
		$noDetail->setSummary('Created record');
		$noDetail->setDetail(null);
		$noDetail->setCreated(1_689_000_000);

		$this->historyMapper->method('findByRecord')->willReturn([$withDetail, $noDetail]);

		$out = $this->service->history('alice', 3);

		$this->assertSame(['fields' => ['Title']], $out[0]['detail']);
		$this->assertNull($out[1]['detail'], 'a null/empty detail decodes to null');
		$this->assertSame('create', $out[1]['action']);
	}
}
