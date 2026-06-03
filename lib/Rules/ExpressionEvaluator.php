<?php

declare(strict_types=1);

// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Dataforms\Rules;

/**
 * A small, SANDBOXED expression evaluator for computed fields and validation.
 *
 * This deliberately does NOT use eval() or any dynamic code execution. It is a
 * hand-written tokenizer + recursive-descent parser over a fixed grammar with a
 * whitelisted function set. Identifiers resolve to record field values only.
 *
 * Grammar:
 *   expr    := compare
 *   compare := add ( ('=='|'!='|'<'|'>'|'<='|'>=') add )?
 *   add     := mul ( ('+'|'-') mul )*
 *   mul     := unary ( ('*'|'/'|'%') unary )*
 *   unary   := '-' unary | primary
 *   primary := number | string | 'true' | 'false'
 *            | ident '(' args? ')' | ident | '(' expr ')'
 *
 * The JS implementation in src/rules/expression.js mirrors this exactly; the
 * cross-implementation test fixtures keep them in lockstep.
 */
class ExpressionEvaluator {
	private const FUNCTIONS = [
		'sum' => -1, 'min' => -1, 'max' => -1, 'concat' => -1, 'coalesce' => -1,
		'round' => -2, 'abs' => 1, 'len' => 1, 'lower' => 1, 'upper' => 1,
		'if' => 3, 'number' => 1,
	];

	/** @var array<int,array{type:string,value:mixed}> */
	private array $tokens = [];
	private int $pos = 0;

	/**
	 * @param array<string,mixed> $values machineName => value
	 * @return mixed number|string|bool|null
	 * @throws ExpressionException on syntax/runtime error.
	 */
	public function evaluate(string $expression, array $values) {
		$this->tokens = $this->tokenize($expression);
		$this->pos = 0;
		$result = $this->parseExpr($values);
		if ($this->pos < count($this->tokens)) {
			throw new ExpressionException('Unexpected token near position ' . $this->pos);
		}
		return $result;
	}

	// ---- tokenizer -------------------------------------------------------

	/**
	 * @return array<int,array{type:string,value:mixed}>
	 */
	private function tokenize(string $s): array {
		$tokens = [];
		$len = strlen($s);
		$i = 0;
		while ($i < $len) {
			$c = $s[$i];
			if (ctype_space($c)) {
				$i++;
				continue;
			}
			// number
			if (ctype_digit($c) || ($c === '.' && $i + 1 < $len && ctype_digit($s[$i + 1]))) {
				$num = '';
				while ($i < $len && (ctype_digit($s[$i]) || $s[$i] === '.')) {
					$num .= $s[$i++];
				}
				$tokens[] = ['type' => 'number', 'value' => (float)$num];
				continue;
			}
			// string literal
			if ($c === "'" || $c === '"') {
				$quote = $c;
				$i++;
				$str = '';
				while ($i < $len && $s[$i] !== $quote) {
					if ($s[$i] === '\\' && $i + 1 < $len) {
						$i++;
					}
					$str .= $s[$i++];
				}
				if ($i >= $len) {
					throw new ExpressionException('Unterminated string literal');
				}
				$i++; // closing quote
				$tokens[] = ['type' => 'string', 'value' => $str];
				continue;
			}
			// identifier
			if (ctype_alpha($c) || $c === '_') {
				$id = '';
				while ($i < $len && (ctype_alnum($s[$i]) || $s[$i] === '_')) {
					$id .= $s[$i++];
				}
				$tokens[] = ['type' => 'ident', 'value' => $id];
				continue;
			}
			// two-char operators
			$two = substr($s, $i, 2);
			if (in_array($two, ['==', '!=', '<=', '>='], true)) {
				$tokens[] = ['type' => 'op', 'value' => $two];
				$i += 2;
				continue;
			}
			if (strpos('+-*/%()<>,', $c) !== false) {
				$tokens[] = ['type' => 'op', 'value' => $c];
				$i++;
				continue;
			}
			throw new ExpressionException('Unexpected character: ' . $c);
		}
		return $tokens;
	}

	// ---- parser ----------------------------------------------------------

	private function peek(): ?array {
		return $this->tokens[$this->pos] ?? null;
	}

	private function isOp(string $op): bool {
		$t = $this->peek();
		return $t !== null && $t['type'] === 'op' && $t['value'] === $op;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return mixed
	 */
	private function parseExpr(array $values) {
		$left = $this->parseAdd($values);
		$t = $this->peek();
		if ($t !== null && $t['type'] === 'op' && in_array($t['value'], ['==', '!=', '<', '>', '<=', '>='], true)) {
			$this->pos++;
			$right = $this->parseAdd($values);
			return $this->compare((string)$t['value'], $left, $right);
		}
		return $left;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return mixed
	 */
	private function parseAdd(array $values) {
		$left = $this->parseMul($values);
		while (($t = $this->peek()) !== null && $t['type'] === 'op' && ($t['value'] === '+' || $t['value'] === '-')) {
			$this->pos++;
			$right = $this->parseMul($values);
			if ($t['value'] === '+') {
				// numeric add if both numeric, else string concat
				if (is_numeric($left) && is_numeric($right)) {
					$left = (float)$left + (float)$right;
				} else {
					$left = $this->toStr($left) . $this->toStr($right);
				}
			} else {
				$left = (float)$left - (float)$right;
			}
		}
		return $left;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return mixed
	 */
	private function parseMul(array $values) {
		$left = $this->parseUnary($values);
		while (($t = $this->peek()) !== null && $t['type'] === 'op' && in_array($t['value'], ['*', '/', '%'], true)) {
			$this->pos++;
			$right = $this->parseUnary($values);
			$l = (float)$left;
			$r = (float)$right;
			if ($t['value'] === '*') {
				$left = $l * $r;
			} elseif ($t['value'] === '/') {
				$left = $r == 0.0 ? 0.0 : $l / $r;
			} else {
				$left = $r == 0.0 ? 0.0 : fmod($l, $r);
			}
		}
		return $left;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return mixed
	 */
	private function parseUnary(array $values) {
		if ($this->isOp('-')) {
			$this->pos++;
			return -1.0 * (float)$this->parseUnary($values);
		}
		return $this->parsePrimary($values);
	}

	/**
	 * @param array<string,mixed> $values
	 * @return mixed
	 */
	private function parsePrimary(array $values) {
		$t = $this->peek();
		if ($t === null) {
			throw new ExpressionException('Unexpected end of expression');
		}
		if ($t['type'] === 'number' || $t['type'] === 'string') {
			$this->pos++;
			return $t['value'];
		}
		if ($this->isOp('(')) {
			$this->pos++;
			$val = $this->parseExpr($values);
			if (!$this->isOp(')')) {
				throw new ExpressionException('Expected )');
			}
			$this->pos++;
			return $val;
		}
		if ($t['type'] === 'ident') {
			$name = (string)$t['value'];
			$this->pos++;
			if ($this->isOp('(')) {
				return $this->callFunction(strtolower($name), $values);
			}
			if ($name === 'true') {
				return true;
			}
			if ($name === 'false') {
				return false;
			}
			// field reference
			return $values[$name] ?? null;
		}
		throw new ExpressionException('Unexpected token: ' . json_encode($t['value']));
	}

	/**
	 * @param array<string,mixed> $values
	 * @return mixed
	 */
	private function callFunction(string $name, array $values) {
		if (!array_key_exists($name, self::FUNCTIONS)) {
			throw new ExpressionException('Unknown function: ' . $name);
		}
		$this->pos++; // consume '('
		$args = [];
		if (!$this->isOp(')')) {
			$args[] = $this->parseExpr($values);
			while ($this->isOp(',')) {
				$this->pos++;
				$args[] = $this->parseExpr($values);
			}
		}
		if (!$this->isOp(')')) {
			throw new ExpressionException('Expected ) after arguments to ' . $name);
		}
		$this->pos++;

		$arity = self::FUNCTIONS[$name];
		if ($arity >= 0 && count($args) !== $arity) {
			throw new ExpressionException($name . '() expects ' . $arity . ' argument(s)');
		}
		if ($arity === -2 && count($args) < 1) {
			throw new ExpressionException($name . '() expects at least 1 argument');
		}

		return $this->applyFunction($name, $args);
	}

	/**
	 * @param array<int,mixed> $a
	 * @return mixed
	 */
	private function applyFunction(string $name, array $a) {
		switch ($name) {
			case 'sum':
				return array_sum(array_map(fn ($x) => (float)$x, $a));
			case 'min':
				return empty($a) ? 0 : min(array_map(fn ($x) => (float)$x, $a));
			case 'max':
				return empty($a) ? 0 : max(array_map(fn ($x) => (float)$x, $a));
			case 'abs':
				return abs((float)$a[0]);
			case 'round':
				return round((float)$a[0], (int)($a[1] ?? 0));
			case 'len':
				return mb_strlen($this->toStr($a[0]));
			case 'lower':
				return mb_strtolower($this->toStr($a[0]));
			case 'upper':
				return mb_strtoupper($this->toStr($a[0]));
			case 'number':
				return is_numeric($a[0]) ? (float)$a[0] : 0.0;
			case 'concat':
				return implode('', array_map(fn ($x) => $this->toStr($x), $a));
			case 'coalesce':
				foreach ($a as $x) {
					if ($x !== null && $x !== '') {
						return $x;
					}
				}
				return null;
			case 'if':
				return $this->truthy($a[0]) ? $a[1] : $a[2];
		}
		throw new ExpressionException('Unhandled function: ' . $name);
	}

	/**
	 * @param mixed $l
	 * @param mixed $r
	 */
	private function compare(string $op, $l, $r): bool {
		if (is_numeric($l) && is_numeric($r)) {
			$l = (float)$l;
			$r = (float)$r;
		}
		switch ($op) {
			case '==': return $l == $r;
			case '!=': return $l != $r;
			case '<': return $l < $r;
			case '>': return $l > $r;
			case '<=': return $l <= $r;
			case '>=': return $l >= $r;
		}
		return false;
	}

	/**
	 * @param mixed $v
	 */
	private function truthy($v): bool {
		return $v !== null && $v !== false && $v !== '' && $v !== 0 && $v !== 0.0 && $v !== '0';
	}

	/**
	 * @param mixed $v
	 */
	private function toStr($v): string {
		if (is_bool($v)) {
			return $v ? 'true' : 'false';
		}
		if (is_float($v) && $v == (int)$v) {
			return (string)(int)$v;
		}
		return (string)($v ?? '');
	}
}
