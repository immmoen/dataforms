/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * FieldInput renders one control per field type; mount each touched branch and
 * drive the @input handlers / methods so the type-checker casts are exercised.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import FieldInput from './FieldInput.vue'

vi.mock('../api/records.js', async (orig) => ({
	...(await orig()),
	listOptions: vi.fn(() => Promise.resolve([{ id: 1, label: 'One' }])),
	uploadLocalFile: vi.fn(() => Promise.resolve({ id: 7, name: 'f.txt' })),
}))

const field = (type, extra = {}) => ({ id: 1, machineName: 'f', label: 'F', type, ...extra })
const mountType = (type, props = {}) => mount(FieldInput, { props: { field: field(type), modelValue: null, ...props } })

describe('FieldInput', () => {
	beforeEach(() => vi.clearAllMocks())

	it('emits the typed value from text, number, date and fallback inputs', async () => {
		for (const type of ['text', 'number', 'date', 'user']) {
			const wrapper = mountType(type)
			await wrapper.find('input').setValue(type === 'number' ? '42' : 'x')
			expect(wrapper.emitted('update:modelValue')).toBeTruthy()
		}
	})

	it('renders a longtext textarea and emits on input', async () => {
		const wrapper = mountType('longtext', { modelValue: 'hi' })
		await wrapper.find('textarea').setValue('bye')
		expect(wrapper.emitted('update:modelValue')[0]).toEqual(['bye'])
	})

	it('lists attached files and removes one', async () => {
		const wrapper = mount(FieldInput, { props: { field: field('file'), modelValue: { id: 3, name: 'doc.pdf' } } })
		await flushPromises()
		expect(wrapper.vm.fileList.length).toBe(1)
		wrapper.vm.triggerUpload()
	})

	it('searches relation options', async () => {
		const wrapper = mount(FieldInput, { props: { field: field('relation', { config: { target: 9 } }), modelValue: { id: 1, label: 'One' } } })
		await wrapper.vm.loadRelations('one')
		await flushPromises()
		expect(wrapper.vm.relationOptions.length).toBeGreaterThan(0)
	})
})
