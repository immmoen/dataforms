/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GroupedMultiSelect from './GroupedMultiSelect.vue'

describe('GroupedMultiSelect', () => {
	it('mounts and renders selected chips from the modelValue', () => {
		const wrapper = mount(GroupedMultiSelect, {
			props: { modelValue: ['Art 6', 'Art 9'], options: ['Art 6', 'Art 9', 'Art 83'], groupPattern: '^Art \\d+' },
		})
		expect(wrapper.text()).toContain('Art 6')
	})
})
