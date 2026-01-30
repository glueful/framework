<?php

declare(strict_types=1);

namespace Glueful\Repository;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

/**
 * Generic Resource Repository
 *
 * Provides generic resource operations using the unified repository pattern.
 * Can be used for any table/resource that doesn't need specific business logic.
 */
class ResourceRepository extends BaseRepository
{
    protected string $tableName;

    public function __construct(string $tableName, ?Connection $connection = null, ?ApplicationContext $context = null)
    {
        $this->tableName = $tableName;
        parent::__construct($connection, $context);
    }

    /**
     * {@inheritdoc}
     */
    public function getTableName(): string
    {
        return $this->tableName;
    }
}
