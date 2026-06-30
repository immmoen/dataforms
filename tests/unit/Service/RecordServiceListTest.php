<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Record;

/**
 * list() / options(): read-gate, filter resolution into typed-column criteria,
 * the auto-field → record-column sort remap, free-text search scoping, and the
 * returned {records,total,fields} envelope. Frozen before the #8 split.
 */
class RecordServiceListTest extends RecordServiceTestCase {
	/** @var array<string,mixed> last args the record mapper's list was called with */
	private array $listArgs = [];

	/**
	 * @param Field[]|array $fields
	 * @param Record[] $records
	 */
	private function arrangeList(array $fields, array $records): void {
		$this->registerService->method('find')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->recordMapper->method('findByRegister')->willReturnCallback(
			function (int $registerId, int $limit, int $offset, string $sort, string $direction, string $search, array $filters, ?array $sortField, array $searchFieldIds) use ($records): array {
				$this->listArgs = compact('sort', 'direction', 'filters', 'sortField', 'searchFieldIds');
				return $records;
			}
		);
		$this->recordMapper->method('countByRegister')->willReturn(count($records));
		$this->valueMapper->method('findByRecordIds')->willReturn([]);
	}

	public function testListReturnsRecordsTotalAndFieldsEnvelope(): void {
		$fields = [$this->field('text', 'title')];
		$rec = $this->record(3);
		$this->arrangeList($fields, [$rec]);

		$out = $this->service->list('alice', 5, 50, 0, 'updated', 'DESC', '', []);

		$this->assertCount(1, $out['records']);
		$this->assertSame(1, $out['total']);
		$this->assertSame('title', $out['fields'][0]['machineName']);
		$this->assertSame(3, $out['records'][0]['id']);
	}

	public function testFiltersAreResolvedToTypedColumnsAndUnfilterableTypesDropped(): void {
		$fields = [
			$this->field('text', 'title'),
			$this->field('number', 'qty'),
			$this->field('relation', 'parent'), // not filterable here
			$this->field('file', 'doc'),        // not filterable here
		];
		$this->arrangeList($fields, []);

		$this->service->list('alice', 5, 50, 0, 'updated', 'DESC', '', [
			['field' => 'title', 'op' => 'contains', 'value' => 'lap'],
			['field' => 'qty', 'op' => 'gte', 'value' => 2],
			['field' => 'parent', 'op' => 'eq', 'value' => 1],   // dropped (relation)
			['field' => 'doc', 'op' => 'eq', 'value' => 1],      // dropped (file)
			['field' => 'ghost', 'op' => 'eq', 'value' => 1],    // dropped (unknown)
			['field' => 'title', 'op' => 'isNotEmpty'],          // no value coercion
		]);

		$resolved = $this->listArgs['filters'];
		$this->assertCount(3, $resolved, 'only the two known scalar fields + the isNotEmpty filter survive');
		$this->assertSame('value_string', $resolved[0]['column']);
		$this->assertSame('lap', $resolved[0]['value']);
		$this->assertSame('value_number', $resolved[1]['column']);
		$this->assertSame(2.0, $resolved[1]['value']);
		$this->assertNull($resolved[2]['value'], 'isNotEmpty carries no value');
	}

	public function testSortByAutoSequenceFieldRemapsToRecordColumn(): void {
		$fields = [
			$this->field('text', 'title'),
			$this->field('auto', 'ref', ['kind' => 'sequence']),
		];
		$this->arrangeList($fields, []);

		$this->service->list('alice', 5, 50, 0, 'ref', 'ASC', '', []);

		// An auto/sequence sort maps onto the record table's seq column, not a value lookup.
		$this->assertSame('seq', $this->listArgs['sort']);
		$this->assertNull($this->listArgs['sortField']);
	}

	public function testSortByDataFieldUsesValueLookup(): void {
		$title = $this->field('text', 'title');
		$this->arrangeList([$title], []);

		$this->service->list('alice', 5, 50, 0, 'title', 'DESC', '', []);

		$this->assertSame('value_string', $this->listArgs['sortField']['column']);
		$this->assertSame($title->getId(), $this->listArgs['sortField']['fieldId']);
	}

	public function testSearchIsScopedToStringFieldIds(): void {
		$fields = [
			$this->field('text', 'title'),
			$this->field('number', 'qty'),     // value_number — not searchable text
			$this->field('email', 'contact'),  // value_string — searchable
		];
		$this->arrangeList($fields, []);

		$this->service->list('alice', 5, 50, 0, 'updated', 'DESC', 'needle', []);

		// Only the two string-backed fields are offered to the search subquery.
		$this->assertSame([$fields[0]->getId(), $fields[2]->getId()], $this->listArgs['searchFieldIds']);
	}

	public function testOptionsReturnsIdLabelPairs(): void {
		$fields = [$this->field('text', 'name')];
		$rec = $this->record(7);
		$this->registerService->method('find')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->recordMapper->method('findByRegister')->willReturn([$rec]);
		// labelsForRecords resolves the display value from the value rows.
		$this->valueMapper->method('findByRecordIds')->willReturn([
			7 => [$this->valueRow($fields[0], 'Widget') + ['record_id' => 7]],
		]);

		$out = $this->service->options('alice', 5, 'name', 'wid');

		$this->assertSame([['id' => 7, 'label' => 'Widget']], $out);
	}

	public function testOptionsFallsBackToHashIdWhenNoDisplayValue(): void {
		$fields = [$this->field('text', 'name')];
		$rec = $this->record(7);
		$this->registerService->method('find')->willReturn($this->register());
		$this->fieldMapper->method('findByRegister')->willReturn($fields);
		$this->recordMapper->method('findByRegister')->willReturn([$rec]);
		$this->valueMapper->method('findByRecordIds')->willReturn([7 => []]); // no values

		$out = $this->service->options('alice', 5, 'name', '');
		$this->assertSame([['id' => 7, 'label' => '#7']], $out);
	}
}
