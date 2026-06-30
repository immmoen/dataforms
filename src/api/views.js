/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the saved-views OCS API.
 */
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} saved views visible to the user
 */
export async function listViews(registerId) {
	return ocsGet(`registers/${registerId}/views`)
}

/**
 * @param {number} registerId register id
 * @param {object} data {title, definition, shared}
 * @return {Promise<object>} the created view
 */
export async function createView(registerId, data) {
	return ocsPost(`registers/${registerId}/views`, data)
}

/**
 * @param {number} id view id
 * @param {object} data partial {title, definition, shared}
 * @return {Promise<object>} the updated view
 */
export async function updateView(id, data) {
	return ocsPut(`views/${id}`, data)
}

/**
 * @param {number} id view id
 */
export async function deleteView(id) {
	await ocsDelete(`views/${id}`)
}
