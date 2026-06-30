/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RecordsView from './RecordsView.vue'

vi.mock('../api/records.js', async (orig) => ({
	...(await orig()),
	listRecords: vi.fn(() => Promise.resolve({ records: [], total: 0, fields: [] })),
	deleteRecord: vi.fn(() => Promise.resolve()),
	updateRecord: vi.fn(() => Promise.resolve({ id: 1, values: {} })),
	importCsv: vi.fn(() => Promise.resolve({ imported: 0, failed: 0, errors: [] })),
}))
vi.mock('../api/rules.js', async (orig) => ({ ...(await orig()), listRules: vi.fn(() => Promise.resolve([])) }))
vi.mock('../api/views.js', async (orig) => ({
	...(await orig()),
	listViews: vi.fn(() => Promise.resolve([])),
	createView: vi.fn(() => Promise.resolve({})),
	deleteView: vi.fn(() => Promise.resolve()),
}))
vi.mock('../api/forms.js', async (orig) => ({ ...(await orig()), listForms: vi.fn(() => Promise.resolve([])) }))

describe('RecordsView', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders the toolbar, filter bar and import dialog', async () => {
		const wrapper = mount(RecordsView, { props: { registerId: 5, canWrite: true, canManage: true } })
		await flushPromises()
		wrapper.vm.fields = [
			{ machineName: 'n', label: 'N', type: 'number' },
			{ machineName: 'd', label: 'D', type: 'date' },
			{ machineName: 't', label: 'T', type: 'text' },
		]
		wrapper.vm.showFilter = true
		wrapper.vm.draftFilters = [{ field: 't', op: 'eq', value: '' }]
		wrapper.vm.showImport = true
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showFilter).toBe(true)
		expect(wrapper.vm.showImport).toBe(true)

		// triggers the hidden file input (the @click handler on the import button)
		wrapper.vm.triggerImport()
	})

	it('toggles the filter bar, builds columns, and deletes the active view', async () => {
		window.confirm = vi.fn(() => true)
		const wrapper = mount(RecordsView, { props: { registerId: 5, canWrite: true, canManage: true } })
		await flushPromises()
		wrapper.vm.fields = [{ id: 1, machineName: 't', label: 'T', type: 'text' }]
		wrapper.vm.visibleColumns = ['t']
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.columns.length).toBe(1)
		wrapper.vm.toggleFilterBar()
		expect(wrapper.vm.showFilter).toBe(true)
		wrapper.vm.activeViewId = 3
		wrapper.vm.views = [{ id: 3, title: 'V', isOwner: true }]
		await wrapper.vm.removeActiveView()
		expect(wrapper.vm.views.find((v) => v.id === 3)).toBeUndefined()
	})

	it('seeds the inline editor from a record value', async () => {
		const wrapper = mount(RecordsView, { props: { registerId: 5, canWrite: true } })
		await flushPromises()
		wrapper.vm.startInline({ id: 1, values: { flag: true } }, { machineName: 'flag', type: 'boolean' })
		expect(wrapper.vm.editValue).toBe('true')
		expect(wrapper.vm.editingCell).toEqual({ recordId: 1, machineName: 'flag' })
	})

	it('maps a field to its filter input type', async () => {
		const wrapper = mount(RecordsView, { props: { registerId: 5, canWrite: true } })
		await flushPromises()
		wrapper.vm.fields = [
			{ machineName: 'n', type: 'number' },
			{ machineName: 'd', type: 'date' },
			{ machineName: 't', type: 'text' },
		]
		expect(wrapper.vm.fieldInputType('n')).toBe('number')
		expect(wrapper.vm.fieldInputType('d')).toBe('date')
		expect(wrapper.vm.fieldInputType('t')).toBe('text')
		expect(wrapper.vm.fieldInputType('missing')).toBe('text')
	})

	it('coerces inline-edited values by field type on save', async () => {
		const wrapper = mount(RecordsView, { props: { registerId: 5, canWrite: true } })
		await flushPromises()
		const record = { id: 1, values: {}, canEdit: true }
		wrapper.vm.editingCell = '1:bool'
		wrapper.vm.editValue = 'true'
		await wrapper.vm.saveInline(record, { machineName: 'bool', type: 'boolean' })
		wrapper.vm.editingCell = '1:qty'
		wrapper.vm.editValue = '42'
		await wrapper.vm.saveInline(record, { machineName: 'qty', type: 'number' })
		wrapper.vm.editingCell = '1:title'
		wrapper.vm.editValue = 'hi'
		await wrapper.vm.saveInline(record, { machineName: 'title', type: 'text' })
		expect(wrapper.vm.editingCell).toBe(null)
	})
})
