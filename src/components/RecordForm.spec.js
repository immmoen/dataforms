/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * RecordForm characterization (issue #7): the data-entry form with LIVE
 * conditional logic. Asserts the observable behaviour at the component seam —
 * what renders, how the shared rule engine reshapes the form as values change,
 * and the exact payload sent to the records API on save — before the records
 * core is refactored (#8). The API module is mocked; everything else is real.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RecordForm from './RecordForm.vue'
import { createRecord, updateRecord } from '../api/records.js'
import { showError } from '@nextcloud/dialogs'

vi.mock('../api/records.js', async (orig) => ({
	...(await orig()),
	createRecord: vi.fn(() => Promise.resolve({ id: 1 })),
	updateRecord: vi.fn(() => Promise.resolve({ id: 1 })),
}))

const field = (machineName, type = 'text', extra = {}) => ({
	id: extra.id ?? Math.floor(Math.random() * 1e6),
	machineName,
	label: machineName.charAt(0).toUpperCase() + machineName.slice(1),
	type,
	mandatory: false,
	config: {},
	...extra,
})

const mountForm = (props) => mount(RecordForm, {
	props: { registerId: 5, fields: [], rules: [], ...props },
})

describe('RecordForm', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders its fields and the save button', async () => {
		const wrapper = mountForm({
			fields: [field('title', 'text', { mandatory: true }), field('note', 'longtext')],
		})
		await flushPromises()
		expect(wrapper.text()).toContain('Title')
		expect(wrapper.text()).toContain('Save')
	})

	it('renders no data-entry fields when the register has none', () => {
		// The empty-state copy lives in the NcEmptyContent `name` prop, which the
		// slot-only test stub drops; the observable effect is that the data-entry
		// section is suppressed entirely (no .form-field rows).
		const wrapper = mountForm({ fields: [] })
		expect(wrapper.find('.form-field').exists()).toBe(false)
		expect(wrapper.find('.record-form').exists()).toBe(true)
	})

	it('hides a show-ruled field until its condition is met, live', async () => {
		const wrapper = mountForm({
			fields: [field('category', 'select'), field('other_detail', 'text')],
			rules: [{
				effect: 'show',
				target: 'other_detail',
				conditions: { logic: 'and', rules: [{ field: 'category', op: 'eq', value: 'other' }] },
			}],
		})
		await flushPromises()
		// Hidden initially (condition not met).
		expect(wrapper.text()).not.toContain('Other_detail')

		// Typing the triggering value reveals it without any save/round-trip.
		wrapper.vm.onInput(wrapper.props().fields[0], 'other')
		await flushPromises()
		expect(wrapper.text()).toContain('Other_detail')
	})

	it('marks a field required live via a require rule', async () => {
		const wrapper = mountForm({
			fields: [field('paid', 'boolean'), field('reference', 'text')],
			rules: [{
				effect: 'require',
				target: 'reference',
				conditions: { logic: 'and', rules: [{ field: 'paid', op: 'eq', value: true }] },
			}],
		})
		await flushPromises()
		expect(wrapper.vm.evaluation.required.reference).toBe(false)

		wrapper.vm.onInput(wrapper.props().fields[0], true)
		await flushPromises()
		expect(wrapper.vm.evaluation.required.reference).toBe(true)
		expect(wrapper.find('.req').exists()).toBe(true) // the asterisk renders
	})

	it('computes a field live, tags it, and recomputes on input', async () => {
		const wrapper = mountForm({
			fields: [field('qty', 'number'), field('price', 'number'), field('total', 'computed')],
			rules: [{ effect: 'compute', target: 'total', expression: 'qty * price' }],
		})
		await flushPromises()
		wrapper.vm.onInput(wrapper.props().fields[0], 2)
		wrapper.vm.onInput(wrapper.props().fields[1], 5)
		await flushPromises()

		expect(wrapper.vm.computedTargets.has('total')).toBe(true)
		expect(wrapper.text()).toContain('computed') // the computed tag
		expect(wrapper.vm.valueFor({ machineName: 'total' })).toBe(10)
	})

	it('saves the computed payload and nulls hidden fields', async () => {
		const wrapper = mountForm({
			fields: [field('qty', 'number'), field('total', 'computed'), field('secret', 'text')],
			rules: [
				{ effect: 'compute', target: 'total', expression: 'qty * 3' },
				{ effect: 'show', target: 'secret', conditions: { logic: 'and', rules: [{ field: 'qty', op: 'eq', value: 999 }] } },
			],
		})
		await flushPromises()
		wrapper.vm.onInput(wrapper.props().fields[0], 4)
		await flushPromises()

		await wrapper.vm.save()
		await flushPromises()

		expect(createRecord).toHaveBeenCalledTimes(1)
		const [registerId, payload] = createRecord.mock.calls[0]
		expect(registerId).toBe(5)
		expect(payload.total).toBe(12) // computed server-bound value
		expect(payload.secret).toBeNull() // hidden → never persisted
		expect(wrapper.emitted('saved')).toBeTruthy()
	})

	it('blocks save and surfaces errors when a required field is empty', async () => {
		const wrapper = mountForm({
			fields: [field('title', 'text', { mandatory: true })],
		})
		await flushPromises()

		await wrapper.vm.save()
		await flushPromises()

		expect(createRecord).not.toHaveBeenCalled()
		expect(showError).toHaveBeenCalled()
		// Once attempted, the per-field error renders.
		expect(wrapper.find('.err').text()).toContain('required')
	})

	it('edits an existing record via updateRecord, seeded from its values', async () => {
		const wrapper = mountForm({
			fields: [field('title', 'text')],
			record: { id: 77, values: { title: 'Existing' } },
		})
		await flushPromises()
		expect(wrapper.vm.values.title).toBe('Existing')

		await wrapper.vm.save()
		await flushPromises()

		expect(updateRecord).toHaveBeenCalledWith(77, expect.objectContaining({ title: 'Existing' }))
		expect(createRecord).not.toHaveBeenCalled()
	})

	it('surfaces server-side field errors returned by the API', async () => {
		createRecord.mockRejectedValueOnce({ response: { data: { ocs: { data: { errors: { title: 'Already taken' } } } } } })
		const wrapper = mountForm({
			fields: [field('title', 'text')],
		})
		await flushPromises()
		wrapper.vm.onInput(wrapper.props().fields[0], 'dup')
		await flushPromises()

		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.vm.serverErrors.title).toBe('Already taken')
		expect(wrapper.find('.err').text()).toContain('Already taken')
		// Editing the field clears its server error.
		wrapper.vm.onInput(wrapper.props().fields[0], 'fresh')
		await flushPromises()
		expect(wrapper.vm.serverErrors.title).toBeUndefined()
	})
})
