/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the records filter builder (extracted from RecordsView, #27).
 * No Vue, no DOM — directly unit-testable.
 */

/**
 * Fields a user can filter on. 'auto' values are computed at read time (no
 * stored column to filter); file/relation live in join tables.
 *
 * @param {import('@/types/models').Field[]} fields
 * @return {Array<{id:string,label:string}>}
 */
export function filterableFieldOptions(fields) {
	return fields
		.filter((f) => !['file', 'relation', 'auto'].includes(f.type))
		.map((f) => ({ id: f.machineName, label: f.label }))
}

/**
 * Options for a select/multi-select filter value (empty for other types).
 *
 * @param {import('@/types/models').Field[]} fields
 * @param {string} machineName
 * @return {any[]}
 */
export function filterValueOptions(fields, machineName) {
	const f = fields.find((x) => x.machineName === machineName)
	return /** @type {any[]} */ ((f && ['select', 'multiselect'].includes(f.type)) ? (f.config?.options ?? []) : [])
}

/**
 * HTML input type for a field's free-text filter value (date/number where
 * useful). 'date' is valid at runtime though NcTextField's prop type omits it.
 *
 * @param {import('@/types/models').Field[]} fields
 * @param {string} machineName
 * @return {any}
 */
export function filterValueInputType(fields, machineName) {
	const f = fields.find((x) => x.machineName === machineName)
	if (!f) return 'text'
	if (['number', 'currency', 'percentage'].includes(f.type)) return 'number'
	if (f.type === 'date') return 'date'
	return 'text'
}

/**
 * The operator to default to when a filter's field changes. Multi-select values
 * are stored as a JSON array, so they match by 'contains'; everything else 'eq'.
 *
 * @param {import('@/types/models').Field[]} fields
 * @param {string} machineName
 * @return {string}
 */
export function defaultOperatorForField(fields, machineName) {
	const f = fields.find((x) => x.machineName === machineName)
	return (f && f.type === 'multiselect') ? 'contains' : 'eq'
}
