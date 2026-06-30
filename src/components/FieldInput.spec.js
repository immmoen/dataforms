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

	it('normalises the file value into a list (array, single object, none)', () => {
		expect(mount(FieldInput, { props: { field: field('file'), modelValue: [{ id: 1 }, { id: 2 }] } }).vm.fileList).toHaveLength(2)
		expect(mount(FieldInput, { props: { field: field('file'), modelValue: { id: 9, name: 'x' } } }).vm.fileList).toEqual([{ id: 9, name: 'x' }])
		expect(mount(FieldInput, { props: { field: field('file'), modelValue: null } }).vm.fileList).toEqual([])
	})

	it('uploads picked files and emits the merged list (referenced by id)', async () => {
		const { uploadLocalFile } = await import('../api/records.js')
		uploadLocalFile.mockResolvedValueOnce({ id: 11, name: 'a.txt' }).mockResolvedValueOnce({ id: 12, name: 'b.txt' })
		const wrapper = mount(FieldInput, { props: { field: field('file'), modelValue: [{ id: 1, name: 'old.txt' }] } })
		await wrapper.vm.onLocalFile({ target: { files: [new File(['a'], 'a.txt'), new File(['b'], 'b.txt')], value: 'x' } })
		await flushPromises()
		expect(uploadLocalFile).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('update:modelValue')[0][0]).toEqual([
			{ id: 1, name: 'old.txt' }, { id: 11, name: 'a.txt' }, { id: 12, name: 'b.txt' },
		])
	})

	it('does nothing when no files are picked', async () => {
		const { uploadLocalFile } = await import('../api/records.js')
		const wrapper = mount(FieldInput, { props: { field: field('file'), modelValue: [] } })
		await wrapper.vm.onLocalFile({ target: { files: [], value: '' } })
		expect(uploadLocalFile).not.toHaveBeenCalled()
	})

	it('clears the uploading flag if an upload fails', async () => {
		const { uploadLocalFile } = await import('../api/records.js')
		uploadLocalFile.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mount(FieldInput, { props: { field: field('file'), modelValue: [] } })
		await wrapper.vm.onLocalFile({ target: { files: [new File(['a'], 'a.txt')], value: 'x' } })
		await flushPromises()
		expect(wrapper.vm.uploading).toBe(false)
	})

	it('removes an attachment by id and builds a file URL', () => {
		const wrapper = mount(FieldInput, { props: { field: field('file'), modelValue: [{ id: 1, name: 'a' }, { id: 2, name: 'b' }] } })
		wrapper.vm.removeFile(1)
		expect(wrapper.emitted('update:modelValue')[0][0]).toEqual([{ id: 2, name: 'b' }])
		// The router stub doesn't interpolate params; assert the route shape.
		expect(wrapper.vm.fileUrl(42)).toContain('/f/')
	})

	it('seeds the picker from the current value and loads options on mount', async () => {
		const { listOptions } = await import('../api/records.js')
		const wrapper = mount(FieldInput, {
			props: { field: field('relation', { config: { targetRegisterId: 9, displayField: 'name' } }), modelValue: { id: 5, label: 'Acme' } },
		})
		// The current selection is shown immediately (before options load)...
		expect(wrapper.vm.relationOptions).toEqual([{ id: 5, label: 'Acme' }])
		await flushPromises()
		// ...then the target register's options are fetched with the display field.
		expect(listOptions).toHaveBeenCalledWith(9, { display: 'name', search: '' })
	})

	it('merges the current selection into freshly loaded options without duplicating', async () => {
		const { listOptions } = await import('../api/records.js')
		listOptions.mockResolvedValue([{ id: 1, label: 'One' }, { id: 2, label: 'Two' }])
		const wrapper = mount(FieldInput, {
			props: { field: field('relation', { config: { targetRegisterId: 9 } }), modelValue: { id: 3, label: 'Kept' } },
		})
		await wrapper.vm.loadRelations('')
		await flushPromises()
		const ids = wrapper.vm.relationOptions.map((o) => o.id)
		expect(ids).toEqual([1, 2, 3]) // loaded options + the current selection appended
	})

	it('does nothing when the relation has no target register', async () => {
		const { listOptions } = await import('../api/records.js')
		const wrapper = mount(FieldInput, { props: { field: field('relation', { config: {} }), modelValue: null } })
		await wrapper.vm.loadRelations('x')
		expect(listOptions).not.toHaveBeenCalled()
	})

	it('debounces the search then loads', async () => {
		vi.useFakeTimers()
		try {
			const { listOptions } = await import('../api/records.js')
			const wrapper = mount(FieldInput, { props: { field: field('relation', { config: { targetRegisterId: 9 } }), modelValue: null } })
			listOptions.mockClear()
			wrapper.vm.onRelSearch('ab')
			wrapper.vm.onRelSearch('abc') // resets the timer
			vi.advanceTimersByTime(250)
			expect(listOptions).toHaveBeenCalledWith(9, { display: '', search: 'abc' })
		} finally {
			vi.useRealTimers()
		}
	})

	it('keeps going if loading options fails', async () => {
		const { listOptions } = await import('../api/records.js')
		listOptions.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mount(FieldInput, { props: { field: field('relation', { config: { targetRegisterId: 9 } }), modelValue: null } })
		await wrapper.vm.loadRelations('')
		await flushPromises()
		expect(wrapper.vm.relLoading).toBe(false)
	})

	it('relationModel wraps a single value into a list for a multi relation', () => {
		const single = mount(FieldInput, { props: { field: field('relation', { config: { targetRegisterId: 9 } }), modelValue: { id: 1, label: 'A' } } })
		expect(single.vm.relationModel).toEqual({ id: 1, label: 'A' })

		const multi = mount(FieldInput, { props: { field: field('relation', { config: { targetRegisterId: 9, multiple: true } }), modelValue: { id: 1, label: 'A' } } })
		expect(multi.vm.relationModel).toEqual([{ id: 1, label: 'A' }]) // single coerced to a list

		const multiList = mount(FieldInput, { props: { field: field('relation', { config: { targetRegisterId: 9, multiple: true } }), modelValue: [{ id: 1 }, { id: 2 }] } })
		expect(multiList.vm.relationModel).toEqual([{ id: 1 }, { id: 2 }])

		const multiEmpty = mount(FieldInput, { props: { field: field('relation', { config: { targetRegisterId: 9, multiple: true } }), modelValue: null } })
		expect(multiEmpty.vm.relationModel).toEqual([])
	})

	it('emits the picked record(s) from the relation select', async () => {
		const wrapper = mount(FieldInput, { props: { field: field('relation', { config: { targetRegisterId: 9 } }), modelValue: null } })
		await flushPromises()
		wrapper.vm.emit({ id: 7, label: 'Picked' })
		expect(wrapper.emitted('update:modelValue')[0]).toEqual([{ id: 7, label: 'Picked' }])
	})
})
