/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the register shares OCS API.
 */
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

/** Permission bits (mirror lib/Db/Share.php). */
export const PERM_READ = 1
export const PERM_WRITE = 2
export const PERM_MANAGE = 4

/** Roles map to bitmasks; higher roles include the lower bits. */
export const ROLES = [
	{ id: 'read', label: 'Read', permissions: PERM_READ },
	{ id: 'write', label: 'Write', permissions: PERM_READ | PERM_WRITE },
	{ id: 'manage', label: 'Manage', permissions: PERM_READ | PERM_WRITE | PERM_MANAGE },
]

/**
 * @param {number} permissions bitmask
 * @return {string} role id
 */
export function roleOf(permissions) {
	if (permissions & PERM_MANAGE) return 'manage'
	if (permissions & PERM_WRITE) return 'write'
	return 'read'
}

/**
 * @param {string} roleId role id
 * @return {number} permission bitmask
 */
export function permissionsOf(roleId) {
	return ROLES.find((r) => r.id === roleId)?.permissions ?? PERM_READ
}

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} shares (first entry is the owner)
 */
export async function listShares(registerId) {
	return ocsGet(`registers/${registerId}/shares`)
}

/**
 * Typeahead search for users/groups to share with.
 *
 * @param {number} registerId register id
 * @param {string} search query
 * @return {Promise<{id:string,label:string,sub:string,type:string}[]>}
 */
export async function searchSharees(registerId, search) {
	return ocsGet(`registers/${registerId}/sharees`, { search })
}

/**
 * @param {number} registerId register id
 * @param {object} data {shareType:'user'|'group', shareWith, permissions}
 * @return {Promise<object>} the created/updated share
 */
export async function addShare(registerId, data) {
	return ocsPost(`registers/${registerId}/shares`, data)
}

/**
 * @param {number} id share id
 * @param {number} permissions bitmask
 * @return {Promise<object>} the updated share
 */
export async function updateShare(id, permissions) {
	return ocsPut(`shares/${id}`, { permissions })
}

/**
 * @param {number} id share id
 */
export async function removeShare(id) {
	await ocsDelete(`shares/${id}`)
}
