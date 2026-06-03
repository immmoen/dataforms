<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Conditional rules storage. A rule is a declarative JSON definition (a single
 * shared schema) evaluated by both the JS renderer and the PHP service, so the
 * two interpreters cannot diverge.
 */
class Version000200Date20260603140000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('df_rules')) {
			$t = $schema->createTable('df_rules');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			// show | require | set_value | validate | compute
			$t->addColumn('effect', Types::STRING, ['notnull' => true, 'length' => 32]);
			// machine_name of the field the effect applies to
			$t->addColumn('target', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Opaque JSON: { conditions, value, expression, validation }.
			$t->addColumn('definition', Types::TEXT, ['notnull' => false]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			// Nullable boolean (NC portability rule); null is treated as enabled.
			$t->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['register_id'], 'df_rules_reg_idx');
		}

		return $schema;
	}
}
