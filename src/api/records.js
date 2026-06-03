/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the records OCS API, plus the CSV export URL helper.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

// Build the path literally — a {placeholder} would percent-encode slashes.
const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)
const config = { timeout: 30000, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }
const unwrap = (response) => response.data.ocs.data

/**
 * @param {number} registerId register id
 * @param {object} params {limit, offset, sort, direction, search}
 * @return {Promise<{records: object[], total: number, fields: object[]}>}
 */
export async function listRecords(registerId, params = {}) {
	return unwrap(await axios.get(url(`registers/${registerId}/records`), { ...config, params }))
}

/**
 * @param {number} registerId register id
 * @param {object} values machineName -> value
 * @return {Promise<object>} the created record
 */
export async function createRecord(registerId, values) {
	return unwrap(await axios.post(url(`registers/${registerId}/records`), { values }, config))
}

/**
 * @param {number} id record id
 * @param {object} values machineName -> value
 * @return {Promise<object>} the updated record
 */
export async function updateRecord(id, values) {
	return unwrap(await axios.put(url(`records/${id}`), { values }, config))
}

/**
 * @param {number} id record id
 */
export async function deleteRecord(id) {
	await axios.delete(url(`records/${id}`), config)
}

/**
 * Pickable options for a relation target register.
 *
 * @param {number} registerId target register id
 * @param {object} params {display, search}
 * @return {Promise<{id:number,label:string}[]>}
 */
export async function listOptions(registerId, params = {}) {
	return unwrap(await axios.get(url(`registers/${registerId}/options`), { ...config, params }))
}

/**
 * Resolve a picked file path to its id and name.
 *
 * @param {string} path the file path from the file picker
 * @return {Promise<{id:number,name:string}>}
 */
export async function resolveFile(path) {
	return unwrap(await axios.get(url('files/resolve'), { ...config, params: { path } }))
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
	return unwrap(await axios.post(url(`registers/${registerId}/import`), { csv }, config))
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
