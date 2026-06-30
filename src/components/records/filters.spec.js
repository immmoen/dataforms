/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect } from 'vitest'
import { filterableFieldOptions, filterValueOptions, filterValueInputType, defaultOperatorForField } from './filters.js'

const fields = [
	{ machineName: 'title', label: 'Title', type: 'text' },
	{ machineName: 'qty', label: 'Qty', type: 'number' },
	{ machineName: 'due', label: 'Due', type: 'date' },
	{ machineName: 'cat', label: 'Category', type: 'select', config: { options: ['a', 'b'] } },
	{ machineName: 'tags', label: 'Tags', type: 'multiselect', config: { options: ['x'] } },
	{ machineName: 'doc', label: 'Doc', type: 'file' },
	{ machineName: 'parent', label: 'Parent', type: 'relation' },
	{ machineName: 'created', label: 'Created', type: 'auto' },
]

describe('records/filters', () => {
	it('excludes file, relation and auto fields from filterable options', () => {
		const ids = filterableFieldOptions(fields).map((o) => o.id)
		expect(ids).toEqual(['title', 'qty', 'due', 'cat', 'tags'])
	})

	it('returns value options only for select/multiselect', () => {
		expect(filterValueOptions(fields, 'cat')).toEqual(['a', 'b'])
		expect(filterValueOptions(fields, 'tags')).toEqual(['x'])
		expect(filterValueOptions(fields, 'title')).toEqual([])
		expect(filterValueOptions(fields, 'missing')).toEqual([])
	})

	it('maps a field to its filter input type', () => {
		expect(filterValueInputType(fields, 'qty')).toBe('number')
		expect(filterValueInputType(fields, 'due')).toBe('date')
		expect(filterValueInputType(fields, 'title')).toBe('text')
		expect(filterValueInputType(fields, 'missing')).toBe('text')
	})

	it('defaults multiselect to contains and everything else to eq', () => {
		expect(defaultOperatorForField(fields, 'tags')).toBe('contains')
		expect(defaultOperatorForField(fields, 'title')).toBe('eq')
		expect(defaultOperatorForField(fields, 'missing')).toBe('eq')
	})
})
