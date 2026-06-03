/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the fields (schema) OCS API.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

// Build the path literally — a {placeholder} would percent-encode slashes.
const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)

const config = {
	headers: {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	},
}

const unwrap = (response) => response.data.ocs.data

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} fields ordered by position
 */
export async function listFields(registerId) {
	return unwrap(await axios.get(url(`registers/${registerId}/fields`), config))
}

/**
 * @param {number} registerId register id
 * @param {object} data {label, type, machineName?, config?, mandatory?, unique?, default?}
 * @return {Promise<object>} the created field
 */
export async function createField(registerId, data) {
	return unwrap(await axios.post(url(`registers/${registerId}/fields`), data, config))
}

/**
 * @param {number} id field id
 * @param {object} data partial {label, config, mandatory, unique, default}
 * @return {Promise<object>} the updated field
 */
export async function updateField(id, data) {
	return unwrap(await axios.put(url(`fields/${id}`), data, config))
}

/**
 * @param {number} id field id
 */
export async function deleteField(id) {
	await axios.delete(url(`fields/${id}`), config)
}

/**
 * @param {number} registerId register id
 * @param {number[]} order field ids in the desired order
 * @return {Promise<object[]>} the reordered fields
 */
export async function reorderFields(registerId, order) {
	return unwrap(await axios.post(url(`registers/${registerId}/fields/reorder`), { order }, config))
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
]

/**
 * @param {string} typeId a field type id
 * @return {string} its human label (falls back to the id)
 */
export function typeLabel(typeId) {
	return FIELD_TYPES.find((t) => t.id === typeId)?.label ?? typeId
}
