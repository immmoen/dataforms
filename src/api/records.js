/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the records OCS API, plus the local-upload and CSV export URL
 * helpers (which use the normal app route, not OCS).
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

/**
 * @param {number} registerId register id
 * @param {object} params {limit, offset, sort, direction, search}
 * @return {Promise<{records: object[], total: number, fields: object[]}>}
 */
export async function listRecords(registerId, params = {}) {
	return ocsGet(`registers/${registerId}/records`, params)
}

/**
 * @param {number} registerId register id
 * @param {object} values machineName -> value
 * @return {Promise<object>} the created record
 */
export async function createRecord(registerId, values) {
	return ocsPost(`registers/${registerId}/records`, { values })
}

/**
 * @param {number} id record id
 * @param {object} values machineName -> value
 * @return {Promise<object>} the updated record
 */
export async function updateRecord(id, values) {
	return ocsPut(`records/${id}`, { values })
}

/**
 * @param {number} id record id
 */
export async function deleteRecord(id) {
	await ocsDelete(`records/${id}`)
}

/**
 * Audit history for a record (most recent first).
 *
 * @param {number} id record id
 * @return {Promise<{action:string,user:string,summary:string,detail:object,created:number}[]>}
 */
export async function listHistory(id) {
	return ocsGet(`records/${id}/history`)
}

/**
 * Pickable options for a relation target register.
 *
 * @param {number} registerId target register id
 * @param {object} params {display, search}
 * @return {Promise<{id:number,label:string}[]>}
 */
export async function listOptions(registerId, params = {}) {
	return ocsGet(`registers/${registerId}/options`, params)
}

/**
 * Resolve a picked file path to its id and name.
 *
 * @param {string} path the file path from the file picker
 * @return {Promise<{id:number,name:string}>}
 */
export async function resolveFile(path) {
	return ocsGet('files/resolve', { path })
}

/**
 * Upload a file from the user's computer into their Nextcloud Files; returns
 * its id and name. Uses the normal (CSRF-protected) app route.
 *
 * @param {File} file the file to upload
 * @return {Promise<{id:number,name:string}>}
 */
export async function uploadLocalFile(file) {
	const form = new FormData()
	form.append('file', file)
	const res = await axios.post(generateUrl('/apps/dataforms/upload'), form, { timeout: 120000 })
	return res.data
}

/**
 * Import CSV text into a register.
 *
 * @param {number} registerId register id
 * @param {string} csv raw CSV text
 * @return {Promise<{imported:number,failed:number,errors:string[]}>}
 */
export async function importCsv(registerId, csv) {
	return ocsPost(`registers/${registerId}/import`, { csv })
}

/**
 * Direct download URL for the CSV export (normal route, not OCS).
 *
 * @param {number} registerId register id
 * @return {string}
 */
export function csvExportUrl(registerId) {
	return generateUrl(`/apps/dataforms/registers/${registerId}/export/csv`)
}
