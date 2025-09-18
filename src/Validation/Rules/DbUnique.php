<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\Rule;
use PDO;

final class DbUnique implements Rule
{
    public function __construct(private PDO $pdo, private string $table, private string $column)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string
    {
        if ($value === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->table} WHERE {$this->column} = :v LIMIT 1");
        $stmt->execute(['v' => $value]);
        return $stmt->fetchColumn() ? 'Value must be unique.' : null;
    }
}
