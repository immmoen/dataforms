<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Exception\ValidationException;
use OCA\Dataforms\Service\ImportService;
use OCA\Dataforms\Service\RecordService;
use OCA\Dataforms\Service\RegisterService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * ImportService: CSV header→field mapping (label or machine name), per-field
 * coercion, per-row validation reporting, the row cap, and the design rule that
 * a bulk import goes through createForImport() (no per-row automations). The
 * whole file is one transaction. RecordService and the mappers are mocked.
 */
class ImportServiceTest extends TestCase {
	private RegisterService&MockObject $registerService;
	private FieldMapper&MockObject $fieldMapper;
	private RecordService&MockObject $recordService;
	private IDBConnection&MockObject $db;
	private ImportService $service;

	/** @var array<int,array<string,mixed>> values passed to createForImport */
	private array $written = [];

	protected function setUp(): void {
		$this->registerService = $this->createMock(RegisterService::class);
		$this->fieldMapper = $this->createMock(FieldMapper::class);
		$this->recordService = $this->createMock(RecordService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new ImportService($this->registerService, $this->fieldMapper, $this->recordService, $this->db);

		$this->written = [];
		$this->recordService->method('createForImport')->willReturnCallback(function (string $u, int $r, array $f, array $values): void {
			$this->written[] = $values;
		});
	}

	private function field(string $type, string $machineName, string $label): Field {
		$f = new Field();
		$f->setType($type);
		$f->setMachineName($machineName);
		$f->setLabel($label);
		return $f;
	}

	private function fields(): array {
		return [
			$this->field('text', 'title', 'Title'),
			$this->field('number', 'qty', 'Quantity'),
			$this->field('boolean', 'done', 'Done'),
			$this->field('multiselect', 'tags', 'Tags'),
			$this->field('auto', 'seq', 'Number'),       // auto — assigned, importable header but coerced
			$this->field('relation', 'parent', 'Parent'), // skipped on import
			$this->field('file', 'doc', 'Attachment'),     // skipped on import
		];
	}

	private function arrange(): void {
		$this->registerService->method('findWritable');
		$this->fieldMapper->method('findByRegister')->willReturn($this->fields());
	}

	public function testEmptyCsvIsRejected(): void {
		$this->arrange();
		$this->expectException(ValidationException::class);
		$this->service->importCsv('alice', 5, '');
	}

	public function testNoMatchingColumnsIsRejected(): void {
		$this->arrange();
		$this->expectException(ValidationException::class);
		$this->service->importCsv('alice', 5, "Nope,Zilch\n1,2");
	}

	public function testImportsRowsMatchingByLabelOrMachineNameAndCoercesValues(): void {
		$this->arrange();
		// Headers mix a label ("Quantity"), a machine name ("title"), a BOM, and a
		// relation column ("Parent") that must be ignored.
		$csv = "\xEF\xBB\xBFtitle,Quantity,Done,Tags,Parent\n"
			. "Widget,3,yes,\"a, b\",99\n"
			. "Gadget,,no,,\n";
		$report = $this->service->importCsv('alice', 5, $csv);

		$this->assertSame(2, $report['imported']);
		$this->assertSame(0, $report['failed']);
		// Row 1: typed coercion; the relation column is never mapped.
		$this->assertSame('Widget', $this->written[0]['title']);
		$this->assertSame(3.0, $this->written[0]['qty']);
		$this->assertTrue($this->written[0]['done']);
		$this->assertSame(['a', 'b'], $this->written[0]['tags']);
		$this->assertArrayNotHasKey('parent', $this->written[0]);
		// Row 2: empties coerce to null/false-ish.
		$this->assertNull($this->written[1]['qty']);
		$this->assertFalse($this->written[1]['done']);
	}

	public function testGoesThroughCreateForImportNeverCreateSoAutomationsDoNotFire(): void {
		$this->arrange();
		// create() dispatches record events (automations); createForImport() does not.
		$this->recordService->expects($this->never())->method('create');
		$this->recordService->expects($this->atLeastOnce())->method('createForImport');
		$this->service->importCsv('alice', 5, "title\nA\nB");
	}

	public function testReportsPerRowValidationErrors(): void {
		$this->arrange();
		$this->recordService->method('createForImport')->willReturnCallback(function (string $u, int $r, array $f, array $values): void {
			if ($values['title'] === 'bad') {
				throw new ValidationException('Title is invalid');
			}
			$this->written[] = $values;
		});

		$report = $this->service->importCsv('alice', 5, "title\nok\nbad\nok2");
		$this->assertSame(2, $report['imported']);
		$this->assertSame(1, $report['failed']);
		$this->assertStringContainsString('Row 3: Title is invalid', $report['errors'][0]);
	}

	public function testMapsAHeaderByMachineNameWhenItIsNotALabel(): void {
		$this->arrange();
		// "qty" is the machine name (the label is "Quantity"), so it maps via the
		// machine-name fallback, not the label.
		$report = $this->service->importCsv('alice', 5, "qty\n42");
		$this->assertSame(1, $report['imported']);
		$this->assertSame(42.0, $this->written[0]['qty']);
	}

	public function testBlankLinesAreSkipped(): void {
		$this->arrange();
		$report = $this->service->importCsv('alice', 5, "title\nA\n\n   \nB\n");
		$this->assertSame(2, $report['imported']);
	}

	public function testStopsAtTheRowCapWithAMessage(): void {
		$this->arrange();
		$rows = [];
		for ($i = 0; $i < 5050; $i++) {
			$rows[] = 'row' . $i;
		}
		$csv = "title\n" . implode("\n", $rows);
		$report = $this->service->importCsv('alice', 5, $csv);

		$this->assertSame(5000, $report['imported']); // capped
		$this->assertNotEmpty($report['errors']);
		$this->assertStringContainsString('5000-row limit', end($report['errors']));
	}

	public function testCatastrophicFailureRollsBackTheWholeBatch(): void {
		$this->arrange();
		$rolled = false;
		$this->db->method('rollBack')->willReturnCallback(static function () use (&$rolled): void {
			$rolled = true;
		});
		$this->recordService->method('createForImport')->willThrowException(new \RuntimeException('db exploded'));

		try {
			$this->service->importCsv('alice', 5, "title\nA");
			$this->fail('expected the DB failure to propagate');
		} catch (\RuntimeException $e) {
			$this->assertSame('db exploded', $e->getMessage());
		}
		$this->assertTrue($rolled, 'a catastrophic error rolls the whole import back');
	}
}
