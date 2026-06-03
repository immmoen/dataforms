/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Drives the JS rule engine with the shared fixtures
 * (tests/fixtures/rule-cases.json), the same cases the PHP RuleEvaluator suite
 * runs, so JS and PHP stay in lockstep.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { describe, it, expect } from 'vitest'
import { evaluateRules, matches } from './engine.js'

const here = dirname(fileURLToPath(import.meta.url))
const fixtures = JSON.parse(readFileSync(join(here, '../../tests/fixtures/rule-cases.json'), 'utf8'))

describe('rule engine (shared fixtures)', () => {
	for (const c of fixtures.rules) {
		it(c.name, () => {
			const result = evaluateRules(c.fields, c.rules, c.values)
			if (c.expect.values) {
				for (const [k, v] of Object.entries(c.expect.values)) {
					expect(Number(result.values[k])).toBeCloseTo(Number(v), 6)
				}
			}
			if (c.expect.visible) {
				for (const [k, v] of Object.entries(c.expect.visible)) {
					expect(result.visible[k]).toBe(v)
				}
			}
			if (c.expect.required) {
				for (const [k, v] of Object.entries(c.expect.required)) {
					expect(result.required[k]).toBe(v)
				}
			}
			if (c.expect.errors) {
				expect(result.errors).toEqual(c.expect.errors)
			}
		})
	}
})

describe('condition matching', () => {
	it('and requires all', () => {
		const cond = { logic: 'and', rules: [{ field: 'a', op: 'eq', value: 1 }, { field: 'b', op: 'eq', value: 2 }] }
		expect(matches(cond, { a: 1, b: 2 })).toBe(true)
		expect(matches(cond, { a: 1, b: 9 })).toBe(false)
	})
	it('empty conditions always match', () => {
		expect(matches(null, {})).toBe(true)
		expect(matches({ rules: [] }, {})).toBe(true)
	})
	it('supports isEmpty / contains', () => {
		expect(matches({ rules: [{ field: 'x', op: 'isEmpty' }] }, { x: '' })).toBe(true)
		expect(matches({ rules: [{ field: 'x', op: 'contains', value: 'ell' }] }, { x: 'hello' })).toBe(true)
	})
})
