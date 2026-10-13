<?php
declare(strict_types=1);

/** Lightweight validator. Returns field => message map. */
final class Validator
{
    /** @param array<string,mixed> $data @param array<string,string> $rules @return array<string,string> */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleStr) {
            $value = $data[$field] ?? null;
            $parts = explode('|', $ruleStr);
            foreach ($parts as $rule) {
                $rule = trim($rule);
                if ($rule === 'required') {
                    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                        $errors[$field] = 'This field is required.';
                        break;
                    }
                } elseif ($rule === 'email') {
                    if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$field] = 'Must be a valid email address.';
                        break;
                    }
                } elseif (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (is_string($value) && mb_strlen($value) < $min) {
                        $errors[$field] = "Must be at least {$min} characters.";
                        break;
                    }
                    if (is_numeric($value) && (float) $value < $min) {
                        $errors[$field] = "Must be at least {$min}.";
                        break;
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_string($value) && mb_strlen($value) > $max) {
                        $errors[$field] = "Must be at most {$max} characters.";
                        break;
                    }
                } elseif (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if ($value !== null && $value !== '' && !in_array((string) $value, $allowed, true)) {
                        $errors[$field] = 'Invalid value.';
                        break;
                    }
                } elseif ($rule === 'int') {
                    if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                        $errors[$field] = 'Must be an integer.';
                        break;
                    }
                } elseif ($rule === 'phone') {
                    if ($value !== null && $value !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', (string) $value)) {
                        $errors[$field] = 'Must be a valid phone number.';
                        break;
                    }
                }
            }
        }
        return $errors;
    }

    /** @param array<string,mixed> $data */
    public static function string(array $data, string $key, string $default = ''): string
    {
        $v = $data[$key] ?? $default;
        return is_string($v) ? trim($v) : (string) $v;
    }

    /** @param array<string,mixed> $data */
    public static function int(array $data, string $key, int $default = 0): int
    {
        $v = $data[$key] ?? $default;
        return is_numeric($v) ? (int) $v : $default;
    }

    /** @param array<string,mixed> $data */
    public static function paginate(array $data): array
    {
        $page = max(1, self::int($data, 'page', 1));
        $per = self::int($data, 'per_page', 15);
        $per = max(1, min(100, $per));
        return [$page, $per, ($page - 1) * $per];
    }
}
