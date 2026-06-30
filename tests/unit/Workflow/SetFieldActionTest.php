<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Workflow;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Workflow\ActionContext;
use OCA\Dataforms\Workflow\SetFieldAction;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SetFieldAction (AUT-09): writes a field's value column directly (so it never
 * re-fires automations — no loop), skips relation/file/auto/computed targets,
 * and no-ops on an unknown field. Inline action.
 */
class SetFieldActionTest extends TestCase {
	private function field(string $type, string $machineName, int $id = 10): Field {
		$f = new Field();
		$f->setId($id);
		$f->setType($type);
		$f->setMachineName($machineName);
		return $f;
	}

	private function action(FieldMapper $fields, RecordValueMapper $values): SetFieldAction {
		return new SetFieldAction($fields, $values, $this->createMock(LoggerInterface::class));
	}

	public function testWritesTheValueColumnDirectly(): void {
		$fields = $this->createMock(FieldMapper::class);
		$fields->method('findByRegister')->willReturn([$this->field('text', 'status', 10)]);
		$values = $this->createMock(RecordValueMapper::class);
		$values->expects($this->once())->method('deleteForRecordField')->with(9, 10);
		$values->expects($this->once())->method('insertValue')->with(9, 10, 'value_string', 'done');

		$this->action($fields, $values)->run(new ActionContext(5, 9, 'alice', 'Set', [], ['field' => 'status', 'value' => 'done']));
	}

	public function testSkipsUnsupportedFieldType(): void {
		$fields = $this->createMock(FieldMapper::class);
		$fields->method('findByRegister')->willReturn([$this->field('relation', 'parent', 10)]);
		$values = $this->createMock(RecordValueMapper::class);
		$values->expects($this->never())->method('insertValue');
		$this->action($fields, $values)->run(new ActionContext(5, 9, 'alice', 'Set', [], ['field' => 'parent', 'value' => 1]));
	}

	public function testNoOpOnMissingFieldOrBlankConfig(): void {
		$fields = $this->createMock(FieldMapper::class);
		$fields->method('findByRegister')->willReturn([$this->field('text', 'status')]);
		$values = $this->createMock(RecordValueMapper::class);
		$values->expects($this->never())->method('insertValue');
		$action = $this->action($fields, $values);
		$action->run(new ActionContext(5, 9, 'alice', 'Set', [], ['field' => 'ghost', 'value' => 'x'])); // unknown field
		$action->run(new ActionContext(5, 9, 'alice', 'Set', [], ['field' => '  '])); // blank
	}

	public function testSwallowsAStorageError(): void {
		$fields = $this->createMock(FieldMapper::class);
		$fields->method('findByRegister')->willReturn([$this->field('text', 'status', 10)]);
		$values = $this->createMock(RecordValueMapper::class);
		$values->method('deleteForRecordField')->willThrowException(new \RuntimeException('db'));
		// No throw escapes (best-effort).
		$this->action($fields, $values)->run(new ActionContext(5, 9, 'alice', 'Set', [], ['field' => 'status', 'value' => 'x']));
		$this->assertSame('set_field', $this->action($fields, $values)->getType());
	}
}
