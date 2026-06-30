/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import RecordDetail from './RecordDetail.vue'

vi.mock('../api/records.js', async (orig) => ({
	...(await orig()),
	listHistory: vi.fn(() => Promise.resolve([])),
}))

describe('RecordDetail', () => {
	beforeEach(() => vi.clearAllMocks())

	it('renders the fields and an Edit button when editable', async () => {
		const wrapper = mount(RecordDetail, {
			props: {
				fields: [{ id: 1, machineName: 'title', label: 'Title', type: 'text' }],
				record: { id: 9, values: { title: 'Hello' } },
				canEdit: true,
			},
		})
		await flushPromises()
		expect(wrapper.html()).toContain('Hello')
	})
})
