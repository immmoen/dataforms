/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the data-entry forms OCS API.
 */
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} forms for the register
 */
export async function listForms(registerId) {
	return ocsGet(`registers/${registerId}/forms`)
}

/**
 * @param {number} registerId register id
 * @param {object} data {title, definition:{sections}}
 * @return {Promise<object>} the created form
 */
export async function createForm(registerId, data) {
	return ocsPost(`registers/${registerId}/forms`, data)
}

/**
 * @param {number} id form id
 * @param {object} data partial {title, definition}
 * @return {Promise<object>} the updated form
 */
export async function updateForm(id, data) {
	return ocsPut(`forms/${id}`, data)
}

/**
 * @param {number} id form id
 */
export async function deleteForm(id) {
	await ocsDelete(`forms/${id}`)
}
