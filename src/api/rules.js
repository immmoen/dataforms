/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the rules OCS API.
 */
import { ocsGet, ocsPost, ocsPut, ocsDelete } from './ocs.js'

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} rules for the register
 */
export async function listRules(registerId) {
	return ocsGet(`registers/${registerId}/rules`)
}

/**
 * @param {number} registerId register id
 * @param {object} data {effect, target, conditions?, value?, expression?, validation?, enabled?}
 * @return {Promise<object>} the created rule
 */
export async function createRule(registerId, data) {
	return ocsPost(`registers/${registerId}/rules`, data)
}

/**
 * @param {number} id rule id
 * @param {object} data partial rule
 * @return {Promise<object>} the updated rule
 */
export async function updateRule(id, data) {
	return ocsPut(`rules/${id}`, data)
}

/**
 * @param {number} id rule id
 */
export async function deleteRule(id) {
	await ocsDelete(`rules/${id}`)
}

/** Available rule effects, for the rule builder. */
export const RULE_EFFECTS = [
	{ id: 'show', label: 'Show field', needs: 'conditions' },
	{ id: 'require', label: 'Make required', needs: 'conditions' },
	{ id: 'set_value', label: 'Set default value', needs: 'conditions+value' },
	{ id: 'compute', label: 'Compute value', needs: 'expression' },
	{ id: 'validate', label: 'Validate', needs: 'conditions+validation' },
]

/** Condition operators, for the condition builder. */
export const CONDITION_OPS = [
	{ id: 'eq', label: '=' },
	{ id: 'neq', label: '≠' },
	{ id: 'gt', label: '>' },
	{ id: 'lt', label: '<' },
	{ id: 'gte', label: '≥' },
	{ id: 'lte', label: '≤' },
	{ id: 'contains', label: 'contains' },
	{ id: 'in', label: 'is one of (comma-separated)' },
	{ id: 'isEmpty', label: 'is empty' },
	{ id: 'isNotEmpty', label: 'is not empty' },
	{ id: 'matches', label: 'matches regex' },
]

/** Operators offered in the records filter bar (subset that maps to SQL). */
export const FILTER_OPS = [
	{ id: 'eq', label: '=' },
	{ id: 'neq', label: '≠' },
	{ id: 'contains', label: 'contains' },
	{ id: 'gt', label: '>' },
	{ id: 'lt', label: '<' },
	{ id: 'gte', label: '≥' },
	{ id: 'lte', label: '≤' },
	{ id: 'isEmpty', label: 'is empty' },
	{ id: 'isNotEmpty', label: 'is not empty' },
]
