/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * The file-attachment transport in the records API client: resolve a picked
 * path to an id (OCS) and upload a local file to the app route (multipart).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import axios from '@nextcloud/axios'
import { ocsGet } from './ocs.js'
import { resolveFile, uploadLocalFile } from './records.js'

vi.mock('./ocs.js', async (orig) => ({
	...(await orig()),
	ocsGet: vi.fn(() => Promise.resolve({ id: 5, name: 'p.png' })),
}))

describe('api/records (files)', () => {
	beforeEach(() => vi.clearAllMocks())

	it('resolves a picked path via OCS', async () => {
		const out = await resolveFile('Photos/p.png')
		expect(ocsGet).toHaveBeenCalledWith('files/resolve', { path: 'Photos/p.png' })
		expect(out).toEqual({ id: 5, name: 'p.png' })
	})

	it('uploads a local file as multipart to the app route and returns the body', async () => {
		axios.post.mockResolvedValueOnce({ data: { id: 9, name: 'a.txt' } })
		const file = new File(['hi'], 'a.txt')
		const out = await uploadLocalFile(file)

		expect(out).toEqual({ id: 9, name: 'a.txt' })
		const [url, form, opts] = axios.post.mock.calls[0]
		expect(url).toContain('/apps/dataforms/upload')
		expect(form).toBeInstanceOf(FormData)
		expect(form.get('file')).toBe(file)
		expect(opts.timeout).toBe(120000)
	})
})
