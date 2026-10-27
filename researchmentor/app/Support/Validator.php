<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Small rule-based validator.
 * Rules (pipe separated): required, nullable, string, int, numeric, bool, array, email, date, uuid,
 * min:n, max:n (string length / numeric value / array size), in:a,b,c, regex:/../
 */
final class Validator
{
    private array $errors = [];
    private array $clean = [];
    private bool $numeric = false;

    private function __construct(private array $data, private array $rules)
    {
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    /** @return array validated data (only keys present in $rules) */
    public function validate(): array
    {
        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $present = array_key_exists($field, $this->data);
            $value = $this->data[$field] ?? null;
            $nullable = in_array('nullable', $rules, true);
            $required = in_array('required', $rules, true);
            $empty = !$present || $value === null || (is_string($value) && trim($value) === '');

            if ($empty) {
                if ($required) {
                    $this->errors[$field][] = 'This field is required.';
                } elseif ($present) {
                    $this->clean[$field] = null;
                }
                continue;
            }
            if (is_string($value)) {
                $value = trim($value);
            }
            $this->numeric = in_array('int', $rules, true) || in_array('numeric', $rules, true);
            foreach ($rules as $rule) {
                if (in_array($rule, ['required', 'nullable'], true)) {
                    continue;
                }
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $err = $this->check($name, $arg, $value);
                if ($err !== null) {
                    $this->errors[$field][] = $err;
                    break;
                }
            }
            $this->clean[$field] = $this->cast($rules, $value);
        }
        if ($this->errors) {
            throw HttpException::unprocessable('The given data was invalid.', $this->errors);
        }
        return $this->clean;
    }

    private function cast(array $rules, mixed $value): mixed
    {
        if (in_array('int', $rules, true)) return (int) $value;
        if (in_array('numeric', $rules, true)) return $value + 0;
        if (in_array('bool', $rules, true)) return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        return $value;
    }

    private function check(string $rule, ?string $arg, mixed $v): ?string
    {
        switch ($rule) {
            case 'string':
                return is_string($v) ? null : 'Must be text.';
            case 'int':
                return (is_int($v) || (is_string($v) && preg_match('/^-?\d+$/', $v))) ? null : 'Must be a whole number.';
            case 'numeric':
                return is_numeric($v) ? null : 'Must be a number.';
            case 'bool':
                return (is_bool($v) || in_array($v, [0, 1, '0', '1', 'true', 'false'], true)) ? null : 'Must be true or false.';
            case 'array':
                return is_array($v) ? null : 'Must be a list.';
            case 'email':
                return (is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) && strlen($v) <= 190) ? null : 'Must be a valid email address.';
            case 'date':
                if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $v) || strtotime($v) === false) return 'Must be a valid date (YYYY-MM-DD).';
                return null;
            case 'datetime':
                return (is_string($v) && strtotime($v) !== false) ? null : 'Must be a valid date and time.';
            case 'uuid':
                return Uuid::isValid(is_string($v) ? $v : null) ? null : 'Must be a valid identifier.';
            case 'min':
                return $this->size($v) >= (float) $arg ? null : "Must be at least {$arg}.";
            case 'max':
                return $this->size($v) <= (float) $arg ? null : "Must be at most {$arg}.";
            case 'in':
                return in_array((string) $v, explode(',', (string) $arg), true) ? null : 'Invalid value.';
            case 'regex':
                return preg_match((string) $arg, (string) $v) === 1 ? null : 'Invalid format.';
        }
        throw new \InvalidArgumentException("Unknown validation rule: {$rule}");
    }

    private function size(mixed $v): float
    {
        if (is_array($v)) return (float) count($v);
        if ($this->numeric && is_numeric($v)) return (float) $v;
        return (float) mb_strlen((string) $v);
    }
}
