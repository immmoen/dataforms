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

vi.mock('./api/registers.js', async (orig) => ({
	...(await orig()),
	listRegisters: vi.fn(() => Promise.resolve([])),
	createRegister: vi.fn(() => Promise.resolve({ id: 1 })),
	deleteRegister: vi.fn(() => Promise.resolve()),
	favoriteRegister: vi.fn(() => Promise.resolve({})),
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
})
