/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import FormBuilder from './FormBuilder.vue'

vi.mock('../api/forms.js', async (orig) => ({
	...(await orig()),
	listForms: vi.fn(() => Promise.resolve([{ id: 1, title: 'Intake', definition: { sections: [] } }])),
	createForm: vi.fn(() => Promise.resolve({})),
	updateForm: vi.fn(() => Promise.resolve({})),
	deleteForm: vi.fn(() => Promise.resolve()),
}))
vi.mock('../api/fields.js', async (orig) => ({
	...(await orig()),
	listFields: vi.fn(() => Promise.resolve([{ id: 1, machineName: 'title', label: 'Title', type: 'text' }])),
}))

describe('FormBuilder', () => {
	beforeEach(() => vi.clearAllMocks())

	it('lists forms, then opens the section builder', async () => {
		const wrapper = mount(FormBuilder, { props: { registerId: 5, canManage: true } })
		await flushPromises()
		expect(wrapper.vm.forms.length).toBe(1)
		wrapper.vm.openAdd()
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.draft.sections.length).toBe(1)
		expect(wrapper.html()).toBeTruthy()
	})
})
