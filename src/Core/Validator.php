<?php

declare(strict_types=1);

namespace App\Core;

/** Declarative input validation: ['email' => 'required|email|max:190', ...]. */
final class Validator
{
    public array $errors = [];

    public function __construct(private array $data, private array $rules)
    {
    }

    public static function make(array $data, array $rules): self
    {
        $v = new self($data, $rules);
        $v->run();
        return $v;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleStr) {
            $value = $this->data[$field] ?? null;
            $rules = explode('|', $ruleStr);
            $present = !($value === null || $value === '' || $value === []);
            if (!$present) {
                if (in_array('required', $rules, true)) {
                    $this->errors[$field] = $this->label($field) . ' is required.';
                }
                continue;
            }
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $err = $this->check($name, $arg, $value, $field);
                if ($err) {
                    $this->errors[$field] = $err;
                    break;
                }
            }
        }
    }

    private function check(string $name, ?string $arg, mixed $v, string $field): ?string
    {
        $l = $this->label($field);
        $s = is_scalar($v) ? (string) $v : '';
        return match ($name) {
            'required' => null,
            'string' => is_string($v) ? null : "$l must be text.",
            'email' => filter_var($s, FILTER_VALIDATE_EMAIL) ? null : "$l must be a valid email.",
            'url' => (filter_var($s, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $s)) ? null : "$l must be a valid http(s) URL.",
            'https' => (filter_var($s, FILTER_VALIDATE_URL) && str_starts_with(strtolower($s), 'https://')) ? null : "$l must be an https:// URL.",
            'int' => filter_var($s, FILTER_VALIDATE_INT) !== false ? null : "$l must be a whole number.",
            'numeric' => is_numeric($s) ? null : "$l must be a number.",
            'min' => ($this->isNumericRule($field) ? (float) $s < (float) $arg : mb_strlen($s) < (int) $arg) ? "$l must be at least $arg" . ($this->isNumericRule($field) ? '.' : ' characters.') : null,
            'max' => ($this->isNumericRule($field) ? (float) $s > (float) $arg : mb_strlen($s) > (int) $arg) ? "$l may not be greater than $arg" . ($this->isNumericRule($field) ? '.' : ' characters.') : null,
            'in' => in_array($s, explode(',', (string) $arg), true) ? null : "$l is not a valid option.",
            'regex' => preg_match((string) $arg, $s) ? null : "$l has an invalid format.",
            'same' => $s === (string) ($this->data[$arg] ?? null) ? null : "$l does not match.",
            'lat' => (is_numeric($s) && (float) $s >= -90 && (float) $s <= 90) ? null : "$l must be between -90 and 90.",
            'lon' => (is_numeric($s) && (float) $s >= -180 && (float) $s <= 180) ? null : "$l must be between -180 and 180.",
            'password' => self::strongPassword($s) ? null : "$l needs upper and lower case letters and a number.",
            default => null,
        };
    }

    private function isNumericRule(string $field): bool
    {
        $r = explode('|', $this->rules[$field] ?? '');
        return in_array('int', $r, true) || in_array('numeric', $r, true);
    }

    public static function strongPassword(string $p): bool
    {
        return (bool) (preg_match('/[a-z]/', $p) && preg_match('/[A-Z]/', $p) && preg_match('/\d/', $p));
    }

    private function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
