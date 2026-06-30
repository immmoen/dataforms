/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * ShareDialog: the manager-facing register sharing UI. Covers loading the share
 * list, the sharee typeahead (debounced), adding a share at a chosen role,
 * changing a role, and removing access — plus the error paths. The API is
 * mocked; the role↔bitmask mapping lives in src/api/shares.spec.js.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import ShareDialog from './ShareDialog.vue'
import { searchSharees, addShare, updateShare, removeShare } from '../api/shares.js'

vi.mock('../api/shares.js', async (orig) => ({
	...(await orig()),
	listShares: vi.fn(() => Promise.resolve([
		{ id: 0, shareType: 'user', shareWith: 'alice', permissions: 7, isOwner: true },
		{ id: 2, shareType: 'user', shareWith: 'bob', permissions: 3, isOwner: false },
	])),
	searchSharees: vi.fn(() => Promise.resolve([{ id: 'carol', label: 'Carol', sub: 'carol', type: 'user' }])),
	addShare: vi.fn(() => Promise.resolve({})),
	updateShare: vi.fn(() => Promise.resolve({})),
	removeShare: vi.fn(() => Promise.resolve()),
}))

const mountDialog = async () => {
	const wrapper = mount(ShareDialog, { props: { register: { id: 5, title: 'Fines' } } })
	await flushPromises()
	return wrapper
}

describe('ShareDialog', () => {
	beforeEach(() => vi.clearAllMocks())

	it('loads the share list (owner first)', async () => {
		const wrapper = await mountDialog()
		expect(wrapper.vm.shares).toHaveLength(2)
		expect(wrapper.vm.shares[0].isOwner).toBe(true)
	})

	it('debounces the sharee search and clears it for a blank query', async () => {
		vi.useFakeTimers()
		try {
			const wrapper = await mountDialog()
			wrapper.vm.onShareeSearch('car')
			vi.advanceTimersByTime(250)
			await flushPromises()
			expect(searchSharees).toHaveBeenCalledWith(5, 'car')
			expect(wrapper.vm.shareeOptions).toHaveLength(1)

			wrapper.vm.onShareeSearch('   ') // blank → cleared, no call
			expect(wrapper.vm.shareeOptions).toEqual([])
		} finally {
			vi.useRealTimers()
		}
	})

	it('adds a share at the chosen role and reloads', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.selectedSharee = { id: 'carol', type: 'user' }
		wrapper.vm.newRole = 'write'
		await wrapper.vm.add()
		await flushPromises()
		expect(addShare).toHaveBeenCalledWith(5, { shareType: 'user', shareWith: 'carol', permissions: 3 }) // read|write
		expect(wrapper.vm.selectedSharee).toBeNull()
	})

	it('does not add without a selected sharee', async () => {
		const wrapper = await mountDialog()
		wrapper.vm.selectedSharee = null
		await wrapper.vm.add()
		expect(addShare).not.toHaveBeenCalled()
	})

	it('surfaces an add error and stays usable', async () => {
		addShare.mockRejectedValueOnce({ response: { data: { ocs: { data: { message: 'dup' } } } } })
		const wrapper = await mountDialog()
		wrapper.vm.selectedSharee = { id: 'carol', type: 'user' }
		wrapper.vm.newRole = 'read'
		await wrapper.vm.add()
		await flushPromises()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('changes a role via updateShare', async () => {
		const wrapper = await mountDialog()
		await wrapper.vm.changeRole({ id: 2 }, 'manage')
		await flushPromises()
		expect(updateShare).toHaveBeenCalledWith(2, 7) // read|write|manage
	})

	it('handles a change-role failure', async () => {
		updateShare.mockRejectedValueOnce(new Error('down'))
		const wrapper = await mountDialog()
		await wrapper.vm.changeRole({ id: 2 }, 'read')
		await flushPromises()
		expect(updateShare).toHaveBeenCalled() // error swallowed
	})

	it('removes a share, dropping it from the list', async () => {
		const wrapper = await mountDialog()
		await wrapper.vm.remove({ id: 2 })
		await flushPromises()
		expect(removeShare).toHaveBeenCalledWith(2)
		expect(wrapper.vm.shares.find((s) => s.id === 2)).toBeUndefined()
	})

	it('keeps the share on a remove failure', async () => {
		removeShare.mockRejectedValueOnce(new Error('down'))
		const wrapper = await mountDialog()
		await wrapper.vm.remove({ id: 2 })
		await flushPromises()
		expect(wrapper.vm.shares.find((s) => s.id === 2)).toBeTruthy()
	})

	it('clears the loading flag if the share list fails to load', async () => {
		const { listShares } = await import('../api/shares.js')
		listShares.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mount(ShareDialog, { props: { register: { id: 9, title: 'X' } } })
		await flushPromises()
		expect(wrapper.vm.loading).toBe(false)
	})
})
