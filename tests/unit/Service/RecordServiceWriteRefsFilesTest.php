<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Exception\ValidationException;

/**
 * The multi-valued join writes: storeRefs() (relation targets, with the
 * cross-register integrity gate) and storeFiles() (file attachments). Driven
 * through create(); frozen before the #8 split.
 */
class RecordServiceWriteRefsFilesTest extends RecordServiceTestCase {
	private function arrangeCreate(array $fields, int $newId = 1): void {
		$this->registerService->method('findWritable')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->recordMapper->method('maxSeqForRegister')->willReturn(0);
		$this->recordMapper->method('insert')->willReturnCallback(static function (Record $r) use ($newId): Record {
			$r->setId($newId);
			return $r;
		});
		// Trivial DTO read-back (no rows → resolveRelations/resolveFiles return empty).
		$this->valueMapper->method('findByRecordIds')->willReturn([$newId => []]);
		$this->refMapper->method('findByRecordIds')->willReturn([]);
		$this->fileMapper->method('findByRecordIds')->willReturn([]);
	}

	public function testStoresValidatedRelationTargetsInOrder(): void {
		$links = $this->field('relation', 'links', ['targetRegisterId' => 9, 'multiple' => true]);
		$this->arrangeCreate([$links]);
		// Both targets are live records in register 9.
		$this->recordMapper->method('existingIdsInRegister')->willReturn([50, 51]);

		$inserted = [];
		$this->refMapper->method('insertRef')->willReturnCallback(static function (int $rid, int $fid, int $target, int $pos) use (&$inserted): void {
			$inserted[] = [$target, $pos];
		});

		$this->service->create('alice', 5, ['links' => [['id' => 50], 51, 50]]); // dup 50 deduped

		$this->assertSame([[50, 0], [51, 1]], $inserted, 'targets stored de-duplicated, position-ordered');
	}

	public function testRejectsRelationTargetOutsideConfiguredRegister(): void {
		$links = $this->field('relation', 'links', ['targetRegisterId' => 9, 'multiple' => true]);
		$this->arrangeCreate([$links]);
		// 51 is not a live record in register 9.
		$this->recordMapper->method('existingIdsInRegister')->willReturn([50]);
		$this->refMapper->expects($this->never())->method('insertRef');

		try {
			$this->service->create('alice', 5, ['links' => [50, 51]]);
			$this->fail('expected an invalid relation target to be rejected');
		} catch (ValidationException $e) {
			$this->assertSame('Invalid relation target', $e->getErrors()['links']);
			$this->assertStringContainsString('51', $e->getMessage());
		}
	}

	public function testRelationWithoutTargetRegisterSkipsIntegrityGate(): void {
		// A relation field with no configured target register stores ids unchecked.
		$links = $this->field('relation', 'links', ['multiple' => true]);
		$this->arrangeCreate([$links]);
		$this->recordMapper->expects($this->never())->method('existingIdsInRegister');

		$inserted = [];
		$this->refMapper->method('insertRef')->willReturnCallback(static function (int $r, int $f, int $t) use (&$inserted): void {
			$inserted[] = $t;
		});

		$this->service->create('alice', 5, ['links' => [50]]);
		$this->assertSame([50], $inserted);
	}

	public function testEmptyRelationStoresNothing(): void {
		$links = $this->field('relation', 'links', ['targetRegisterId' => 9]);
		$this->arrangeCreate([$links]);
		$this->refMapper->expects($this->never())->method('insertRef');
		$this->service->create('alice', 5, ['links' => []]);
		$this->addToAssertionCount(1);
	}

	public function testStoresMultipleFileAttachmentsInOrder(): void {
		$doc = $this->field('file', 'doc');
		$this->arrangeCreate([$doc]);

		$inserted = [];
		$this->fileMapper->method('insertFile')->willReturnCallback(static function (int $r, int $f, int $fileId, int $pos) use (&$inserted): void {
			$inserted[] = [$fileId, $pos];
		});

		$this->service->create('alice', 5, ['doc' => [['id' => 500], 501, ['id' => 0]]]); // id 0 dropped

		$this->assertSame([[500, 0], [501, 1]], $inserted);
	}

	public function testStoresSingleFileValue(): void {
		$doc = $this->field('file', 'doc');
		$this->arrangeCreate([$doc]);

		$inserted = [];
		$this->fileMapper->method('insertFile')->willReturnCallback(static function (int $r, int $f, int $fileId, int $pos) use (&$inserted): void {
			$inserted[] = $fileId;
		});

		$this->service->create('alice', 5, ['doc' => 777]); // a bare id, not a list

		$this->assertSame([777], $inserted);
	}

	public function testEmptyFileValueStoresNothingButStillClearsField(): void {
		$doc = $this->field('file', 'doc');
		$this->arrangeCreate([$doc]);
		// The field is always cleared first (so an edit that empties it removes rows)...
		$this->fileMapper->expects($this->once())->method('deleteForRecordField');
		// ...but with no value nothing is inserted.
		$this->fileMapper->expects($this->never())->method('insertFile');

		$this->service->create('alice', 5, ['doc' => null]);
	}
}
