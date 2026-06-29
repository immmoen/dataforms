/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tests for the single OCS transport seam. Every resource client routes
 * through these four helpers, so asserting the request shape (method, URL,
 * headers, envelope unwrapping) here covers transport behaviour once.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'
import axios from '@nextcloud/axios'
import { ocsUrl, ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

const envelope = (data) => ({ data: { ocs: { data } } })

const EXPECTED_CONFIG = {
	timeout: 30000,
	headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
}

beforeEach(() => {
	vi.clearAllMocks()
	axios.get.mockResolvedValue(envelope(null))
	axios.post.mockResolvedValue(envelope(null))
	axios.put.mockResolvedValue(envelope(null))
	axios.delete.mockResolvedValue(envelope(null))
})

describe('ocsUrl', () => {
	it('builds an api/v1 path literally (no slash encoding)', () => {
		expect(ocsUrl('registers/1/fields')).toBe('/ocs/v2.php/apps/dataforms/api/v1/registers/1/fields')
	})
})

describe('ocsGet', () => {
	it('unwraps the OCS envelope and sends the standard config', async () => {
		axios.get.mockResolvedValueOnce(envelope([{ id: 1 }]))
		const result = await ocsGet('registers')
		expect(result).toEqual([{ id: 1 }])
		expect(axios.get).toHaveBeenCalledWith('/ocs/v2.php/apps/dataforms/api/v1/registers', EXPECTED_CONFIG)
	})

	it('passes query params when given', async () => {
		await ocsGet('registers/1/records', { search: 'x', limit: 10 })
		expect(axios.get).toHaveBeenCalledWith(
			'/ocs/v2.php/apps/dataforms/api/v1/registers/1/records',
			{ ...EXPECTED_CONFIG, params: { search: 'x', limit: 10 } },
		)
	})
})

describe('ocsPost', () => {
	it('sends the body and unwraps the envelope', async () => {
		axios.post.mockResolvedValueOnce(envelope({ id: 9 }))
		const result = await ocsPost('registers', { title: 'T' })
		expect(result).toEqual({ id: 9 })
		expect(axios.post).toHaveBeenCalledWith('/ocs/v2.php/apps/dataforms/api/v1/registers', { title: 'T' }, EXPECTED_CONFIG)
	})
})

describe('ocsPut', () => {
	it('sends the body and unwraps the envelope', async () => {
		axios.put.mockResolvedValueOnce(envelope({ id: 9, title: 'U' }))
		const result = await ocsPut('registers/9', { title: 'U' })
		expect(result).toEqual({ id: 9, title: 'U' })
		expect(axios.put).toHaveBeenCalledWith('/ocs/v2.php/apps/dataforms/api/v1/registers/9', { title: 'U' }, EXPECTED_CONFIG)
	})
})

describe('ocsDelete', () => {
	it('issues a DELETE and resolves to undefined (no body)', async () => {
		const result = await ocsDelete('registers/9')
		expect(result).toBeUndefined()
		expect(axios.delete).toHaveBeenCalledWith('/ocs/v2.php/apps/dataforms/api/v1/registers/9', EXPECTED_CONFIG)
	})
})
