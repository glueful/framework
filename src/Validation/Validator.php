<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Validation\Contracts\DatabaseAwareRule;
use Glueful\Validation\Contracts\Rule;
use Glueful\Validation\Contracts\MutatingRule;
use Glueful\Validation\Contracts\ValidatorInterface;
use Glueful\Validation\Rules\Nullable;
use Glueful\Validation\Rules\Sometimes;

final class Validator implements ValidatorInterface
{
    /** @var array<string, Rule[]> */
    private array $rules;
    /** @var array<string, mixed> */
    private array $filtered = [];

    /**
     * @param array<string, Rule[]> $rules
     * @param ApplicationContext|null $context Used to resolve the database Connection (lazily, only
     *                                         when a DatabaseAwareRule such as unique/exists runs).
     * @param Connection|null $connection Explicit connection for DatabaseAwareRules; wins over $context.
     */
    public function __construct(
        array $rules = [],
        private readonly ?ApplicationContext $context = null,
        private ?Connection $connection = null,
    ) {
        $this->rules = $rules;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data): array
    {
        $errors = [];
        foreach ($this->rules as $field => $rules) {
            // `sometimes`: an absent field skips its whole rule chain.
            if ($this->skippedBySometimes((string) $field, $rules, $data)) {
                continue;
            }

            $value = $data[$field] ?? null;
            $ruleContext = ['field' => $field, 'data' => $data];
            if ($this->context !== null) {
                $ruleContext['context'] = $this->context;
            }

            foreach ($rules as $rule) {
                if ($rule instanceof MutatingRule) {
                    $value = $rule->mutate($value, $ruleContext);
                    continue;
                }
                // `nullable`: a null/empty value stops the remaining rules for this field.
                if ($rule instanceof Nullable && $rule->shouldStopValidation($value)) {
                    break;
                }
                if ($rule instanceof DatabaseAwareRule && !$rule->hasConnection()) {
                    $connection = $this->resolveConnection();
                    if ($connection !== null) {
                        $rule->setConnection($connection);
                    }
                    // No connection: the rule itself fails closed.
                }
                $msg = $rule->validate($value, $ruleContext);
                if ($msg !== null) {
                    $errors[$field][] = $msg;
                }
            }
            $this->filtered[$field] = $value;
        }
        return $errors;
    }

    /**
     * Return the last filtered/sanitized data seen during validate().
     * @return array<string, mixed>
     */
    public function filtered(): array
    {
        return $this->filtered;
    }

    /**
     * @param Rule[] $rules
     * @param array<string, mixed> $data
     */
    private function skippedBySometimes(string $field, array $rules, array $data): bool
    {
        foreach ($rules as $rule) {
            if ($rule instanceof Sometimes && $rule->shouldSkipValidation($field, $data)) {
                return true;
            }
        }

        return false;
    }

    private function resolveConnection(): ?Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        if ($this->context === null || !$this->context->hasContainer()) {
            return null;
        }

        $container = $this->context->getContainer();
        if (!$container->has(Connection::class)) {
            return null;
        }
        $connection = $container->get(Connection::class);

        return $this->connection = $connection instanceof Connection ? $connection : null;
    }
}
