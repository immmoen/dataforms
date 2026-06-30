/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * RecordsView is now a thin orchestrator: list loading, the toolbar, dialogs,
 * and wiring to the extracted RecordsFilterBar / RecordsTable sub-components and
 * the saved-view helpers. The inline-edit, filter and view-state logic is
 * tested at those units; here we cover the parent's coordination.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RecordsView from './RecordsView.vue'
import { listRecords } from '../api/records.js'
import { createView } from '../api/views.js'

vi.mock('../api/records.js', async (orig) => ({
	...(await orig()),
	listRecords: vi.fn(() => Promise.resolve({ records: [], total: 0, fields: [] })),
	deleteRecord: vi.fn(() => Promise.resolve()),
	importCsv: vi.fn(() => Promise.resolve({ imported: 0, failed: 0, errors: [] })),
}))
vi.mock('../api/rules.js', async (orig) => ({ ...(await orig()), listRules: vi.fn(() => Promise.resolve([])) }))
vi.mock('../api/views.js', async (orig) => ({
	...(await orig()),
	listViews: vi.fn(() => Promise.resolve([])),
	createView: vi.fn(() => Promise.resolve({ id: 9, title: 'V' })),
	deleteView: vi.fn(() => Promise.resolve()),
}))
vi.mock('../api/forms.js', async (orig) => ({ ...(await orig()), listForms: vi.fn(() => Promise.resolve([])) }))

const stub = { template: '<div />' }
const mountView = (props) => mount(RecordsView, {
	props: { registerId: 5, canWrite: true, canManage: true, ...props },
	global: { stubs: { RecordsTable: stub, RecordsFilterBar: stub, RecordForm: stub, RecordDetail: stub } },
})

describe('RecordsView', () => {
	beforeEach(() => vi.clearAllMocks())

	it('builds columns from the visible-column selection', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.fields = [{ id: 1, machineName: 't', label: 'T', type: 'text' }, { id: 2, machineName: 'n', label: 'N', type: 'number' }]
		wrapper.vm.visibleColumns = ['n']
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.columns.map((f) => f.machineName)).toEqual(['n'])
	})

	it('toggleFilterBar flips the filter bar', async () => {
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.vm.showFilter).toBe(false)
		wrapper.vm.toggleFilterBar()
		expect(wrapper.vm.showFilter).toBe(true)
	})

	it('applies and clears filters from the filter bar, reloading each time', async () => {
		const wrapper = mountView()
		await flushPromises()
		listRecords.mockClear()
		wrapper.vm.onFilterApply([{ field: 't', op: 'eq', value: 'x' }])
		expect(wrapper.vm.activeFilters).toEqual([{ field: 't', op: 'eq', value: 'x' }])
		await flushPromises()
		expect(listRecords).toHaveBeenCalledTimes(1)

		wrapper.vm.onFilterClear()
		expect(wrapper.vm.activeFilters).toEqual([])
		await flushPromises()
		expect(listRecords).toHaveBeenCalledTimes(2)
	})

	it('toggles column visibility', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.fields = [{ id: 1, machineName: 't', type: 'text' }, { id: 2, machineName: 'n', type: 'number' }]
		const field = wrapper.vm.fields[0]
		expect(wrapper.vm.isColumnVisible(field)).toBe(true) // default set
		wrapper.vm.toggleColumn(field)
		expect(wrapper.vm.isColumnVisible(field)).toBe(false)
	})

	it('selecting a view applies its state and reloads; clearing resets the active view', async () => {
		const wrapper = mountView()
		await flushPromises()
		listRecords.mockClear()
		wrapper.vm.onSelectView({ id: 3, definition: { columns: ['t'], filters: [{ field: 't', op: 'eq', value: 1 }], search: 'q', sort: 'created', direction: 'ASC' } })
		expect(wrapper.vm.activeViewId).toBe(3)
		expect(wrapper.vm.visibleColumns).toEqual(['t'])
		expect(wrapper.vm.activeFilters).toEqual([{ field: 't', op: 'eq', value: 1 }])
		expect(wrapper.vm.sort).toBe('created')
		await flushPromises()
		expect(listRecords).toHaveBeenCalledTimes(1)

		wrapper.vm.onSelectView(null)
		expect(wrapper.vm.activeViewId).toBeNull()
	})

	it('saves the current view from the live state', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.fields = [{ id: 1, machineName: 't', label: 'T', type: 'text' }]
		wrapper.vm.activeFilters = [{ field: 't', op: 'eq', value: 'x' }]
		wrapper.vm.newView = { title: 'My view', shared: true }
		await wrapper.vm.saveView()
		await flushPromises()
		const [registerId, payload] = createView.mock.calls[0]
		expect(registerId).toBe(5)
		expect(payload.title).toBe('My view')
		expect(payload.definition.filters).toEqual([{ field: 't', op: 'eq', value: 'x' }])
		expect(wrapper.vm.activeViewId).toBe(9)
	})

	it('deletes the active view after confirmation', async () => {
		window.confirm = vi.fn(() => true)
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.views = [{ id: 3, title: 'V', isOwner: true }]
		wrapper.vm.activeViewId = 3
		await wrapper.vm.removeActiveView()
		expect(wrapper.vm.views.find((v) => v.id === 3)).toBeUndefined()
	})

	it('onInlineSaved swaps the updated record into the list', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.records = [{ id: 1, values: { title: 'old' } }, { id: 2, values: {} }]
		wrapper.vm.onInlineSaved({ id: 1, values: { title: 'new' } })
		expect(wrapper.vm.records[0].values.title).toBe('new')
	})

	it('refreshIfIdle reloads only when nothing is mid-interaction', async () => {
		const wrapper = mountView()
		await flushPromises()
		listRecords.mockClear()
		// An open inline edit blocks the refresh.
		wrapper.vm.inlineEditing = true
		wrapper.vm.refreshIfIdle()
		expect(listRecords).not.toHaveBeenCalled()
		// Idle → it reloads.
		wrapper.vm.inlineEditing = false
		wrapper.vm.refreshIfIdle()
		await flushPromises()
		expect(listRecords).toHaveBeenCalledTimes(1)
	})

	it('toggleSort cycles direction and sets the sort field', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.toggleSort({ machineName: 'title' })
		expect(wrapper.vm.sort).toBe('title')
		expect(wrapper.vm.direction).toBe('ASC')
		wrapper.vm.toggleSort({ machineName: 'title' })
		expect(wrapper.vm.direction).toBe('DESC')
	})
})
