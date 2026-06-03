/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Sandboxed expression evaluator for computed fields and validation — the JS
 * mirror of lib/Rules/ExpressionEvaluator.php. NO eval / Function(): a
 * hand-written tokenizer + recursive-descent parser over a fixed grammar with
 * a whitelisted function set. Identifiers resolve to record field values only.
 *
 * Keep this in lockstep with the PHP implementation (shared test fixtures).
 */

const FUNCTIONS = {
	sum: -1, min: -1, max: -1, concat: -1, coalesce: -1,
	round: -2, abs: 1, len: 1, lower: 1, upper: 1, if: 3, number: 1,
}

export class ExpressionError extends Error {}

function tokenize(s) {
	const tokens = []
	let i = 0
	const len = s.length
	const isDigit = (c) => c >= '0' && c <= '9'
	const isAlpha = (c) => (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || c === '_'
	const isAlnum = (c) => isAlpha(c) || isDigit(c)

	while (i < len) {
		const c = s[i]
		if (c === ' ' || c === '\t' || c === '\n' || c === '\r') { i++; continue }
		if (isDigit(c) || (c === '.' && isDigit(s[i + 1]))) {
			let num = ''
			while (i < len && (isDigit(s[i]) || s[i] === '.')) num += s[i++]
			tokens.push({ type: 'number', value: parseFloat(num) })
			continue
		}
		if (c === "'" || c === '"') {
			const quote = c
			i++
			let str = ''
			while (i < len && s[i] !== quote) {
				if (s[i] === '\\' && i + 1 < len) i++
				str += s[i++]
			}
			if (i >= len) throw new ExpressionError('Unterminated string literal')
			i++
			tokens.push({ type: 'string', value: str })
			continue
		}
		if (isAlpha(c)) {
			let id = ''
			while (i < len && isAlnum(s[i])) id += s[i++]
			tokens.push({ type: 'ident', value: id })
			continue
		}
		const two = s.substr(i, 2)
		if (['==', '!=', '<=', '>='].includes(two)) { tokens.push({ type: 'op', value: two }); i += 2; continue }
		if ('+-*/%()<>,'.includes(c)) { tokens.push({ type: 'op', value: c }); i++; continue }
		throw new ExpressionError('Unexpected character: ' + c)
	}
	return tokens
}

const toStr = (v) => {
	if (typeof v === 'boolean') return v ? 'true' : 'false'
	if (typeof v === 'number' && Number.isInteger(v)) return String(v)
	return v == null ? '' : String(v)
}
const num = (v) => (typeof v === 'number' ? v : parseFloat(v)) || 0
const isNumeric = (v) => v !== '' && v != null && !isNaN(parseFloat(v)) && isFinite(v)
const truthy = (v) => v !== null && v !== undefined && v !== false && v !== '' && v !== 0 && v !== '0'

/**
 * Evaluate an expression against a values map.
 *
 * @param {string} expression the expression source
 * @param {object} values machineName -> value
 * @return {number|string|boolean|null}
 */
export function evaluateExpression(expression, values) {
	const tokens = tokenize(expression)
	let pos = 0

	const peek = () => tokens[pos] ?? null
	const isOp = (op) => { const t = peek(); return t && t.type === 'op' && t.value === op }

	function parseExpr() {
		const left = parseAdd()
		const t = peek()
		if (t && t.type === 'op' && ['==', '!=', '<', '>', '<=', '>='].includes(t.value)) {
			pos++
			return compare(t.value, left, parseAdd())
		}
		return left
	}
	function parseAdd() {
		let left = parseMul()
		let t
		while ((t = peek()) && t.type === 'op' && (t.value === '+' || t.value === '-')) {
			pos++
			const right = parseMul()
			if (t.value === '+') {
				left = (isNumeric(left) && isNumeric(right)) ? num(left) + num(right) : toStr(left) + toStr(right)
			} else {
				left = num(left) - num(right)
			}
		}
		return left
	}
	function parseMul() {
		let left = parseUnary()
		let t
		while ((t = peek()) && t.type === 'op' && ['*', '/', '%'].includes(t.value)) {
			pos++
			const r = num(parseUnary())
			const l = num(left)
			if (t.value === '*') left = l * r
			else if (t.value === '/') left = r === 0 ? 0 : l / r
			else left = r === 0 ? 0 : l % r
		}
		return left
	}
	function parseUnary() {
		if (isOp('-')) { pos++; return -1 * num(parseUnary()) }
		return parsePrimary()
	}
	function parsePrimary() {
		const t = peek()
		if (!t) throw new ExpressionError('Unexpected end of expression')
		if (t.type === 'number' || t.type === 'string') { pos++; return t.value }
		if (isOp('(')) {
			pos++
			const v = parseExpr()
			if (!isOp(')')) throw new ExpressionError('Expected )')
			pos++
			return v
		}
		if (t.type === 'ident') {
			const name = t.value
			pos++
			if (isOp('(')) return callFunction(name.toLowerCase())
			if (name === 'true') return true
			if (name === 'false') return false
			return values[name] ?? null
		}
		throw new ExpressionError('Unexpected token')
	}
	function callFunction(name) {
		if (!(name in FUNCTIONS)) throw new ExpressionError('Unknown function: ' + name)
		pos++ // '('
		const args = []
		if (!isOp(')')) {
			args.push(parseExpr())
			while (isOp(',')) { pos++; args.push(parseExpr()) }
		}
		if (!isOp(')')) throw new ExpressionError('Expected ) after arguments')
		pos++
		const arity = FUNCTIONS[name]
		if (arity >= 0 && args.length !== arity) throw new ExpressionError(name + '() expects ' + arity + ' argument(s)')
		return applyFunction(name, args)
	}

	const result = parseExpr()
	if (pos < tokens.length) throw new ExpressionError('Unexpected token near ' + pos)
	return result
}

function applyFunction(name, a) {
	switch (name) {
		case 'sum': return a.reduce((s, x) => s + num(x), 0)
		case 'min': return a.length ? Math.min(...a.map(num)) : 0
		case 'max': return a.length ? Math.max(...a.map(num)) : 0
		case 'abs': return Math.abs(num(a[0]))
		case 'round': { const d = parseInt(a[1] ?? 0, 10) || 0; const f = 10 ** d; return Math.round(num(a[0]) * f) / f }
		case 'len': return toStr(a[0]).length
		case 'lower': return toStr(a[0]).toLowerCase()
		case 'upper': return toStr(a[0]).toUpperCase()
		case 'number': return isNumeric(a[0]) ? num(a[0]) : 0
		case 'concat': return a.map(toStr).join('')
		case 'coalesce': return a.find((x) => x !== null && x !== undefined && x !== '') ?? null
		case 'if': return truthy(a[0]) ? a[1] : a[2]
	}
	throw new ExpressionError('Unhandled function: ' + name)
}

function compare(op, l, r) {
	if (isNumeric(l) && isNumeric(r)) { l = num(l); r = num(r) }
	switch (op) {
		case '==': return l == r // eslint-disable-line eqeqeq
		case '!=': return l != r // eslint-disable-line eqeqeq
		case '<': return l < r
		case '>': return l > r
		case '<=': return l <= r
		case '>=': return l >= r
	}
	return false
}
