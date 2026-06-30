/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect } from 'vitest'
import { isInlineEditable, inlineInputType, seedInlineValue, coerceInlineValue, formatCell } from './cells.js'

const t = (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (_, k) => vars[k]) : text)

describe('records/cells', () => {
	it('marks simple single-value types inline-editable, complex ones not', () => {
		for (const type of ['text', 'number', 'date', 'select', 'boolean', 'email', 'time']) {
			expect(isInlineEditable({ type })).toBe(true)
		}
		for (const type of ['relation', 'file', 'multiselect', 'computed', 'auto', 'longtext']) {
			expect(isInlineEditable({ type })).toBe(false)
		}
	})

	it('maps a field to its inline input type', () => {
		expect(inlineInputType({ type: 'email' })).toBe('email')
		expect(inlineInputType({ type: 'phone' })).toBe('tel')
		expect(inlineInputType({ type: 'currency' })).toBe('number')
		expect(inlineInputType({ type: 'datetime' })).toBe('datetime-local')
		expect(inlineInputType({ type: 'text' })).toBe('text')
	})

	it('seeds the editor value from the raw value', () => {
		expect(seedInlineValue({ type: 'boolean' }, true)).toBe('true')
		expect(seedInlineValue({ type: 'boolean' }, false)).toBe('false')
		expect(seedInlineValue({ type: 'boolean' }, null)).toBe('')
		expect(seedInlineValue({ type: 'text' }, null)).toBe('')
		expect(seedInlineValue({ type: 'text' }, 'hi')).toBe('hi')
	})

	it('coerces the editor value back to the logical type', () => {
		expect(coerceInlineValue({ type: 'boolean' }, 'true')).toBe(true)
		expect(coerceInlineValue({ type: 'boolean' }, 'false')).toBe(false)
		expect(coerceInlineValue({ type: 'boolean' }, '')).toBeNull()
		expect(coerceInlineValue({ type: 'number' }, '42')).toBe(42)
		expect(coerceInlineValue({ type: 'number' }, '')).toBeNull()
		expect(coerceInlineValue({ type: 'text' }, 'x')).toBe('x')
		expect(coerceInlineValue({ type: 'text' }, '')).toBeNull()
	})

	it('formats cell values by type', () => {
		expect(formatCell({ type: 'text' }, null, t)).toBe('')
		expect(formatCell({ type: 'boolean' }, true, t)).toBe('Yes')
		expect(formatCell({ type: 'boolean' }, false, t)).toBe('No')
		expect(formatCell({ type: 'file' }, [{ id: 1, name: 'a.pdf' }], t)).toBe('📎 a.pdf')
		expect(formatCell({ type: 'file' }, [{ id: 1, name: 'a' }, { id: 2, name: 'b' }], t)).toBe('📎 2 files')
		expect(formatCell({ type: 'file' }, [], t)).toBe('')
		expect(formatCell({ type: 'relation' }, [{ id: 1, label: 'Acme' }, { id: 2, label: 'Beta' }], t)).toBe('Acme, Beta')
		expect(formatCell({ type: 'relation' }, { id: 1, label: 'Solo' }, t)).toBe('Solo')
		expect(formatCell({ type: 'number', config: { decimals: 2 } }, 3, t)).toBe('3.00')
		expect(formatCell({ type: 'currency' }, 5, t)).toBe('5.00')
		expect(formatCell({ type: 'multiselect' }, ['x', 'y'], t)).toBe('x, y')
		expect(formatCell({ type: 'text' }, 'plain', t)).toBe('plain')
	})
})
