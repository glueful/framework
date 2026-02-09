<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Http\Exceptions\HttpException;

/**
 * Validation Exception
 *
 * Thrown when request validation fails. Automatically renders
 * as a 422 Unprocessable Entity response.
 *
 * @example
 * throw new ValidationException([
 *     'email' => ['The email field is required.'],
 *     'password' => ['The password must be at least 8 characters.'],
 * ]);
 */
class ValidationException extends HttpException
{
    /**
     * @param array<string, array<string>> $errors Validation errors by field
     * @param array<string, string> $customMessages Custom error messages
     * @param string $message Overall error message
     */
    public function __construct(
        private array $errors,
        private array $customMessages = [],
        string $message = 'The given data was invalid.'
    ) {
        parent::__construct(422, $message);
        $this->context = $this->formatErrors();
    }

    /**
     * Get all validation errors
     *
     * @return array<string, array<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field
     *
     * @return array<string>
     */
    public function errorsFor(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Get the first error message for a field
     */
    public function firstError(string $field): ?string
    {
        $errors = $this->errorsFor($field);
        return $errors[0] ?? null;
    }

    /**
     * Get all first error messages (one per field)
     *
     * @return array<string, string>
     */
    public function firstErrors(): array
    {
        $first = [];
        foreach ($this->errors as $field => $messages) {
            if ($messages !== []) {
                $first[$field] = $messages[0];
            }
        }
        return $first;
    }

    /**
     * Check if there are errors for a field
     */
    public function hasError(string $field): bool
    {
        return isset($this->errors[$field]) && $this->errors[$field] !== [];
    }

    /**
     * Get the fields that have errors
     *
     * @return array<string>
     */
    public function failedFields(): array
    {
        return array_keys($this->errors);
    }

    /**
     * Get custom messages
     *
     * @return array<string, string>
     */
    public function getCustomMessages(): array
    {
        return $this->customMessages;
    }

    /**
     * Get total number of errors
     */
    public function errorCount(): int
    {
        $count = 0;
        foreach ($this->errors as $messages) {
            $count += count($messages);
        }
        return $count;
    }

    /**
     * Get the response representation
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => $this->formatErrors(),
        ];
    }

    /**
     * Format errors for response
     *
     * @return array<string, array<string>>
     */
    protected function formatErrors(): array
    {
        if ($this->customMessages === []) {
            return $this->errors;
        }

        $formatted = [];

        foreach ($this->errors as $field => $messages) {
            $formatted[$field] = array_map(function ($message) use ($field) {
                // Check for field.rule format custom message
                foreach ($this->customMessages as $key => $customMessage) {
                    if (str_starts_with($key, "{$field}.")) {
                        // Check if the message matches the rule
                        $rule = substr($key, strlen($field) + 1);
                        if (str_contains(strtolower($message), strtolower($rule))) {
                            return $customMessage;
                        }
                    }
                }

                // Check for field-level custom message
                if (isset($this->customMessages[$field])) {
                    return $this->customMessages[$field];
                }

                return $message;
            }, $messages);
        }

        return $formatted;
    }

    /**
     * Create from validator errors format
     *
     * @param array<string, string|array<string>> $errors
     * @param array<string, string> $customMessages
     */
    public static function withErrors(array $errors, array $customMessages = []): self
    {
        // Normalize errors to array format
        $normalized = [];
        foreach ($errors as $field => $messages) {
            $normalized[$field] = is_array($messages) ? $messages : [$messages];
        }

        return new self($normalized, $customMessages);
    }

    /**
     * Create for a single field error
     */
    public static function forField(string $field, string $message): self
    {
        return new self([$field => [$message]]);
    }

    /**
     * Create for multiple fields with single messages
     *
     * @param array<string, string> $fieldErrors
     */
    public static function forFields(array $fieldErrors): self
    {
        $errors = [];
        foreach ($fieldErrors as $field => $message) {
            $errors[$field] = [$message];
        }
        return new self($errors);
    }
}
