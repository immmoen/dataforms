/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * RuleBuilder: the manager-facing rule editor. Covers the per-effect payload
 * assembly (the JS counterpart of RuleService::encodeDefinition), the draft
 * seeding for add/edit, the human-readable summaries, and the save/remove
 * flows. The API is mocked; rule semantics live in the shared-fixture suite.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RuleBuilder from './RuleBuilder.vue'
import { createRule, updateRule, deleteRule } from '../api/rules.js'

vi.mock('../api/fields.js', async (orig) => ({
	...(await orig()),
	listFields: vi.fn(() => Promise.resolve([
		{ id: 1, machineName: 'title', label: 'Title', type: 'text' },
		{ id: 2, machineName: 'cat', label: 'Category', type: 'select', config: { options: ['a', 'b'] } },
	])),
}))
vi.mock('../api/rules.js', async (orig) => ({
	...(await orig()),
	listRules: vi.fn(() => Promise.resolve([])),
	createRule: vi.fn((reg, data) => Promise.resolve({ id: 99, ...data })),
	updateRule: vi.fn((id, data) => Promise.resolve({ id, ...data })),
	deleteRule: vi.fn(() => Promise.resolve()),
}))

const mountBuilder = async () => {
	const wrapper = mount(RuleBuilder, { props: { registerId: 5, canManage: true } })
	await flushPromises()
	return wrapper
}

describe('RuleBuilder', () => {
	beforeEach(() => vi.clearAllMocks())

	it('loads fields and seeds the add-rule draft with the first field', async () => {
		const wrapper = await mountBuilder()
		wrapper.vm.openAdd()
		expect(wrapper.vm.showAdd).toBe(true)
		expect(wrapper.vm.draft.target).toBe('title')
		expect(wrapper.vm.draft.effect).toBe('show')
	})

	it('populates the draft when editing an existing rule', async () => {
		const wrapper = await mountBuilder()
		wrapper.vm.openEdit({
			id: 3,
			effect: 'validate',
			target: 'title',
			conditions: { logic: 'or', rules: [{ field: 'cat', op: 'eq', value: 'a' }] },
			validation: { kind: 'range', min: 0, max: 9, message: 'bad' },
		})
		expect(wrapper.vm.editingRule.id).toBe(3)
		expect(wrapper.vm.draft.logic).toBe('or')
		expect(wrapper.vm.draft.conditions).toEqual([{ field: 'cat', op: 'eq', value: 'a' }])
		expect(wrapper.vm.draft.validation.kind).toBe('range')
	})

	it('edit falls back to a blank condition when the rule has none', async () => {
		const wrapper = await mountBuilder()
		wrapper.vm.openEdit({ id: 4, effect: 'compute', target: 'title', expression: 'a + b' })
		expect(wrapper.vm.draft.conditions).toEqual([{ field: 'title', op: 'eq', value: '' }])
		expect(wrapper.vm.draft.expression).toBe('a + b')
	})

	it('addCondition appends a row', async () => {
		const wrapper = await mountBuilder()
		wrapper.vm.openAdd()
		wrapper.vm.addCondition()
		expect(wrapper.vm.draft.conditions).toHaveLength(2)
	})

	it('builds the payload per effect', async () => {
		const wrapper = await mountBuilder()
		const draft = (over) => Object.assign(wrapper.vm.draft, { effect: 'show', target: 't', logic: 'and', conditions: [{ field: 'cat', op: 'eq', value: 'a' }], value: '', expression: '', ...over })

		draft({ effect: 'show' })
		expect(wrapper.vm.buildPayload()).toEqual({ effect: 'show', target: 't', conditions: { logic: 'and', rules: [{ field: 'cat', op: 'eq', value: 'a' }] } })

		draft({ effect: 'set_value', value: 'def' })
		expect(wrapper.vm.buildPayload().value).toBe('def')

		draft({ effect: 'compute', expression: 'a * b' })
		const computed = wrapper.vm.buildPayload()
		expect(computed.expression).toBe('a * b')
		expect(computed.conditions).toBeUndefined() // compute uses no conditions

		draft({ effect: 'validate', validation: { kind: 'regex', pattern: '^x$' } })
		expect(wrapper.vm.buildPayload().validation.pattern).toBe('^x$')
	})

	it('splits an "is one of" value into an array and drops fieldless conditions', async () => {
		const wrapper = await mountBuilder()
		Object.assign(wrapper.vm.draft, {
			effect: 'require',
			target: 't',
			logic: 'and',
			conditions: [{ field: 'cat', op: 'in', value: 'a, b ,c' }, { field: '', op: 'eq', value: 'x' }],
		})
		const payload = wrapper.vm.buildPayload()
		expect(payload.conditions.rules).toEqual([{ field: 'cat', op: 'in', value: ['a', 'b', 'c'] }])
	})

	it('submits a new rule and appends it', async () => {
		const wrapper = await mountBuilder()
		wrapper.vm.openAdd()
		wrapper.vm.draft.target = 'title'
		await wrapper.vm.submit()
		await flushPromises()
		expect(createRule).toHaveBeenCalled()
		expect(wrapper.vm.rules.some((r) => r.id === 99)).toBe(true)
		expect(wrapper.vm.showAdd).toBe(false)
	})

	it('submits an edit and replaces the rule in place', async () => {
		const wrapper = await mountBuilder()
		wrapper.vm.rules = [{ id: 3, effect: 'show', target: 'old' }]
		wrapper.vm.openEdit(wrapper.vm.rules[0])
		wrapper.vm.draft.target = 'new'
		await wrapper.vm.submit()
		await flushPromises()
		expect(updateRule).toHaveBeenCalledWith(3, expect.objectContaining({ target: 'new' }))
		expect(wrapper.vm.rules[0].target).toBe('new')
	})

	it('does nothing on submit without a target', async () => {
		const wrapper = await mountBuilder()
		wrapper.vm.openAdd()
		wrapper.vm.draft.target = ''
		await wrapper.vm.submit()
		expect(createRule).not.toHaveBeenCalled()
	})

	it('surfaces a save error and keeps the dialog open', async () => {
		createRule.mockRejectedValueOnce({ response: { data: { ocs: { data: { message: 'nope' } } } } })
		const wrapper = await mountBuilder()
		wrapper.vm.openAdd()
		wrapper.vm.draft.target = 'title'
		await wrapper.vm.submit()
		await flushPromises()
		expect(wrapper.vm.showAdd).toBe(true)
	})

	it('removes a rule after confirmation', async () => {
		window.confirm = vi.fn(() => true)
		const wrapper = await mountBuilder()
		wrapper.vm.rules = [{ id: 7, effect: 'show', target: 't' }]
		await wrapper.vm.remove(wrapper.vm.rules[0])
		expect(deleteRule).toHaveBeenCalledWith(7)
		expect(wrapper.vm.rules).toHaveLength(0)
	})

	it('skips removal when not confirmed', async () => {
		window.confirm = vi.fn(() => false)
		const wrapper = await mountBuilder()
		await wrapper.vm.remove({ id: 7 })
		expect(deleteRule).not.toHaveBeenCalled()
	})

	it('keeps the rule on a delete failure', async () => {
		window.confirm = vi.fn(() => true)
		deleteRule.mockRejectedValueOnce(new Error('down'))
		const wrapper = await mountBuilder()
		wrapper.vm.rules = [{ id: 7, effect: 'show', target: 't' }]
		await wrapper.vm.remove(wrapper.vm.rules[0])
		await flushPromises()
		expect(wrapper.vm.rules).toHaveLength(1) // not removed
	})

	it('summarises rules and conditions for the list', async () => {
		const wrapper = await mountBuilder()
		expect(wrapper.vm.conditionsText({ conditions: null })).toBe('always')
		expect(wrapper.vm.conditionsText({ conditions: { logic: 'and', rules: [{ field: 'cat', op: 'isEmpty' }] } })).toContain('cat')
		expect(wrapper.vm.ruleSummary({ effect: 'compute', target: 'risk', expression: 'a*b' })).toBe('risk = a*b')
		// The test t() stub returns the raw template (no {var} interpolation), so
		// assert the static, effect-distinguishing part of each summary.
		expect(wrapper.vm.ruleSummary({ effect: 'show', target: 'x', conditions: null })).toContain('Show')
		expect(wrapper.vm.ruleSummary({ effect: 'require', target: 'x', conditions: null })).toContain('Require')
		expect(wrapper.vm.ruleSummary({ effect: 'validate', target: 'x', validation: { kind: 'range' } })).toContain('Validate')
		expect(wrapper.vm.ruleSummary({ effect: 'set_value', target: 'x', value: 'v', conditions: null })).toContain('Set')
		expect(wrapper.vm.ruleSummary({ effect: 'other', target: 'fallback' })).toBe('fallback')
		expect(wrapper.vm.effectLabel('show')).toBe('Show field')
		expect(wrapper.vm.effectLabel('mystery')).toBe('mystery')
		expect(wrapper.vm.optionsForField('cat')).toEqual(['a', 'b'])
	})

	it('clears loading even when loading fails', async () => {
		const { listFields } = await import('../api/fields.js')
		listFields.mockRejectedValueOnce(new Error('boom'))
		const wrapper = mount(RuleBuilder, { props: { registerId: 9, canManage: true } })
		await flushPromises()
		expect(wrapper.vm.loading).toBe(false)
	})
})
