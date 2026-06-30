/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'
import { listRules, createRule, updateRule, deleteRule, RULE_EFFECTS, CONDITION_OPS, FILTER_OPS } from './rules.js'

vi.mock('./ocs.js', () => ({
	ocsGet: vi.fn(() => Promise.resolve([])),
	ocsPost: vi.fn(() => Promise.resolve({ id: 1 })),
	ocsPut: vi.fn(() => Promise.resolve({ id: 1 })),
	ocsDelete: vi.fn(() => Promise.resolve()),
}))

describe('api/rules', () => {
	beforeEach(() => vi.clearAllMocks())

	it('lists rules for a register', async () => {
		await listRules(5)
		expect(ocsGet).toHaveBeenCalledWith('registers/5/rules')
	})

	it('creates a rule', async () => {
		const data = { effect: 'show', target: 'a' }
		await createRule(5, data)
		expect(ocsPost).toHaveBeenCalledWith('registers/5/rules', data)
	})

	it('updates a rule', async () => {
		await updateRule(7, { enabled: false })
		expect(ocsPut).toHaveBeenCalledWith('rules/7', { enabled: false })
	})

	it('deletes a rule', async () => {
		await deleteRule(7)
		expect(ocsDelete).toHaveBeenCalledWith('rules/7')
	})

	it('exposes the effect / operator catalogues used by the builders', () => {
		expect(RULE_EFFECTS.map((e) => e.id)).toEqual(['show', 'require', 'set_value', 'compute', 'validate'])
		expect(CONDITION_OPS.find((o) => o.id === 'matches')).toBeTruthy()
		// The filter subset excludes the regex/in operators that have no SQL mapping.
		expect(FILTER_OPS.find((o) => o.id === 'matches')).toBeUndefined()
		expect(FILTER_OPS.find((o) => o.id === 'in')).toBeUndefined()
	})
})
