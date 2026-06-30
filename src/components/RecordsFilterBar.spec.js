/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import RecordsFilterBar from './RecordsFilterBar.vue'

const fields = [
	{ machineName: 'title', label: 'Title', type: 'text' },
	{ machineName: 'qty', label: 'Qty', type: 'number' },
	{ machineName: 'tags', label: 'Tags', type: 'multiselect', config: { options: ['x'] } },
]

const mountBar = (props) => mount(RecordsFilterBar, { props: { fields, ...props } })

describe('RecordsFilterBar', () => {
	it('seeds one empty row when there are no initial filters', () => {
		const wrapper = mountBar({ initialFilters: [] })
		expect(wrapper.vm.draftFilters).toEqual([{ field: 'title', op: 'eq', value: '' }])
	})

	it('seeds from the initial (active) filters', () => {
		const wrapper = mountBar({ initialFilters: [{ field: 'qty', op: 'gte', value: 5 }] })
		expect(wrapper.vm.draftFilters).toEqual([{ field: 'qty', op: 'gte', value: 5 }])
	})

	it('adds and removes conditions', () => {
		const wrapper = mountBar({ initialFilters: [] })
		wrapper.vm.addCondition()
		expect(wrapper.vm.draftFilters).toHaveLength(2)
		wrapper.vm.draftFilters.splice(0, 1)
		expect(wrapper.vm.draftFilters).toHaveLength(1)
	})

	it('resets value and picks the operator when the field changes', () => {
		const wrapper = mountBar({ initialFilters: [{ field: 'title', op: 'eq', value: 'x' }] })
		wrapper.vm.onFieldChange(wrapper.vm.draftFilters[0], 'tags')
		expect(wrapper.vm.draftFilters[0]).toEqual({ field: 'tags', op: 'contains', value: '' })
	})

	it('emits normalised criteria on apply, dropping fieldless rows', () => {
		const wrapper = mountBar({ initialFilters: [{ field: 'title', op: 'eq', value: 'hi' }] })
		wrapper.vm.draftFilters.push({ field: '', op: 'eq', value: 'ignored' })
		wrapper.vm.apply()
		expect(wrapper.emitted('apply')[0][0]).toEqual([{ field: 'title', op: 'eq', value: 'hi' }])
	})

	it('clears the rows and emits clear', () => {
		const wrapper = mountBar({ initialFilters: [{ field: 'title', op: 'eq', value: 'hi' }] })
		wrapper.vm.clear()
		expect(wrapper.vm.draftFilters).toEqual([])
		expect(wrapper.emitted('clear')).toBeTruthy()
	})
})
