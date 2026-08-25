<?php
/**
 * Server-side input validation for the HR1 API.
 * Collects field => message errors and lets controllers decide the response.
 */

declare(strict_types=1);

class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public function required(string ...$fields): self
    {
        foreach ($fields as $f) {
            $v = $this->data[$f] ?? null;
            if ($v === null || (is_string($v) && trim($v) === '')) {
                $this->errors[$f] ??= ucfirst(str_replace('_', ' ', $f)) . ' is required.';
            }
        }
        return $this;
    }

    public function email(string $field): self
    {
        $v = $this->data[$field] ?? null;
        if (is_string($v) && trim($v) !== '' && !filter_var(trim($v), FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] ??= 'A valid email address is required.';
        }
        return $this;
    }

    public function phone(string $field): self
    {
        $v = $this->data[$field] ?? null;
        if (is_string($v) && trim($v) !== ''
            && !preg_match('/^[0-9+\-\s().]{7,20}$/', trim($v))) {
            $this->errors[$field] ??= 'Phone number may contain digits, spaces, + - ( ) . and must be 7-20 characters.';
        }
        return $this;
    }

    public function intMin(string $field, int $min = 0): self
    {
        $v = $this->data[$field] ?? null;
        if ($v === null || $v === '') {
            return $this;
        }
        if (filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min]]) === false) {
            $this->errors[$field] ??= ucfirst(str_replace('_', ' ', $field)) . " must be an integer >= $min.";
        }
        return $this;
    }

    /** Values are already-normalised stored values. Skips empty input. */
    public function inSet(string $field, array $values): self
    {
        $v = $this->data[$field] ?? null;
        if (is_string($v) && trim($v) !== '' && !in_array(strtolower(trim($v)), $values, true)) {
            $this->errors[$field] ??= ucfirst($field) . ' must be one of: ' . implode(', ', $values) . '.';
        }
        return $this;
    }

    public function maxLen(string $field, int $max): self
    {
        $v = $this->data[$field] ?? null;
        if (is_string($v) && mb_strlen($v) > $max) {
            $this->errors[$field] ??= ucfirst(str_replace('_', ' ', $field)) . " may not exceed $max characters.";
        }
        return $this;
    }

    public function dateYMD(string $field): self
    {
        $v = $this->data[$field] ?? null;
        if (is_string($v) && trim($v) !== '') {
            $d = DateTime::createFromFormat('Y-m-d', trim($v));
            if (!$d || $d->format('Y-m-d') !== trim($v)) {
                $this->errors[$field] ??= ucfirst(str_replace('_', ' ', $field)) . ' must use YYYY-MM-DD format.';
            }
        }
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
