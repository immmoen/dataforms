/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Live rule engine for the form renderer — the JS mirror of
 * lib/Rules/RuleEvaluator.php. Same rule JSON, same semantics; the server
 * re-checks everything authoritatively on save.
 */
import { evaluateExpression, ExpressionError } from './expression.js'

const isEmpty = (v) => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)
const truthy = (v) => v !== null && v !== undefined && v !== false && v !== '' && v !== 0 && v !== '0'
const isNumeric = (v) => v !== '' && v != null && !isNaN(parseFloat(v)) && isFinite(v)

const asString = (v) => {
	if (typeof v === 'boolean') return v ? 'true' : 'false'
	if (Array.isArray(v)) return v.join(',')
	return v == null ? '' : String(v)
}

/**
 *
 * @param a
 * @param b
 */
function looseEq(a, b) {
	if (isNumeric(a) && isNumeric(b)) return parseFloat(a) === parseFloat(b)
	return asString(a) === asString(b)
}

/**
 *
 * @param cond
 * @param values
 */
function testCondition(cond, values) {
	const left = values[cond.field] ?? null
	const right = cond.value ?? null
	switch (cond.op) {
	case 'eq': return looseEq(left, right)
	case 'neq': return !looseEq(left, right)
	case 'gt': return parseFloat(left) > parseFloat(right)
	case 'lt': return parseFloat(left) < parseFloat(right)
	case 'gte': return parseFloat(left) >= parseFloat(right)
	case 'lte': return parseFloat(left) <= parseFloat(right)
	case 'contains': return asString(left).includes(asString(right))
	case 'in': return Array.isArray(right) && right.some((x) => looseEq(x, left))
	case 'isEmpty': return isEmpty(left)
	case 'isNotEmpty': return !isEmpty(left)
	case 'matches': try { return asString(right) !== '' && new RegExp(asString(right)).test(asString(left)) } catch { return false }
	default: return false
	}
}

/**
 * @param {object|null} conditions {logic, rules: [{field, op, value}]}
 * @param {object} values
 * @return {boolean}
 */
export function matches(conditions, values) {
	if (!conditions || !conditions.rules || conditions.rules.length === 0) return true
	const results = conditions.rules.map((c) => testCondition(c, values))
	return (conditions.logic || 'and').toLowerCase() === 'or'
		? results.some(Boolean)
		: results.every(Boolean)
}

/**
 *
 * @param validation
 * @param value
 * @param values
 */
function runValidation(validation, value, values) {
	const message = validation.message || 'Invalid value'
	if (isEmpty(value)) return null
	switch (validation.kind) {
	case 'regex':
		if (!validation.pattern) return null
		try { return new RegExp(validation.pattern).test(asString(value)) ? null : message } catch { return message }
	case 'range': {
		const n = parseFloat(value)
		if (validation.min !== '' && validation.min != null && n < parseFloat(validation.min)) return message
		if (validation.max !== '' && validation.max != null && n > parseFloat(validation.max)) return message
		return null
	}
	case 'expression':
		try { return truthy(evaluateExpression(validation.expression || 'true', values)) ? null : message } catch (e) { return e instanceof ExpressionError ? message : message }
	default: return null
	}
}

/**
 * Evaluate all rules for a register against the current form values.
 *
 * @param {object[]} fields [{machineName, type, mandatory}]
 * @param {object[]} rules [{effect, target, conditions, value, expression, validation, enabled}]
 * @param {object} inputValues machineName -> value
 * @return {{values:object, visible:object, required:object, errors:object}}
 */
export function evaluateRules(fields, rules, inputValues) {
	const values = { ...inputValues }
	const visible = {}
	const required = {}
	const errors = {}
	const hasShowRule = {}

	for (const f of fields) {
		visible[f.machineName] = true
		required[f.machineName] = !!f.mandatory
	}

	const active = rules.filter((r) => r.enabled !== false)

	// 1) computed fields first
	for (const r of active) {
		if (r.effect === 'compute' && r.expression) {
			try { values[r.target] = evaluateExpression(r.expression, values) } catch { values[r.target] = null }
		}
	}
	// 2) visibility
	for (const r of active) {
		if (r.effect === 'show') {
			if (!hasShowRule[r.target]) { hasShowRule[r.target] = true; visible[r.target] = false }
			if (matches(r.conditions, values)) visible[r.target] = true
		}
	}
	// 3) set_value (default when empty)
	for (const r of active) {
		if (r.effect === 'set_value' && matches(r.conditions, values)) {
			if (isEmpty(values[r.target])) values[r.target] = r.value ?? null
		}
	}
	// 4) require
	for (const r of active) {
		if (r.effect === 'require' && matches(r.conditions, values)) required[r.target] = true
	}
	// 5) required-but-empty (visible only)
	for (const f of fields) {
		const mn = f.machineName
		if (visible[mn] && required[mn] && isEmpty(values[mn])) errors[mn] = 'This field is required'
	}
	// 6) custom validations
	for (const r of active) {
		if (r.effect !== 'validate') continue
		if (!visible[r.target] || errors[r.target]) continue
		if (!matches(r.conditions, values)) continue
		const msg = runValidation(r.validation || {}, values[r.target], values)
		if (msg) errors[r.target] = msg
	}

	return { values, visible, required, errors }
}
