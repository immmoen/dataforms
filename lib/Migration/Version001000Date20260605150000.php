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
 * Workflow automations: react to a record event (create/update/delete) when an
 * optional condition holds, by running an action. Condition reuses the rule
 * schema; action_config is opaque JSON. One table, same pattern as rules/views.
 */
class Version001000Date20260605150000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('df_automations')) {
			$t = $schema->createTable('df_automations');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('trigger', Types::STRING, ['notnull' => true, 'length' => 16]);
			$t->addColumn('condition', Types::TEXT, ['notnull' => false]);
			$t->addColumn('action_type', Types::STRING, ['notnull' => true, 'length' => 32]);
			$t->addColumn('action_config', Types::TEXT, ['notnull' => false]);
			$t->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('updated', Types::BIGINT, ['notnull' => true]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['register_id', 'trigger'], 'df_auto_reg_trig_idx');
		}

		return $schema;
	}
}
