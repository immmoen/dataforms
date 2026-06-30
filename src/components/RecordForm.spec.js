/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RecordForm from './RecordForm.vue'

vi.mock('../api/records.js', async (orig) => ({
	...(await orig()),
	createRecord: vi.fn(() => Promise.resolve({ id: 1 })),
	updateRecord: vi.fn(() => Promise.resolve({ id: 1 })),
}))

describe('RecordForm', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders its fields and the save button', async () => {
		const wrapper = mount(RecordForm, {
			props: {
				registerId: 5,
				fields: [
					{ id: 1, machineName: 'title', label: 'Title', type: 'text', mandatory: true },
					{ id: 2, machineName: 'note', label: 'Note', type: 'longtext' },
				],
				rules: [],
			},
		})
		await flushPromises()
		expect(wrapper.html()).toContain('Title')
	})
})
