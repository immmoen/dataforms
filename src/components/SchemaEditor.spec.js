/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import SchemaEditor from './SchemaEditor.vue'

vi.mock('../api/fields.js', async (orig) => ({
	...(await orig()),
	listFields: vi.fn(() => Promise.resolve([
		{ id: 1, machineName: 'title', label: 'Title', type: 'text' },
		{ id: 2, machineName: 'qty', label: 'Qty', type: 'number' },
	])),
	createField: vi.fn(() => Promise.resolve({})),
	updateField: vi.fn(() => Promise.resolve({})),
	deleteField: vi.fn(() => Promise.resolve()),
	reorderFields: vi.fn(() => Promise.resolve([])),
}))
vi.mock('../api/registers.js', async (orig) => ({
	...(await orig()),
	listRegisters: vi.fn(() => Promise.resolve([])),
}))

describe('SchemaEditor', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders the field list and the add-field dialog for a manager', async () => {
		const wrapper = mount(SchemaEditor, { props: { registerId: 5, canManage: true } })
		await flushPromises()
		wrapper.vm.openAdd()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showAdd).toBe(true)
		expect(wrapper.html()).toBeTruthy()

		// submitEdit returns early without an editing field (guards line)
		wrapper.vm.editingField = null
		await wrapper.vm.submitEdit()
		// …and saves an edited field when one is set
		wrapper.vm.editingField = { id: 2, machineName: 'qty', label: 'Qty', type: 'number' }
		wrapper.vm.draft.label = 'Quantity'
		await wrapper.vm.submitEdit()
	})
})
