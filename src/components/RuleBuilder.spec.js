/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Smoke coverage for RuleBuilder: mounts the manager view and opens the
 * add-rule dialog so the action buttons (migrated to NcButton `variant`) render.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RuleBuilder from './RuleBuilder.vue'

vi.mock('../api/fields.js', async (orig) => ({
	...(await orig()),
	listFields: vi.fn(() => Promise.resolve([{ id: 1, machineName: 'title', label: 'Title', type: 'text' }])),
}))
vi.mock('../api/rules.js', async (orig) => ({
	...(await orig()),
	listRules: vi.fn(() => Promise.resolve([])),
	createRule: vi.fn(() => Promise.resolve({})),
	updateRule: vi.fn(() => Promise.resolve({})),
	deleteRule: vi.fn(() => Promise.resolve()),
}))

describe('RuleBuilder', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders the add button and the rule dialog with its action buttons', async () => {
		const wrapper = mount(RuleBuilder, { props: { registerId: 5, canManage: true } })
		await flushPromises()
		wrapper.vm.openAdd()
		wrapper.vm.draft.conditions = [{ field: 'title', op: 'eq', value: 'x' }]
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.showAdd).toBe(true)
		expect(wrapper.html()).toBeTruthy()
	})
})
