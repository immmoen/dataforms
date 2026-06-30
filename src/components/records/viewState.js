/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for saved-view / column-visibility state (extracted from
 * RecordsView, #27). No Vue, no I/O — directly unit-testable.
 *
 * The default view shows the first six fields; an explicit `visibleColumns`
 * list (machine names) overrides that.
 */

const DEFAULT_COLUMN_COUNT = 6

/**
 * The fields shown as columns for the current visibleColumns selection.
 *
 * @param {import('@/types/models').Field[]} fields
 * @param {string[]} visibleColumns machine names, or empty for the default set
 * @return {import('@/types/models').Field[]}
 */
export function columnsFor(fields, visibleColumns) {
	if (visibleColumns.length) {
		return /** @type {import('@/types/models').Field[]} */ (visibleColumns
			.map((mn) => fields.find((f) => f.machineName === mn))
			.filter(Boolean))
	}
	return fields.slice(0, DEFAULT_COLUMN_COUNT)
}

/**
 * Whether a field is currently shown as a column.
 *
 * @param {import('@/types/models').Field[]} fields
 * @param {string[]} visibleColumns
 * @param {import('@/types/models').Field} field
 * @return {boolean}
 */
export function isColumnVisible(fields, visibleColumns, field) {
	return visibleColumns.length
		? visibleColumns.includes(field.machineName)
		: fields.slice(0, DEFAULT_COLUMN_COUNT).some((f) => f.id === field.id)
}

/**
 * The visibleColumns list after toggling one field, materialising the implicit
 * default set first so the toggle is relative to what is actually shown.
 *
 * @param {import('@/types/models').Field[]} fields
 * @param {string[]} visibleColumns
 * @param {import('@/types/models').Field} field
 * @return {string[]}
 */
export function toggleColumnList(fields, visibleColumns, field) {
	const base = visibleColumns.length
		? [...visibleColumns]
		: fields.slice(0, DEFAULT_COLUMN_COUNT).map((f) => f.machineName)
	const i = base.indexOf(field.machineName)
	if (i === -1) {
		base.push(field.machineName)
	} else {
		base.splice(i, 1)
	}
	return base
}

/**
 * The persisted definition for "save current view".
 *
 * @param {object} state
 * @param {string[]} state.columns
 * @param {Array<object>} state.filters
 * @param {string} state.sort
 * @param {string} state.direction
 * @param {string} state.search
 * @return {object}
 */
export function viewDefinition({ columns, filters, sort, direction, search }) {
	return { columns, filters, sort, direction, search }
}

/**
 * The list-state patch a saved view applies when selected.
 *
 * @param {import('@/types/models').View|null} view
 * @return {{visibleColumns:string[],activeFilters:Array<object>,search:string,sort:string,direction:string}}
 */
export function stateFromView(view) {
	const d = /** @type {any} */ (view?.definition ?? {})
	return {
		visibleColumns: Array.isArray(d.columns) ? d.columns : [],
		activeFilters: Array.isArray(d.filters) ? d.filters : [],
		search: d.search ?? '',
		sort: d.sort ?? 'updated',
		direction: d.direction ?? 'DESC',
	}
}
