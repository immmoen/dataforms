/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the fields (schema) OCS API.
 */
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} fields ordered by position
 */
export async function listFields(registerId) {
	return ocsGet(`registers/${registerId}/fields`)
}

/**
 * @param {number} registerId register id
 * @param {object} data {label, type, machineName?, config?, mandatory?, unique?, default?}
 * @return {Promise<object>} the created field
 */
export async function createField(registerId, data) {
	return ocsPost(`registers/${registerId}/fields`, data)
}

/**
 * @param {number} id field id
 * @param {object} data partial {label, config, mandatory, unique, default}
 * @return {Promise<object>} the updated field
 */
export async function updateField(id, data) {
	return ocsPut(`fields/${id}`, data)
}

/**
 * @param {number} id field id
 */
export async function deleteField(id) {
	await ocsDelete(`fields/${id}`)
}

/**
 * @param {number} registerId register id
 * @param {number[]} order field ids in the desired order
 * @return {Promise<object[]>} the reordered fields
 */
export async function reorderFields(registerId, order) {
	return ocsPost(`registers/${registerId}/fields/reorder`, { order })
}

/**
 * Field types, shared with the backend's FieldService::TYPES. Grouped for the
 * type picker. `config` lists which extra config keys the type supports.
 */
export const FIELD_TYPES = [
	{ id: 'text', label: 'Text', group: 'Basic', config: ['maxLength'] },
	{ id: 'longtext', label: 'Long text', group: 'Basic', config: ['maxLength'] },
	{ id: 'number', label: 'Number', group: 'Number', config: ['min', 'max', 'decimals'] },
	{ id: 'currency', label: 'Currency', group: 'Number', config: ['min', 'max', 'decimals'] },
	{ id: 'percentage', label: 'Percentage', group: 'Number', config: ['min', 'max', 'decimals'] },
	{ id: 'boolean', label: 'Yes / No', group: 'Basic', config: [] },
	{ id: 'date', label: 'Date', group: 'Date & time', config: [] },
	{ id: 'datetime', label: 'Date & time', group: 'Date & time', config: [] },
	{ id: 'time', label: 'Time', group: 'Date & time', config: [] },
	{ id: 'select', label: 'Single select', group: 'Choice', config: ['options'] },
	{ id: 'multiselect', label: 'Multi select', group: 'Choice', config: ['options'] },
	{ id: 'email', label: 'Email', group: 'Contact', config: [] },
	{ id: 'url', label: 'URL', group: 'Contact', config: [] },
	{ id: 'phone', label: 'Phone', group: 'Contact', config: [] },
	{ id: 'user', label: 'User', group: 'People', config: [] },
	{ id: 'group', label: 'Group', group: 'People', config: [] },
	{ id: 'relation', label: 'Relation (link records)', group: 'Advanced', config: ['target'] },
	{ id: 'file', label: 'File attachment', group: 'Advanced', config: [] },
	{ id: 'computed', label: 'Computed (expression)', group: 'Advanced', config: ['expression'] },
	{ id: 'auto', label: 'Automatic value (sequence number, dates, author)', group: 'Advanced', config: ['autoKind'] },
]

/** Auto-field kinds. */
export const AUTO_KINDS = [
	{ id: 'sequence', label: 'Sequence number (1, 2, 3 … per register)' },
	{ id: 'created_at', label: 'Created date/time' },
	{ id: 'updated_at', label: 'Last updated date/time' },
	{ id: 'created_by', label: 'Created by (user)' },
]

/**
 * Presets for grouping a long select/multi-select option list under collapsible
 * parents in the data-entry picker. Each pattern is a JS RegExp source; the
 * group label is the first match against an option (or "Other" if none).
 */
export const GROUP_PRESETS = [
	{ id: '', label: 'No grouping', pattern: '' },
	// "Art 6(1)(a)" → "Art 6"; "Art. 83 (2) (a)" → "Art. 83"
	{ id: 'code', label: 'By leading code (e.g. “Art 6”)', pattern: '^[A-Za-z.]+\\s*\\d+' },
	// "Digital Marketing" → "Digital"
	{ id: 'word', label: 'By first word', pattern: '^\\S+' },
	{ id: 'custom', label: 'Custom pattern…', pattern: null },
]

/**
 * Compute the group label for an option given a RegExp source. Returns 'Other'
 * when the pattern does not match, and the option itself when no pattern is set.
 * @param option
 * @param patternSource
 */
export function groupForOption(option, patternSource) {
	if (!patternSource) {
		return ''
	}
	try {
		const m = String(option).match(new RegExp(patternSource))
		return m ? (m[1] ?? m[0]).trim() : 'Other'
	} catch (e) {
		return ''
	}
}

/**
 * @param {string} typeId a field type id
 * @return {string} its human label (falls back to the id)
 */
export function typeLabel(typeId) {
	return FIELD_TYPES.find((t) => t.id === typeId)?.label ?? typeId
}
