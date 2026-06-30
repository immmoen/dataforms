/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect } from 'vitest'
import { columnsFor, isColumnVisible, toggleColumnList, viewDefinition, stateFromView } from './viewState.js'

const mk = (n) => Array.from({ length: n }, (_, i) => ({ id: i + 1, machineName: 'f' + (i + 1), label: 'F' + (i + 1), type: 'text' }))

describe('records/viewState', () => {
	it('uses the explicit column list when set, else the first six fields', () => {
		const fields = mk(8)
		expect(columnsFor(fields, []).map((f) => f.machineName)).toEqual(['f1', 'f2', 'f3', 'f4', 'f5', 'f6'])
		expect(columnsFor(fields, ['f8', 'f2']).map((f) => f.machineName)).toEqual(['f8', 'f2'])
		// Unknown machine names are dropped.
		expect(columnsFor(fields, ['nope', 'f1']).map((f) => f.machineName)).toEqual(['f1'])
	})

	it('reports column visibility against the explicit or default set', () => {
		const fields = mk(8)
		expect(isColumnVisible(fields, ['f8'], fields[7])).toBe(true)
		expect(isColumnVisible(fields, ['f8'], fields[0])).toBe(false)
		// Default set = first six.
		expect(isColumnVisible(fields, [], fields[0])).toBe(true)
		expect(isColumnVisible(fields, [], fields[7])).toBe(false)
	})

	it('toggles a column, materialising the default set first', () => {
		const fields = mk(8)
		// From the implicit default, removing f1 yields the rest of the first six.
		expect(toggleColumnList(fields, [], fields[0])).toEqual(['f2', 'f3', 'f4', 'f5', 'f6'])
		// Adding f8 to an explicit list appends it.
		expect(toggleColumnList(fields, ['f1'], fields[7])).toEqual(['f1', 'f8'])
		// Toggling an already-present column removes it.
		expect(toggleColumnList(fields, ['f1', 'f2'], fields[0])).toEqual(['f2'])
	})

	it('builds a view definition verbatim', () => {
		const def = viewDefinition({ columns: ['a'], filters: [{ field: 'a' }], sort: 'created', direction: 'ASC', search: 'x' })
		expect(def).toEqual({ columns: ['a'], filters: [{ field: 'a' }], sort: 'created', direction: 'ASC', search: 'x' })
	})

	it('derives list state from a view, falling back to defaults', () => {
		expect(stateFromView({ definition: { columns: ['a'], filters: [{ field: 'a' }], search: 's', sort: 'created', direction: 'ASC' } }))
			.toEqual({ visibleColumns: ['a'], activeFilters: [{ field: 'a' }], search: 's', sort: 'created', direction: 'ASC' })
		// Missing/empty definition → defaults.
		expect(stateFromView({ definition: {} }))
			.toEqual({ visibleColumns: [], activeFilters: [], search: '', sort: 'updated', direction: 'DESC' })
		expect(stateFromView(null).sort).toBe('updated')
	})
})
