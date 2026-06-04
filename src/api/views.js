/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the saved-views OCS API.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)
const config = { timeout: 30000, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }
const unwrap = (response) => response.data.ocs.data

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} saved views visible to the user
 */
export async function listViews(registerId) {
	return unwrap(await axios.get(url(`registers/${registerId}/views`), config))
}

/**
 * @param {number} registerId register id
 * @param {object} data {title, definition, shared}
 * @return {Promise<object>} the created view
 */
export async function createView(registerId, data) {
	return unwrap(await axios.post(url(`registers/${registerId}/views`), data, config))
}

/**
 * @param {number} id view id
 * @param {object} data partial {title, definition, shared}
 * @return {Promise<object>} the updated view
 */
export async function updateView(id, data) {
	return unwrap(await axios.put(url(`views/${id}`), data, config))
}

/**
 * @param {number} id view id
 */
export async function deleteView(id) {
	await axios.delete(url(`views/${id}`), config)
}
