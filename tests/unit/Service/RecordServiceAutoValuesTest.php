<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Service\RecordService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins withAutoValues() — the 0.38.1 fix. Auto fields (sequence number,
 * created/updated dates, author) aren't stored as record values, so the workflow
 * engine wouldn't see them and an automation template like {ref_no} (a sequence
 * field) would collapse to empty. This method injects the resolved auto values
 * into the event's value map. It is pure logic, exercised through the private
 * method without the service's collaborators (cf. AutomationServiceTriggerTest).
 */
class RecordServiceAutoValuesTest extends TestCase {
	private ReflectionMethod $withAuto;
	private RecordService $svc;

	protected function setUp(): void {
		// withAutoValues()/autoValue() touch only the Field and Record entities,
		// never a constructor dependency.
		$this->svc = (new \ReflectionClass(RecordService::class))->newInstanceWithoutConstructor();
		$this->withAuto = new ReflectionMethod(RecordService::class, 'withAutoValues');
		$this->withAuto->setAccessible(true);
	}

	/**
	 * @param array<string,mixed>|null $config
	 */
	private function field(string $type, string $machineName, ?array $config = null): Field {
		$f = new Field();
		$f->setType($type);
		$f->setMachineName($machineName);
		$f->setConfig($config === null ? null : json_encode($config));
		return $f;
	}

	public function testInjectsSequenceAuthorAndDates(): void {
		$record = new Record();
		$record->setSeq(7);
		$record->setCreatedBy('alice');
		$record->setCreated(1_700_000_000);
		$record->setUpdated(1_700_086_400);

		$fields = [
			$this->field('text', 'title'),
			$this->field('auto', 'ref_no', ['kind' => 'sequence']),
			$this->field('auto', 'author', ['kind' => 'created_by']),
			$this->field('auto', 'opened', ['kind' => 'created_at']),
		];

		$out = $this->withAuto->invoke($this->svc, $fields, $record, ['title' => 'Laptop']);

		// The submitted value is preserved...
		$this->assertSame('Laptop', $out['title']);
		// ...and each auto field is now resolvable by the engine.
		$this->assertSame('7', $out['ref_no']);
		$this->assertSame('alice', $out['author']);
		$this->assertSame(gmdate('Y-m-d\TH:i', 1_700_000_000), $out['opened']);
	}

	public function testSequenceFallsBackToRowIdBeforeBackfill(): void {
		// A record created before per-register sequence numbers existed has a null
		// seq; the auto value falls back to the row id, never empty.
		$record = new Record();
		$record->setId(123);
		// seq left null

		$fields = [$this->field('auto', 'ref_no', ['kind' => 'sequence'])];

		$out = $this->withAuto->invoke($this->svc, $fields, $record, []);

		$this->assertSame('123', $out['ref_no']);
	}

	public function testNonAutoFieldsAreLeftUntouched(): void {
		$record = new Record();
		$record->setSeq(1);

		$fields = [$this->field('text', 'title'), $this->field('number', 'qty')];

		$out = $this->withAuto->invoke($this->svc, $fields, $record, ['title' => 'X', 'qty' => 5]);

		$this->assertSame(['title' => 'X', 'qty' => 5], $out);
	}
}
