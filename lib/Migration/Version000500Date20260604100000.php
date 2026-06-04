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
 * Data-entry forms: a register can have one or more forms, each selecting which
 * fields to show, in what order, grouped into sections. The definition is an
 * opaque JSON blob (never queried by value).
 */
class Version000500Date20260604100000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('df_forms')) {
			$t = $schema->createTable('df_forms');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			// JSON: { sections: [ { title, fields: [machineName, ...] } ] }
			$t->addColumn('definition', Types::TEXT, ['notnull' => false]);
			$t->addColumn('position', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['register_id'], 'df_forms_reg_idx');
		}

		return $schema;
	}
}
