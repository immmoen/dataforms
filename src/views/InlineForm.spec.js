/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import InlineForm from './InlineForm.vue'

vi.mock('../api/fields.js', async (orig) => ({
	...(await orig()),
	listFields: vi.fn(() => Promise.resolve([{ id: 1, machineName: 'title', label: 'Title', type: 'text' }])),
}))
vi.mock('../api/rules.js', async (orig) => ({ ...(await orig()), listRules: vi.fn(() => Promise.resolve([])) }))
vi.mock('../api/forms.js', async (orig) => ({
	...(await orig()),
	listForms: vi.fn(() => Promise.resolve([{ id: 9, title: 'Intake', definition: { sections: [] } }])),
}))

describe('InlineForm', () => {
	beforeEach(() => vi.clearAllMocks())

	it('loads the register schema and the chosen form, then renders RecordForm', async () => {
		const wrapper = mount(InlineForm, { props: { registerId: 5, formId: 9 } })
		await flushPromises()
		expect(wrapper.vm.ready).toBe(true)
		expect(wrapper.vm.fields.length).toBe(1)
		expect(wrapper.vm.form?.id).toBe(9)
	})
})
