/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the data-entry forms OCS API.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)
const config = { timeout: 30000, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }
const unwrap = (response) => response.data.ocs.data

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} forms for the register
 */
export async function listForms(registerId) {
	return unwrap(await axios.get(url(`registers/${registerId}/forms`), config))
}

/**
 * @param {number} registerId register id
 * @param {object} data {title, definition:{sections}}
 * @return {Promise<object>} the created form
 */
export async function createForm(registerId, data) {
	return unwrap(await axios.post(url(`registers/${registerId}/forms`), data, config))
}

/**
 * @param {number} id form id
 * @param {object} data partial {title, definition}
 * @return {Promise<object>} the updated form
 */
export async function updateForm(id, data) {
	return unwrap(await axios.put(url(`forms/${id}`), data, config))
}

/**
 * @param {number} id form id
 */
export async function deleteForm(id) {
	await axios.delete(url(`forms/${id}`), config)
}
