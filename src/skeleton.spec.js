/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Walking-skeleton test (issue #2): proves the Vitest + jsdom + @vue/test-utils
 * pipeline end-to-end — a component mounts and renders into the DOM. Real
 * component coverage is added per capability in later slices.
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

const Hello = {
	props: { name: { type: String, default: 'world' } },
	template: '<p class="hello">hello {{ name }}</p>',
}

describe('walking skeleton (jsdom)', () => {
	it('mounts a component and renders it into the DOM', () => {
		const wrapper = mount(Hello, { props: { name: 'dataforms' } })
		expect(wrapper.find('.hello').text()).toBe('hello dataforms')
	})

	it('exposes the mocked Nextcloud t() global', () => {
		expect(t('dataforms', 'Records')).toBe('Records')
	})
})
