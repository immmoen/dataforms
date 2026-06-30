<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Tests\Unit\Service;

use OCA\Dataforms\Db\Field;
use OCA\Dataforms\Db\FieldMapper;
use OCA\Dataforms\Db\HistoryMapper;
use OCA\Dataforms\Db\Record;
use OCA\Dataforms\Db\RecordFileMapper;
use OCA\Dataforms\Db\RecordMapper;
use OCA\Dataforms\Db\RecordRefMapper;
use OCA\Dataforms\Db\RecordValueMapper;
use OCA\Dataforms\Db\Register;
use OCA\Dataforms\Rules\ExpressionEvaluator;
use OCA\Dataforms\Rules\RuleEvaluator;
use OCA\Dataforms\Service\FieldValidator;
use OCA\Dataforms\Service\FieldValue;
use OCA\Dataforms\Service\RecordComputationService;
use OCA\Dataforms\Service\RecordRelationService;
use OCA\Dataforms\Service\RecordService;
use OCA\Dataforms\Service\RegisterService;
use OCA\Dataforms\Service\RuleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Shared fixture for the RecordService characterization suite (issue #7).
 *
 * The records service is the application's god-object: it orchestrates fifteen
 * collaborators to validate, compute, write atomically across five tables and
 * dispatch a domain event. These tests freeze that observable behaviour at the
 * service seam BEFORE the #8 split, so the refactor can be proven iso-behaviour.
 *
 * Strategy: the *logic* collaborators that are themselves pure and already unit
 * tested — RuleEvaluator, ExpressionEvaluator, FieldValidator — are wired as
 * REAL instances so validation/compute is exercised end-to-end exactly as in
 * production; only the I/O collaborators (mappers, RegisterService, RuleService,
 * Files, time, event dispatcher, DB connection) are mocked. The mappers get
 * their own real-SQLite coverage in tests/integration/Db/RecordMapperTest.
 */
abstract class RecordServiceTestCase extends TestCase {
	protected RecordMapper&MockObject $recordMapper;
	protected RecordValueMapper&MockObject $valueMapper;
	protected RecordFileMapper&MockObject $fileMapper;
	protected RecordRefMapper&MockObject $refMapper;
	protected FieldMapper&MockObject $fieldMapper;
	protected RegisterService&MockObject $registerService;
	protected RuleService&MockObject $ruleService;
	protected FieldValidator $fieldValidator;
	protected IRootFolder&MockObject $rootFolder;
	protected ITimeFactory&MockObject $time;
	protected HistoryMapper&MockObject $historyMapper;
	protected IEventDispatcher&MockObject $eventDispatcher;
	protected IDBConnection&MockObject $db;

	protected RecordService $service;

	/** @var list<object> events captured from dispatchTyped() */
	protected array $dispatched = [];

	/** @var array<int,array<string,mixed>> register rule definitions (set per test) */
	protected array $rules = [];

	protected function setUp(): void {
		$this->recordMapper = $this->createMock(RecordMapper::class);
		$this->valueMapper = $this->createMock(RecordValueMapper::class);
		$this->fileMapper = $this->createMock(RecordFileMapper::class);
		$this->refMapper = $this->createMock(RecordRefMapper::class);
		$this->fieldMapper = $this->createMock(FieldMapper::class);
		$this->registerService = $this->createMock(RegisterService::class);
		$this->ruleService = $this->createMock(RuleService::class);
		$this->fieldValidator = new FieldValidator($this->valueMapper);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->historyMapper = $this->createMock(HistoryMapper::class);
		$this->eventDispatcher = $this->createMock(IEventDispatcher::class);
		$this->db = $this->createMock(IDBConnection::class);

		$expr = new ExpressionEvaluator();
		$evaluator = new RuleEvaluator($expr);

		// The two extracted collaborators (#8) are wired as REAL instances over
		// the SAME mocked mappers, so the existing characterization tests exercise
		// the post-split graph through RecordService's unchanged public API.
		$computation = new RecordComputationService($this->ruleService, $evaluator, $expr, $this->fieldValidator);
		$relations = new RecordRelationService(
			$this->refMapper,
			$this->recordMapper,
			$this->fieldMapper,
			$this->valueMapper,
			$this->registerService,
			$this->time,
		);

		$this->service = new RecordService(
			$this->recordMapper,
			$this->valueMapper,
			$this->fileMapper,
			$this->refMapper,
			$this->fieldMapper,
			$this->registerService,
			$computation,
			$relations,
			$this->rootFolder,
			$this->time,
			$this->historyMapper,
			$this->eventDispatcher,
			$this->db,
		);

		// Capture dispatched domain events for assertion.
		$this->dispatched = [];
		$this->eventDispatcher->method('dispatchTyped')
			->willReturnCallback(function (object $event): void {
				$this->dispatched[] = $event;
			});

		// Rules are read from the mutable $rules property so a test can declare
		// them after setUp() (re-stubbing the same mock method would be ignored).
		$this->rules = [];
		$this->ruleService->method('definitionsForRegister')->willReturnCallback(fn (): array => $this->rules);

		// atomically() runs the closure between begin/commit; pass through.
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');

		$this->time->method('getTime')->willReturn(1_700_000_000);
	}

	/**
	 * @param array<string,mixed>|null $config
	 */
	protected function field(string $type, string $machineName, ?array $config = null, bool $mandatory = false, int $id = 0, bool $unique = false): Field {
		static $auto = 1000;
		$f = new Field();
		$f->setId($id > 0 ? $id : $auto++);
		$f->setType($type);
		$f->setMachineName($machineName);
		$f->setLabel(ucfirst($machineName));
		$f->setConfig($config === null ? null : json_encode($config));
		$f->setMandatory($mandatory);
		$f->setIsUnique($unique);
		return $f;
	}

	protected function record(int $id, int $registerId = 5, string $createdBy = 'alice'): Record {
		$r = new Record();
		$r->setId($id);
		$r->setRegisterId($registerId);
		$r->setOwner($createdBy);
		$r->setCreatedBy($createdBy);
		$r->setSeq($id);
		$r->setCreated(1_690_000_000);
		$r->setUpdated(1_690_000_000);
		return $r;
	}

	/**
	 * Build a df_record_values row (one populated typed column) the way the
	 * mapper returns it, for a given field's logical value.
	 *
	 * @param mixed $logical
	 * @return array<string,mixed>
	 */
	protected function valueRow(Field $field, $logical): array {
		$row = [
			'record_id' => 0,
			'field_id' => $field->getId(),
			'value_string' => null,
			'value_number' => null,
			'value_datetime' => null,
			'value_bool' => null,
			'value_file_id' => null,
			'value_ref_record_id' => null,
		];
		$payload = FieldValue::toStorage($field->getType(), $logical);
		if ($payload['column'] !== '') {
			$row[$payload['column']] = $payload['value'];
		}
		return $row;
	}

	protected function register(int $id = 5): Register {
		$r = new Register();
		$r->setId($id);
		return $r;
	}

	/** A Files user-folder whose getById() resolves the given id => name map. */
	protected function userFolderResolving(array $namesById): Folder&MockObject {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->willReturnCallback(function (int $id) use ($namesById) {
			if (!isset($namesById[$id])) {
				return [];
			}
			$node = $this->createMock(\OCP\Files\Node::class);
			$node->method('getName')->willReturn($namesById[$id]);
			return [$node];
		});
		return $folder;
	}
}
