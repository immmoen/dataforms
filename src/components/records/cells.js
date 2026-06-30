/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Pure helpers for the records table cells: inline-edit type rules, value
 * seeding/coercion, and display formatting (extracted from RecordsView, #27).
 * No Vue, no DOM. `t` is injected so these stay framework-free and testable.
 */

/**
 * Simple, single-value types can be edited in place. Multi-value and resolved
 * types (relation/file/multiselect) and read-only computed/auto fall back to
 * the full edit dialog.
 *
 * @param {import('@/types/models').Field} field
 * @return {boolean}
 */
export function isInlineEditable(field) {
	return ['text', 'email', 'url', 'phone', 'number', 'currency', 'percentage',
		'date', 'datetime', 'time', 'select', 'boolean'].includes(field.type)
}

/**
 * The HTML input type for an inline text editor.
 *
 * @param {import('@/types/models').Field} field
 * @return {string}
 */
export function inlineInputType(field) {
	return {
		email: 'email',
		url: 'url',
		phone: 'tel',
		number: 'number',
		currency: 'number',
		percentage: 'number',
		date: 'date',
		datetime: 'datetime-local',
		time: 'time',
	}[field.type] ?? 'text'
}

/**
 * The editor's seed value (string) for a field's raw stored value. Booleans use
 * 'true'/'false'/'' (unset); null/undefined become ''.
 *
 * @param {import('@/types/models').Field} field
 * @param {any} raw
 * @return {any}
 */
export function seedInlineValue(field, raw) {
	if (field.type === 'boolean') {
		return raw === true ? 'true' : (raw === false ? 'false' : '')
	}
	return raw === null || raw === undefined ? '' : raw
}

/**
 * Coerce the editor's string value back to the logical type on save.
 *
 * @param {import('@/types/models').Field} field
 * @param {any} editValue
 * @return {any}
 */
export function coerceInlineValue(field, editValue) {
	if (field.type === 'boolean') {
		return editValue === 'true' ? true : (editValue === 'false' ? false : null)
	}
	if (['number', 'currency', 'percentage'].includes(field.type)) {
		return editValue === '' ? null : Number(editValue)
	}
	return editValue === '' ? null : editValue
}

/**
 * Display string for a cell value.
 *
 * @param {import('@/types/models').Field} field
 * @param {any} value
 * @param {(app:string,text:string,vars?:object)=>string} t translator
 * @return {string}
 */
export function formatCell(field, value, t) {
	if (value === null || value === undefined) return ''
	if (field.type === 'file') {
		const list = Array.isArray(value) ? value : (value && value.id ? [value] : [])
		if (list.length === 0) return ''
		return list.length === 1 ? '📎 ' + list[0].name : '📎 ' + t('dataforms', '{n} files', { n: list.length })
	}
	if (field.type === 'relation') {
		const list = Array.isArray(value) ? value : [value]
		return list.filter(Boolean).map((v) => (v && typeof v === 'object' && 'label' in v) ? v.label : String(v)).join(', ')
	}
	if (['number', 'currency', 'percentage'].includes(field.type) && value !== '' && !isNaN(Number(value))) {
		const dec = Number(field.config?.decimals ?? (field.type === 'currency' ? 2 : 0))
		return Number(value).toLocaleString(undefined, { minimumFractionDigits: dec, maximumFractionDigits: dec })
	}
	if (Array.isArray(value)) return value.join(', ') // multiselect
	if (typeof value === 'boolean') return value ? t('dataforms', 'Yes') : t('dataforms', 'No')
	if (typeof value === 'object' && 'label' in value) return value.label // relation
	return String(value)
}
