<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Exception\NotFoundException;
use OCA\Dataforms\Service\RegisterService;
use OCA\Dataforms\Workflow\RelationResolver;
use PHPUnit\Framework\TestCase;

/**
 * RelationResolver (AUT-18): exposes a relation target's scalar fields as
 * {relationField}.{targetField} for templates, behind the owner read-gate, and
 * skips targets in registers the owner can't read or that don't match the
 * configured target register.
 */
class RelationResolverTest extends TestCase {
	private function field(string $type, string $machineName, int $id, ?array $config = null): Field {
		$f = new Field();
		$f->setId($id);
		$f->setType($type);
		$f->setMachineName($machineName);
		$f->setConfig($config === null ? null : json_encode($config));
		return $f;
	}

	private function valueRow(int $fieldId, string $value): array {
		return ['field_id' => $fieldId, 'value_string' => $value, 'value_number' => null, 'value_datetime' => null, 'value_bool' => null, 'value_file_id' => null, 'value_ref_record_id' => null];
	}

	private function resolver(bool $readable, int $targetRecRegister = 9): RelationResolver {
		$parent = $this->field('relation', 'sub', 100, ['targetRegisterId' => 9]);
		$code = $this->field('text', 'code', 200);
		$rel = $this->field('relation', 'inner', 201); // skipped (relation type)

		$fieldMapper = $this->createMock(FieldMapper::class);
		$fieldMapper->method('findByRegister')->willReturnCallback(static fn (int $reg): array => $reg === 9 ? [$code, $rel] : [$parent]);

		$recordMapper = $this->createMock(RecordMapper::class);
		$target = new Record();
		$target->setId(50);
		$target->setRegisterId($targetRecRegister);
		$recordMapper->method('find')->willReturn($target);

		$valueMapper = $this->createMock(RecordValueMapper::class);
		$valueMapper->method('findByRecordIds')->willReturn([50 => [$this->valueRow(200, 'ESG-1')]]);

		$registerService = $this->createMock(RegisterService::class);
		if (!$readable) {
			$registerService->method('find')->willThrowException(new NotFoundException('no'));
		}

		return new RelationResolver($fieldMapper, $recordMapper, $valueMapper, $registerService);
	}

	public function testEnrichesWithTargetScalarSubfields(): void {
		$out = $this->resolver(true)->enrich('alice', 5, ['sub' => ['id' => 50]]);
		$this->assertSame('ESG-1', $out['sub.code']);
		$this->assertArrayNotHasKey('sub.inner', $out); // relation subfield skipped
	}

	public function testSkipsWhenOwnerCannotReadTheTargetRegister(): void {
		$out = $this->resolver(false)->enrich('alice', 5, ['sub' => 50]);
		$this->assertArrayNotHasKey('sub.code', $out);
	}

	public function testSkipsWhenTargetIsNotInTheConfiguredRegister(): void {
		$out = $this->resolver(true, 999)->enrich('alice', 5, ['sub' => 50]); // target record in reg 999, not 9
		$this->assertArrayNotHasKey('sub.code', $out);
	}

	public function testLeavesValuesUntouchedWithoutARelationValue(): void {
		$out = $this->resolver(true)->enrich('alice', 5, ['sub' => null]);
		$this->assertArrayNotHasKey('sub.code', $out);
	}
}
