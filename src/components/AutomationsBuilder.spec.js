/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import AutomationsBuilder from './AutomationsBuilder.vue'

vi.mock('../api/automations.js', async (orig) => ({
	...(await orig()),
	listAutomations: vi.fn(() => Promise.resolve([])),
	getAvailableActions: vi.fn(() => Promise.resolve({ actions: ['notify', 'provision_folders'], serviceAccounts: [] })),
	getAutomationLog: vi.fn(() => Promise.resolve([])),
	createAutomation: vi.fn(() => Promise.resolve({})),
	updateAutomation: vi.fn(() => Promise.resolve({})),
	deleteAutomation: vi.fn(() => Promise.resolve()),
}))
vi.mock('../api/fields.js', async (orig) => ({
	...(await orig()),
	listFields: vi.fn(() => Promise.resolve([{ id: 1, machineName: 'title', label: 'Title', type: 'text' }])),
}))
vi.mock('../api/shares.js', async (orig) => ({
	...(await orig()),
	searchSharees: vi.fn(() => Promise.resolve([])),
}))

describe('AutomationsBuilder', () => {
	beforeEach(() => vi.clearAllMocks())
	afterEach(() => vi.useRealTimers())

	it('opens the editor dialog with a condition row', async () => {
		const wrapper = mount(AutomationsBuilder, { props: { registerId: 5, canManage: true } })
		await flushPromises()
		wrapper.vm.openAdd()
		wrapper.vm.draft.conditions = [{ field: 'title', op: 'eq', value: 'x' }]
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showDialog).toBe(true)
	})

	it('lifts a newly-opened folder picker above the builder dialog', async () => {
		vi.useFakeTimers()
		const modal = document.createElement('div')
		window.OC = {
			dialogs: {
				FILEPICKER_TYPE_CHOOSE: 1,
				filepicker: vi.fn(() => {
					modal.className = 'dialog__modal'
					document.body.appendChild(modal)
				}),
			},
		}
		const wrapper = mount(AutomationsBuilder, { props: { registerId: 5, canManage: true } })
		wrapper.vm.pickFolder('basePath')
		vi.runOnlyPendingTimers()
		expect(modal.style.zIndex).toBe('11000')
	})
})
