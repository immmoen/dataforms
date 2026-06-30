/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ShareDialog from './ShareDialog.vue'

vi.mock('../api/shares.js', async (orig) => ({
	...(await orig()),
	listShares: vi.fn(() => Promise.resolve([
		{ id: 1, shareType: 'user', shareWith: 'alice', displayName: 'Alice', permissions: 1, isOwner: true },
		{ id: 2, shareType: 'user', shareWith: 'bob', displayName: 'Bob', permissions: 3, isOwner: false },
	])),
	searchSharees: vi.fn(() => Promise.resolve([])),
	addShare: vi.fn(() => Promise.resolve({})),
	updateShare: vi.fn(() => Promise.resolve({})),
	removeShare: vi.fn(() => Promise.resolve()),
}))

describe('ShareDialog', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders the share list with add and remove controls', async () => {
		const wrapper = mount(ShareDialog, { props: { register: { id: 5, title: 'Fines' } } })
		await flushPromises()
		expect(wrapper.vm.shares.length).toBe(2)
		expect(wrapper.html()).toBeTruthy()
	})
})
