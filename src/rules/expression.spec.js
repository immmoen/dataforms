/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Drives the JS expression evaluator with the shared fixtures
 * (tests/fixtures/rule-cases.json) — the same file the PHP suite uses, so the
 * two implementations are asserted to agree.
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'
import { describe, it, expect } from 'vitest'
import { evaluateExpression, ExpressionError } from './expression.js'

const here = dirname(fileURLToPath(import.meta.url))
const fixtures = JSON.parse(readFileSync(join(here, '../../tests/fixtures/rule-cases.json'), 'utf8'))

describe('expression evaluator (shared fixtures)', () => {
	for (const c of fixtures.expression) {
		it(c.name, () => {
			const result = evaluateExpression(c.expr, c.values)
			if (typeof c.expect === 'number') {
				expect(Number(result)).toBeCloseTo(c.expect, 6)
			} else {
				expect(result).toEqual(c.expect)
			}
		})
	}
})

describe('expression evaluator (safety)', () => {
	it('rejects unknown functions', () => {
		expect(() => evaluateExpression('danger(1)', {})).toThrow(ExpressionError)
	})
	it('does not expose globals', () => {
		// identifiers resolve to field values only — undefined ones are null
		expect(evaluateExpression('window', {})).toBe(null)
		expect(evaluateExpression('process', {})).toBe(null)
	})
	it('rejects malformed input', () => {
		expect(() => evaluateExpression('1 +', {})).toThrow(ExpressionError)
		expect(() => evaluateExpression('(1 + 2', {})).toThrow(ExpressionError)
	})
})
