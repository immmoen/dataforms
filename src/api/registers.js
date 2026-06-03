/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin client for the registers OCS API. Unwraps the OCS envelope so callers
 * get plain data.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

// Build the path literally — do NOT use a {placeholder}, which would
// percent-encode the slashes in nested paths (registers/1/fields).
const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)

const config = {
	headers: {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	},
}

const unwrap = (response) => response.data.ocs.data

/**
 * @return {Promise<object[]>} registers visible to the current user
 */
export async function listRegisters() {
	return unwrap(await axios.get(url('registers'), config))
}

/**
 * @param {object} data {title, description, icon, color}
 * @return {Promise<object>} the created register
 */
export async function createRegister(data) {
	return unwrap(await axios.post(url('registers'), data, config))
}

/**
 * @param {number} id register id
 * @param {object} data partial {title, description, icon, color}
 * @return {Promise<object>} the updated register
 */
export async function updateRegister(id, data) {
	return unwrap(await axios.put(url(`registers/${id}`), data, config))
}

/**
 * @param {number} id register id
 */
export async function deleteRegister(id) {
	await axios.delete(url(`registers/${id}`), config)
}
