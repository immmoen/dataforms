/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Client for the rules OCS API.
 */
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

// Build the path literally — a {placeholder} would percent-encode slashes.
const url = (path) => generateOcsUrl('apps/dataforms/api/v1/' + path)
const config = { timeout: 30000, headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' } }
const unwrap = (response) => response.data.ocs.data

/**
 * @param {number} registerId register id
 * @return {Promise<object[]>} rules for the register
 */
export async function listRules(registerId) {
	return unwrap(await axios.get(url(`registers/${registerId}/rules`), config))
}

/**
 * @param {number} registerId register id
 * @param {object} data {effect, target, conditions?, value?, expression?, validation?, enabled?}
 * @return {Promise<object>} the created rule
 */
export async function createRule(registerId, data) {
	return unwrap(await axios.post(url(`registers/${registerId}/rules`), data, config))
}

/**
 * @param {number} id rule id
 * @param {object} data partial rule
 * @return {Promise<object>} the updated rule
 */
export async function updateRule(id, data) {
	return unwrap(await axios.put(url(`rules/${id}`), data, config))
}

/**
 * @param {number} id rule id
 */
export async function deleteRule(id) {
	await axios.delete(url(`rules/${id}`), config)
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
	{ id: 'isEmpty', label: 'is empty' },
	{ id: 'isNotEmpty', label: 'is not empty' },
	{ id: 'matches', label: 'matches regex' },
]
