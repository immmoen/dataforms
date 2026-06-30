/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Single transport seam for the Dataforms OCS API. Every resource client
 * (registers, fields, records, …) goes through these helpers, so the OCS
 * envelope handling, the request headers and the base path live in exactly
 * one place instead of being repeated in each module.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

/**
 * Build an absolute OCS URL for an api/v1 path. The path is concatenated
 * literally — NOT via a `{placeholder}` — so the slashes in nested paths such
 * as `registers/1/fields` are not percent-encoded.
 *
 * @param {string} path the path under `apps/dataforms/api/v1/`
 * @return {string} the absolute OCS URL
 */
export const ocsUrl = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)

const config = {
	timeout: 30000,
	headers: {
		'OCS-APIRequest': 'true',
		Accept: 'application/json',
	},
}

/**
 * Unwrap the OCS envelope so callers get plain data.
 *
 * @param {object} response the axios response
 * @return {*} the `ocs.data` payload
 */
const unwrap = (response) => response.data.ocs.data

/**
 * @param {string} path the api/v1 path
 * @param {object} [params] optional query parameters
 * @return {Promise<*>} the unwrapped OCS data
 */
export async function ocsGet(path, params) {
	return unwrap(await axios.get(ocsUrl(path), params ? { ...config, params } : config))
}

/**
 * @param {string} path the api/v1 path
 * @param {object} [data] the request body
 * @return {Promise<*>} the unwrapped OCS data
 */
export async function ocsPost(path, data) {
	return unwrap(await axios.post(ocsUrl(path), data, config))
}

/**
 * @param {string} path the api/v1 path
 * @param {object} [data] the request body
 * @return {Promise<*>} the unwrapped OCS data
 */
export async function ocsPut(path, data) {
	return unwrap(await axios.put(ocsUrl(path), data, config))
}

/**
 * DELETE returns no body.
 *
 * @param {string} path the api/v1 path
 * @return {Promise<void>}
 */
export async function ocsDelete(path) {
	await axios.delete(ocsUrl(path), config)
}
