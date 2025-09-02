<?php

declare(strict_types=1);

namespace Glueful\Lock\Store;

use Symfony\Component\Lock\Store\PdoStore;
use Glueful\Database\DatabaseInterface;

class DatabaseLockStore extends PdoStore
{
    private DatabaseInterface $database;
    /** @var array{table: string, id_col: string, token_col: string, expiration_col: string} */
    private array $options;

    /**
     * @param array<string, string> $options
     */
    public function __construct(DatabaseInterface $database, array $options = [])
    {
        $this->database = $database;
        $this->options = array_merge([
            'table' => 'locks',
            'id_col' => 'key_id',
            'token_col' => 'token',
            'expiration_col' => 'expiration'
        ], $options);

        $pdo = $database->getPDO();

        parent::__construct($pdo, $this->options);
    }

    public function getDatabase(): DatabaseInterface
    {
        return $this->database;
    }

    /**
     * @return array{table: string, id_col: string, token_col: string, expiration_col: string}
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
