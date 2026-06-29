/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Thin client for the registers OCS API.
 */
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

/**
 * @return {Promise<object[]>} registers visible to the current user
 */
export async function listRegisters() {
	return ocsGet('registers')
}

/**
 * @param {object} data {title, description, icon, color}
 * @return {Promise<object>} the created register
 */
export async function createRegister(data) {
	return ocsPost('registers', data)
}

/**
 * @param {number} id register id
 * @param {object} data partial {title, description, icon, color}
 * @return {Promise<object>} the updated register
 */
export async function updateRegister(id, data) {
	return ocsPut(`registers/${id}`, data)
}

/**
 * @param {number} id register id
 */
export async function deleteRegister(id) {
	await ocsDelete(`registers/${id}`)
}

/**
 * Toggle a register as a favourite.
 *
 * @param {number} id register id
 * @param {boolean} favorite on/off
 * @return {Promise<object>} the updated register
 */
export async function favoriteRegister(id, favorite) {
	return ocsPost(`registers/${id}/favorite`, { favorite })
}

/** Icon + colour choices offered when creating a register. */
export const REGISTER_ICONS = ['table', 'clipboard', 'shield', 'database', 'cube', 'star', 'flag', 'chart']
export const REGISTER_COLORS = ['#0082c9', '#9d3a3a', '#a06800', '#2d7d46', '#7c5ba6', '#3f7068', '#46637d', '#8a8a8a']
