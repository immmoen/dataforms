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
import { listRecords, deleteRecord, importCsv } from '../api/records.js'
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

describe('RecordsView — browsing & orchestration', () => {
	beforeEach(() => vi.clearAllMocks())

	it('debounces the search then reloads from page 0', async () => {
		vi.useFakeTimers()
		try {
			const wrapper = mountView()
			await flushPromises()
			listRecords.mockClear()
			wrapper.vm.page = 3
			wrapper.vm.onSearch()
			wrapper.vm.onSearch() // resets the debounce
			vi.advanceTimersByTime(300)
			expect(wrapper.vm.page).toBe(0)
			expect(listRecords).toHaveBeenCalledTimes(1)
		} finally {
			vi.useRealTimers()
		}
	})

	it('passes the active filters and search to the list query', async () => {
		const wrapper = mountView()
		await flushPromises()
		listRecords.mockClear()
		wrapper.vm.search = 'needle'
		wrapper.vm.activeFilters = [{ field: 'a', op: 'eq', value: 1 }]
		await wrapper.vm.load()
		const [, params] = listRecords.mock.calls[0]
		expect(params.search).toBe('needle')
		expect(params.filter).toBe(JSON.stringify([{ field: 'a', op: 'eq', value: 1 }]))
	})

	it('paginates with prev/next within bounds', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.total = 60
		wrapper.vm.limit = 25
		wrapper.vm.page = 0
		wrapper.vm.prev() // already at 0 → no-op
		expect(wrapper.vm.page).toBe(0)
		wrapper.vm.next()
		expect(wrapper.vm.page).toBe(1)
		wrapper.vm.next()
		expect(wrapper.vm.page).toBe(2)
		wrapper.vm.next() // (2+1)*25=75 >= 60 → no-op
		expect(wrapper.vm.page).toBe(2)
		wrapper.vm.prev()
		expect(wrapper.vm.page).toBe(1)
	})

	it('computes the range label', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.total = 0
		expect(wrapper.vm.rangeLabel).toBe('') // empty when there are no records
		wrapper.vm.total = 60
		wrapper.vm.limit = 25
		wrapper.vm.page = 1
		// The test t() stub doesn't interpolate {from}/{to}/{total}; assert the
		// label is produced (non-empty) once there are records.
		expect(wrapper.vm.rangeLabel).not.toBe('')
	})

	it('resets browsing state and reloads when the register changes', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.search = 'x'
		wrapper.vm.activeFilters = [{ field: 'a', op: 'eq', value: 1 }]
		wrapper.vm.activeViewId = 3
		listRecords.mockClear()
		await wrapper.setProps({ registerId: 8 })
		await flushPromises()
		expect(wrapper.vm.search).toBe('')
		expect(wrapper.vm.activeFilters).toEqual([])
		expect(wrapper.vm.activeViewId).toBeNull()
		expect(listRecords).toHaveBeenCalled()
	})

	it('opens the new / edit / detail flows and reflects ownership', async () => {
		const wrapper = mountView({ canManage: false })
		await flushPromises()
		wrapper.vm.currentUserId = 'alice'
		wrapper.vm.openNew({ id: 1 })
		expect(wrapper.vm.showForm).toBe(true)
		expect(wrapper.vm.activeForm).toEqual({ id: 1 })

		const rec = { id: 2, createdBy: 'alice' }
		wrapper.vm.openEdit(rec)
		expect(wrapper.vm.editing).toEqual(rec)
		expect(wrapper.vm.showForm).toBe(true)

		wrapper.vm.openDetail(rec)
		expect(wrapper.vm.detailRecord).toEqual(rec)
		expect(wrapper.vm.canModify(rec)).toBe(true) // own record
		expect(wrapper.vm.canModify({ id: 3, createdBy: 'bob' })).toBe(false)

		wrapper.vm.onDetailEdit(rec)
		expect(wrapper.vm.showForm).toBe(true)
		wrapper.vm.onSaved()
		expect(wrapper.vm.showForm).toBe(false)
	})

	it('deletes a record after confirmation and reloads', async () => {
		window.confirm = vi.fn(() => true)
		const wrapper = mountView()
		await flushPromises()
		listRecords.mockClear()
		await wrapper.vm.remove({ id: 7 })
		await flushPromises()
		expect(deleteRecord).toHaveBeenCalledWith(7)
		expect(listRecords).toHaveBeenCalled()
	})

	it('skips deletion when not confirmed', async () => {
		window.confirm = vi.fn(() => false)
		const wrapper = mountView()
		await flushPromises()
		await wrapper.vm.remove({ id: 7 })
		expect(deleteRecord).not.toHaveBeenCalled()
	})

	it('imports a CSV file and reloads, warning on partial failure', async () => {
		importCsv.mockResolvedValueOnce({ imported: 2, failed: 1, errors: ['row 3'] })
		const wrapper = mountView()
		await flushPromises()
		await wrapper.vm.onImportFile({ target: { files: [{ text: () => Promise.resolve('a,b') }], value: 'x' } })
		await flushPromises()
		expect(importCsv).toHaveBeenCalledWith(5, 'a,b')
		expect(wrapper.vm.importResult.failed).toBe(1)
	})

	it('ignores an import with no file', async () => {
		const wrapper = mountView()
		await flushPromises()
		await wrapper.vm.onImportFile({ target: { files: [], value: '' } })
		expect(importCsv).not.toHaveBeenCalled()
	})

	it('downloads a header template and triggers a CSV export', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.fields = [{ machineName: 't', label: 'Title', type: 'text' }, { machineName: 'r', label: 'Rel', type: 'relation' }]
		const createUrl = vi.fn(() => 'blob:x')
		const revoke = vi.fn()
		globalThis.URL.createObjectURL = createUrl
		globalThis.URL.revokeObjectURL = revoke
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		wrapper.vm.downloadTemplate()
		expect(createUrl).toHaveBeenCalled()
		click.mockRestore()

		// exportCsv navigates to the export URL.
		const loc = { href: '' }
		Object.defineProperty(window, 'location', { value: loc, writable: true })
		wrapper.vm.exportCsv()
		expect(loc.href).toContain('export/csv')
	})

	it('refreshes the list on tab/window return when idle, and cleans up listeners', async () => {
		const wrapper = mountView()
		await flushPromises()
		listRecords.mockClear()
		// Idle → onWindowFocus reloads.
		wrapper.vm.onWindowFocus()
		await flushPromises()
		expect(listRecords).toHaveBeenCalledTimes(1)
		// onVisible only reloads when the document is visible.
		Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
		wrapper.vm.onVisible()
		await flushPromises()
		expect(listRecords).toHaveBeenCalledTimes(2)

		const remove = vi.spyOn(document, 'removeEventListener')
		wrapper.unmount()
		expect(remove).toHaveBeenCalled()
	})

	it('surfaces a load error', async () => {
		listRecords.mockRejectedValueOnce(new Error('down'))
		const wrapper = mountView()
		await flushPromises()
		expect(wrapper.vm.loading).toBe(false)
	})

	it('seeds the save-view dialog from the active view title', async () => {
		const wrapper = mountView()
		await flushPromises()
		wrapper.vm.views = [{ id: 3, title: 'Quarterly', isOwner: true }]
		wrapper.vm.activeViewId = 3
		wrapper.vm.openSaveView()
		expect(wrapper.vm.showSaveView).toBe(true)
		expect(wrapper.vm.newView.title).toBe('Quarterly')
	})

	it('reports a clean CSV import success', async () => {
		importCsv.mockResolvedValueOnce({ imported: 4, failed: 0, errors: [] })
		const wrapper = mountView()
		await flushPromises()
		await wrapper.vm.onImportFile({ target: { files: [{ text: () => Promise.resolve('a,b') }], value: 'x' } })
		await flushPromises()
		expect(wrapper.vm.importResult.imported).toBe(4)
	})

	it('clears the importing flag on an import error', async () => {
		importCsv.mockRejectedValueOnce({ response: {} })
		const wrapper = mountView()
		await flushPromises()
		await wrapper.vm.onImportFile({ target: { files: [{ text: () => Promise.resolve('bad') }], value: 'x' } })
		await flushPromises()
		expect(wrapper.vm.importing).toBe(false)
	})

	it('survives a delete failure', async () => {
		window.confirm = vi.fn(() => true)
		deleteRecord.mockRejectedValueOnce({ response: {} })
		const wrapper = mountView()
		await flushPromises()
		await wrapper.vm.remove({ id: 7 })
		await flushPromises()
		expect(deleteRecord).toHaveBeenCalled() // error swallowed, no throw
	})
})
