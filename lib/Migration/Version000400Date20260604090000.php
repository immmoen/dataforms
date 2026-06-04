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
 * Saved views: a named, optionally-shared combination of columns, filters, sort
 * and search for a register's records. The definition is an opaque JSON blob
 * (never queried by value), so it stays portable.
 */
class Version000400Date20260604090000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('df_views')) {
			$t = $schema->createTable('df_views');
			$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('register_id', Types::BIGINT, ['notnull' => true]);
			$t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
			$t->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
			// Nullable boolean (NC portability); null/false = private to the owner.
			$t->addColumn('shared', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$t->addColumn('definition', Types::TEXT, ['notnull' => false]);
			$t->addColumn('created', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->addColumn('updated', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$t->setPrimaryKey(['id']);
			$t->addIndex(['register_id'], 'df_views_reg_idx');
		}

		return $schema;
	}
}
