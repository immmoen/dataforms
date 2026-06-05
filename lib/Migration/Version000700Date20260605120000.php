<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Performance phase: index the text value column so filtering/sorting on text,
 * select, email and similar string-typed fields stays fast at scale (the §5
 * 100k-record target). value_string is a long column (4000 chars), so the
 * composite index uses a 64-char prefix on MySQL/MariaDB to stay under the
 * engine key-length limit; Doctrine applies the prefix on MySQL and omits it on
 * SQLite/PostgreSQL (which index the full value), keeping the migration
 * portable across all supported databases.
 */
class Version000700Date20260605120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('df_record_values')) {
			$t = $schema->getTable('df_record_values');
			if (!$t->hasIndex('df_rv_fstr_idx')) {
				$t->addIndex(['field_id', 'value_string'], 'df_rv_fstr_idx', [], ['lengths' => [null, 64]]);
			}
		}

		return $schema;
	}
}
