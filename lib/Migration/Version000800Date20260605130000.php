<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds a per-register sequence number to records (df_records.seq) so an "auto
 * sequence" field reads 1, 2, 3 … within its register instead of the global
 * row id. Existing records are backfilled in id order per register so numbers
 * are stable and never reused after a deletion.
 */
class Version000800Date20260605130000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('df_records')) {
			$t = $schema->getTable('df_records');
			if (!$t->hasColumn('seq')) {
				$t->addColumn('seq', Types::BIGINT, ['notnull' => false]);
			}
			if (!$t->hasIndex('df_rec_seq_idx')) {
				$t->addIndex(['register_id', 'seq'], 'df_rec_seq_idx');
			}
		}

		return $schema;
	}

	/**
	 * Backfill seq for pre-existing records, numbering each register's rows in
	 * id order starting at 1.
	 *
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$read = $this->db->getQueryBuilder();
		$read->select('id', 'register_id')
			->from('df_records')
			->where($read->expr()->isNull('seq'))
			->orderBy('register_id', 'ASC')
			->addOrderBy('id', 'ASC');
		$result = $read->executeQuery();

		$counters = [];
		$updated = 0;
		while ($row = $result->fetch()) {
			$registerId = (int)$row['register_id'];
			$next = ($counters[$registerId] ?? 0) + 1;
			$counters[$registerId] = $next;

			$upd = $this->db->getQueryBuilder();
			$upd->update('df_records')
				->set('seq', $upd->createNamedParameter($next, IQueryBuilder::PARAM_INT))
				->where($upd->expr()->eq('id', $upd->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)));
			$upd->executeStatement();
			$updated++;
		}
		$result->closeCursor();

		if ($updated > 0) {
			$output->info("Dataforms: assigned per-register sequence numbers to $updated existing record(s).");
		}
	}
}
