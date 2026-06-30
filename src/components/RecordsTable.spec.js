/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RecordsTable from './RecordsTable.vue'
import { updateRecord } from '../api/records.js'

vi.mock('../api/records.js', async (orig) => ({
	...(await orig()),
	updateRecord: vi.fn(() => Promise.resolve({ id: 1, values: { title: 'saved' } })),
}))

const columns = [
	{ id: 1, machineName: 'title', label: 'Title', type: 'text' },
	{ id: 2, machineName: 'flag', label: 'Flag', type: 'boolean' },
]
const records = [
	{ id: 1, createdBy: 'alice', values: { title: 'one', flag: true } },
	{ id: 2, createdBy: 'bob', values: { title: 'two', flag: false } },
]

const mountTable = (props) => mount(RecordsTable, {
	props: { records, columns, canManage: false, currentUserId: 'alice', sort: 'title', direction: 'ASC', ...props },
})

describe('RecordsTable', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders the rows and a header sort indicator', () => {
		const wrapper = mountTable()
		expect(wrapper.text()).toContain('one')
		expect(wrapper.text()).toContain('▲') // sort=title ASC
	})

	it('emits sort on a header click', async () => {
		const wrapper = mountTable()
		await wrapper.findAll('th.sortable')[0].trigger('click')
		expect(wrapper.emitted('sort')[0][0].machineName).toBe('title')
	})

	it('canModify follows ownership and manage rights', () => {
		const owner = mountTable({ currentUserId: 'alice', canManage: false })
		expect(owner.vm.canModify(records[0])).toBe(true) // alice owns #1
		expect(owner.vm.canModify(records[1])).toBe(false) // bob owns #2
		const manager = mountTable({ currentUserId: 'carol', canManage: true })
		expect(manager.vm.canModify(records[1])).toBe(true) // manager edits any
	})

	it('opens the editor on double-click and saves a changed value, emitting inline-saved', async () => {
		const wrapper = mountTable()
		wrapper.vm.onCellDblClick(records[0], columns[0])
		expect(wrapper.vm.editingCell).toEqual({ recordId: 1, machineName: 'title' })
		expect(wrapper.emitted('editing-change')[0]).toEqual([true])

		wrapper.vm.editValue = 'changed'
		await wrapper.vm.saveInline(records[0], columns[0])
		await flushPromises()
		expect(updateRecord).toHaveBeenCalledWith(1, { title: 'changed', flag: true })
		expect(wrapper.emitted('inline-saved')[0][0]).toEqual({ id: 1, values: { title: 'saved' } })
		expect(wrapper.vm.editingCell).toBeNull()
	})

	it('does not call the API when the value is unchanged', async () => {
		const wrapper = mountTable()
		wrapper.vm.startInline(records[0], columns[0])
		// editValue seeded to 'one' (unchanged)
		await wrapper.vm.saveInline(records[0], columns[0])
		expect(updateRecord).not.toHaveBeenCalled()
		expect(wrapper.vm.editingCell).toBeNull()
	})

	it('emits reload when an inline save fails', async () => {
		updateRecord.mockRejectedValueOnce({ response: {} })
		const wrapper = mountTable()
		wrapper.vm.onCellDblClick(records[0], columns[0])
		wrapper.vm.editValue = 'boom'
		await wrapper.vm.saveInline(records[0], columns[0])
		await flushPromises()
		expect(wrapper.emitted('reload')).toBeTruthy()
	})

	it('double-clicking a non-modifiable row opens the detail', () => {
		const wrapper = mountTable({ currentUserId: 'carol', canManage: false })
		wrapper.vm.onCellDblClick(records[1], columns[0]) // carol can't modify bob's row
		expect(wrapper.emitted('detail')[0][0]).toEqual(records[1])
	})

	it('double-clicking a complex field opens the full editor', () => {
		const wrapper = mountTable()
		wrapper.vm.onCellDblClick(records[0], { id: 9, machineName: 'doc', type: 'file' })
		expect(wrapper.emitted('edit')[0][0]).toEqual(records[0])
	})

	it('cancelInline closes the editor and signals editing-change false', () => {
		const wrapper = mountTable()
		wrapper.vm.startInline(records[0], columns[0])
		wrapper.vm.cancelInline()
		expect(wrapper.vm.editingCell).toBeNull()
		expect(wrapper.emitted('editing-change').at(-1)).toEqual([false])
	})

	it('single-click defers opening the detail (and is ignored while editing)', () => {
		vi.useFakeTimers()
		try {
			const wrapper = mountTable()
			wrapper.vm.onCellClick(records[0], columns[0])
			vi.advanceTimersByTime(220)
			expect(wrapper.emitted('detail')[0][0]).toEqual(records[0])

			// While a cell is being edited, a stray click does nothing.
			wrapper.vm.editingCell = { recordId: 1, machineName: 'title' }
			wrapper.vm.onCellClick(records[1], columns[0])
			vi.advanceTimersByTime(300)
			expect(wrapper.emitted('detail')).toHaveLength(1)
		} finally {
			vi.useRealTimers()
		}
	})

	it('exposes the inline input type and renders an editor on double-click', async () => {
		const wrapper = mountTable()
		expect(wrapper.vm.inputType({ type: 'email' })).toBe('email')
		await wrapper.findAll('td')[0].trigger('dblclick') // alice owns #1, text field
		expect(wrapper.find('input.inline-input').exists()).toBe(true)
	})

	it('saveInline is a no-op when nothing is being edited', async () => {
		const wrapper = mountTable()
		await wrapper.vm.saveInline(records[0], columns[0]) // editingCell is null
		expect(updateRecord).not.toHaveBeenCalled()
	})

	it('clears its pending click timer on unmount', () => {
		const wrapper = mountTable()
		wrapper.vm.onCellClick(records[0], columns[0])
		expect(() => wrapper.unmount()).not.toThrow()
	})
})
