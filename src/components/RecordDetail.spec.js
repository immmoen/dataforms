/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * RecordDetail (AUD-04): the read-only record view and the collapsible audit
 * history panel. Covers field rendering for each value shape and the
 * lazy-loaded history timeline (with author + change detail).
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RecordDetail from './RecordDetail.vue'
import { listHistory } from '../api/records.js'

vi.mock('../api/records.js', async (orig) => ({
	...(await orig()),
	listHistory: vi.fn(() => Promise.resolve([])),
}))

const fields = [
	{ id: 1, machineName: 'title', label: 'Title', type: 'text' },
	{ id: 2, machineName: 'qty', label: 'Qty', type: 'number', config: { decimals: 1 } },
	{ id: 3, machineName: 'done', label: 'Done', type: 'boolean' },
	{ id: 4, machineName: 'parent', label: 'Parent', type: 'relation' },
	{ id: 5, machineName: 'doc', label: 'Doc', type: 'file' },
]
const record = {
	id: 9,
	values: { title: 'Hello', qty: 3, done: true, parent: { id: 2, label: 'Acme' }, doc: [{ id: 7, name: 'a.pdf' }] },
}

const mountDetail = (props = {}) => mount(RecordDetail, { props: { fields, record, canEdit: true, ...props } })

describe('RecordDetail', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders the fields and an Edit button when editable', async () => {
		const wrapper = mountDetail()
		await flushPromises()
		expect(wrapper.text()).toContain('Hello')
		expect(wrapper.text()).toContain('Acme') // relation label
	})

	it('emits edit when the Edit button is used', async () => {
		const wrapper = mountDetail()
		wrapper.vm.$emit('edit', record)
		expect(wrapper.emitted('edit')).toBeTruthy()
	})

	it('formats each value shape', () => {
		const wrapper = mountDetail()
		const vm = wrapper.vm
		expect(vm.display({ type: 'number', config: { decimals: 1 } }, 3)).toBe('3.0')
		expect(vm.display({ type: 'boolean' }, true)).toBe('Yes')
		expect(vm.display({ type: 'boolean' }, false)).toBe('No')
		expect(vm.display({ type: 'multiselect' }, ['a', 'b'])).toBe('a, b')
		expect(vm.display({ type: 'relation' }, { label: 'Beta' })).toBe('Beta')
		expect(vm.display({ type: 'text' }, 'plain')).toBe('plain')
		expect(vm.relationLabels([{ id: 1, label: 'A' }, { id: 2, label: 'B' }])).toBe('A, B')
		expect(vm.isRelation(fields[3])).toBe(true)
		expect(vm.fileItems(fields[4])).toEqual([{ id: 7, name: 'a.pdf' }])
		expect(vm.isEmpty('')).toBe(true)
		expect(vm.isEmpty([])).toBe(true)
		expect(vm.isEmpty('x')).toBe(false)
		expect(vm.fileUrl(7)).toContain('/f/')
		expect(vm.formatTime(0)).toBe('')
		expect(vm.formatTime(1_700_000_000)).not.toBe('')
	})

	it('lazy-loads the history timeline on first open (AUD-04)', async () => {
		listHistory.mockResolvedValueOnce([
			{ id: 2, action: 'update', user: 'alice', summary: 'Changed Title', detail: { fields: ['Title'] }, created: 1_700_000_100 },
			{ id: 1, action: 'create', user: 'alice', summary: 'Created record', detail: null, created: 1_700_000_000 },
		])
		const wrapper = mountDetail()
		await wrapper.vm.toggleHistory()
		await flushPromises()
		expect(listHistory).toHaveBeenCalledWith(9)
		expect(wrapper.vm.showHistory).toBe(true)
		expect(wrapper.text()).toContain('Changed Title')
		expect(wrapper.text()).toContain('Title') // the change detail
		expect(wrapper.text()).toContain('Created record')

		// Re-opening does not refetch (history already loaded).
		await wrapper.vm.toggleHistory() // close
		await wrapper.vm.toggleHistory() // open
		expect(listHistory).toHaveBeenCalledTimes(1)
	})

	it('shows the empty state when there is no history', async () => {
		listHistory.mockResolvedValueOnce([])
		const wrapper = mountDetail()
		await wrapper.vm.toggleHistory()
		await flushPromises()
		expect(wrapper.text()).toContain('No history recorded')
	})

	it('clears the loading flag if history fails to load', async () => {
		listHistory.mockRejectedValueOnce(new Error('down'))
		const wrapper = mountDetail()
		await wrapper.vm.toggleHistory()
		await flushPromises()
		expect(wrapper.vm.historyLoading).toBe(false)
	})
})
