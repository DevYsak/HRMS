<?php

namespace App\Services;

/**
 * Safe math expression evaluator supporting: + - * / ( ) decimal numbers
 * and UPPERCASE_IDENTIFIER variable references.
 *
 * No eval(). Unknown variables default to 0.0. Division by zero returns 0.0.
 * Malformed expressions throw \RuntimeException.
 */
class FormulaEvaluator
{
    private string $expression;

    private int $pos;

    /** @var array<string, float> */
    private array $variables;

    private function __construct(string $expression, array $variables)
    {
        $this->expression = preg_replace('/\s+/', '', $expression);
        $this->pos = 0;
        $this->variables = $variables;
    }

    /**
     * Evaluate a formula expression.
     *
     * @param  array<string, float>  $variables  Map of component codes to amounts, e.g. ['BASIC' => 30000.0]
     *
     * @throws \RuntimeException for malformed expressions
     */
    public static function evaluate(string $expression, array $variables): float
    {
        $evaluator = new self($expression, $variables);

        $result = $evaluator->parseExpression();

        if ($evaluator->pos < strlen($evaluator->expression)) {
            throw new \RuntimeException("Unexpected character in formula: '{$expression}'");
        }

        return $result;
    }

    private function parseExpression(): float
    {
        $left = $this->parseTerm();

        while ($this->pos < strlen($this->expression) && in_array($this->expression[$this->pos], ['+', '-'], true)) {
            $op = $this->expression[$this->pos++];
            $right = $this->parseTerm();
            $left = $op === '+' ? $left + $right : $left - $right;
        }

        return $left;
    }

    private function parseTerm(): float
    {
        $left = $this->parseFactor();

        while ($this->pos < strlen($this->expression) && in_array($this->expression[$this->pos], ['*', '/'], true)) {
            $op = $this->expression[$this->pos++];
            $right = $this->parseFactor();

            if ($op === '/') {
                $left = $right == 0.0 ? 0.0 : $left / $right;
            } else {
                $left = $left * $right;
            }
        }

        return $left;
    }

    private function parseFactor(): float
    {
        $expr = $this->expression;
        $len = strlen($expr);

        if ($this->pos >= $len) {
            throw new \RuntimeException("Unexpected end of formula: '{$this->expression}'");
        }

        // Parenthesised sub-expression
        if ($expr[$this->pos] === '(') {
            $this->pos++;
            $value = $this->parseExpression();

            if ($this->pos >= $len || $expr[$this->pos] !== ')') {
                throw new \RuntimeException("Missing closing parenthesis in formula: '{$this->expression}'");
            }

            $this->pos++;

            return $value;
        }

        // Unary minus
        if ($expr[$this->pos] === '-') {
            $this->pos++;

            return -$this->parseFactor();
        }

        // Number: digits with optional decimal point
        if (ctype_digit($expr[$this->pos]) || $expr[$this->pos] === '.') {
            $start = $this->pos;
            while ($this->pos < $len && (ctype_digit($expr[$this->pos]) || $expr[$this->pos] === '.')) {
                $this->pos++;
            }

            return (float) substr($expr, $start, $this->pos - $start);
        }

        // Variable: UPPERCASE letters, digits, underscores — must start with a letter
        if (ctype_alpha($expr[$this->pos])) {
            $start = $this->pos;
            while ($this->pos < $len && (ctype_alnum($expr[$this->pos]) || $expr[$this->pos] === '_')) {
                $this->pos++;
            }
            $name = strtoupper(substr($expr, $start, $this->pos - $start));

            return (float) ($this->variables[$name] ?? 0.0);
        }

        throw new \RuntimeException("Unexpected character '{$expr[$this->pos]}' in formula: '{$this->expression}'");
    }
}
