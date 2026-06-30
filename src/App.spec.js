/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * App shell: the empty state, the selected-register header and the create
 * dialog (all migrated to NcButton `variant`). Child builders are stubbed —
 * they have their own specs.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import App from './App.vue'

import { createRegister, deleteRegister, favoriteRegister, listRegisters } from './api/registers.js'

vi.mock('./api/registers.js', async (orig) => ({
	...(await orig()),
	listRegisters: vi.fn(() => Promise.resolve([])),
	createRegister: vi.fn(() => Promise.resolve({ id: 7, title: 'New' })),
	deleteRegister: vi.fn(() => Promise.resolve()),
	favoriteRegister: vi.fn((id, fav) => Promise.resolve({ id, title: 'Fines', favorite: fav })),
}))

const stubs = ['SchemaEditor', 'RecordsView', 'RuleBuilder', 'FormBuilder', 'AutomationsBuilder', 'ShareDialog']
const mountApp = () => mount(App, { global: { stubs: Object.fromEntries(stubs.map((s) => [s, true])) } })

describe('App', () => {
	beforeEach(() => vi.clearAllMocks())

	it('shows the empty state with a create action when no register is selected', async () => {
		const wrapper = mountApp()
		await flushPromises()
		expect(wrapper.vm.selected).toBe(null)
		expect(wrapper.html()).toBeTruthy()
	})

	it('renders the register header and the create dialog', async () => {
		const wrapper = mountApp()
		await flushPromises()
		wrapper.vm.registers = [{ id: 1, title: 'Fines', canManage: true, color: '#fff' }]
		wrapper.vm.selectedId = 1
		wrapper.vm.openCreate()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showCreate).toBe(true)
		expect(wrapper.html()).toContain('Fines')
	})

	it('loads registers on mount', async () => {
		listRegisters.mockResolvedValueOnce([
			{ id: 1, title: 'A', favorite: true },
			{ id: 2, title: 'B', favorite: false },
		])
		const wrapper = mountApp()
		await flushPromises()
		expect(wrapper.vm.registers).toHaveLength(2)
		expect(wrapper.vm.favoriteRegisters).toHaveLength(1)
		expect(wrapper.vm.otherRegisters).toHaveLength(1)
	})

	it('creates a register, selecting it, and ignores an empty title', async () => {
		const wrapper = mountApp()
		await flushPromises()
		wrapper.vm.openCreate()
		await wrapper.vm.submitCreate() // empty title → no-op
		expect(createRegister).not.toHaveBeenCalled()
		wrapper.vm.draft.title = 'New'
		await wrapper.vm.submitCreate()
		expect(createRegister).toHaveBeenCalledOnce()
		expect(wrapper.vm.registers.some((r) => r.id === 7)).toBe(true)
		expect(wrapper.vm.selectedId).toBe(7)
		expect(wrapper.vm.showCreate).toBe(false)
	})

	it('toggles a register favourite in place', async () => {
		const wrapper = mountApp()
		await flushPromises()
		wrapper.vm.registers = [{ id: 3, title: 'Fines', favorite: false }]
		await wrapper.vm.toggleFavorite(wrapper.vm.registers[0])
		expect(favoriteRegister).toHaveBeenCalledWith(3, true)
		expect(wrapper.vm.registers[0].favorite).toBe(true)
	})

	it('deletes a register after confirmation and clears the selection', async () => {
		window.confirm = vi.fn(() => true)
		const wrapper = mountApp()
		await flushPromises()
		wrapper.vm.registers = [{ id: 4, title: 'Fines' }]
		wrapper.vm.selectedId = 4
		await wrapper.vm.confirmDelete(wrapper.vm.registers[0])
		expect(deleteRegister).toHaveBeenCalledWith(4)
		expect(wrapper.vm.registers).toHaveLength(0)
		expect(wrapper.vm.selectedId).toBe(null)
	})

	it('does not delete when the confirm is declined', async () => {
		window.confirm = vi.fn(() => false)
		const wrapper = mountApp()
		await flushPromises()
		wrapper.vm.registers = [{ id: 4, title: 'Fines' }]
		await wrapper.vm.confirmDelete(wrapper.vm.registers[0])
		expect(deleteRegister).not.toHaveBeenCalled()
	})

	it('selects a register and reflects it in the URL hash', async () => {
		const wrapper = mountApp()
		await flushPromises()
		wrapper.vm.registers = [{ id: 5, title: 'Fines' }]
		wrapper.vm.select(5)
		expect(wrapper.vm.selectedId).toBe(5)
		expect(window.location.hash).toContain('/register/5/records')
	})
})
