<?php

namespace Glueful\Tasks;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\ConfigManager;

class DatabaseBackupTask
{
    /** @var array{backup_created: bool, backup_file: string, backup_size: int, old_backups_deleted: int, errors: string[]} */
    private array $stats = [
        'backup_created' => false,
        'backup_file' => '',
        'backup_size' => 0,
        'old_backups_deleted' => 0,
        'errors' => []
    ];

    /** @var array<string, mixed> */
    private array $config;
    private ?ApplicationContext $context;

    /**
     * @param array<string, mixed>|null $databaseConfig the `database` config; read from the
     *        context (or the static config) when null
     */
    public function __construct(?ApplicationContext $context = null, ?array $databaseConfig = null)
    {
        $this->context = $context;
        $this->config = $databaseConfig
            ?? ($context !== null
                ? (array) config($context, 'database', [])
                : (array) ConfigManager::get('database', []));
    }

    public function createBackup(): void
    {
        try {
            $backupDir = $this->backupDirectory();

            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $timestamp = date('Y-m-d_H-i-s');
            $filename = "backup_{$timestamp}.sql";
            $backupFile = $backupDir . '/' . $filename;

            if ($this->settings()['engine'] === 'sqlite') {
                $this->createSQLiteBackup($backupFile);
            } else {
                $this->runDump($this->dumpCommand($backupFile));
            }

            if (file_exists($backupFile)) {
                $this->stats['backup_created'] = true;
                $this->stats['backup_file'] = $filename;
                $backupSize = filesize($backupFile);
                $this->stats['backup_size'] = $backupSize === false ? 0 : $backupSize;
            }
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to create backup: " . $e->getMessage();
        }
    }

    /**
     * The active connection's settings, read from the stock shape: `engine` names the block
     * (`mysql`, `pgsql`, `sqlite`) whose `host`/`port`/`db`/`user`/`pass` describe it. SQLite's
     * file is its `primary` path.
     *
     * @return array{engine: string, host: string, port: int, database: string, username: string,
     *               password: string, sslmode: string}
     */
    private function settings(): array
    {
        $engine = (string) ($this->config['engine'] ?? 'sqlite');
        $block = (array) ($this->config[$engine] ?? []);

        return [
            'engine' => $engine,
            'host' => (string) ($block['host'] ?? '127.0.0.1'),
            'port' => (int) ($block['port'] ?? ($engine === 'pgsql' ? 5432 : 3306)),
            'database' => (string) ($engine === 'sqlite' ? ($block['primary'] ?? '') : ($block['db'] ?? '')),
            'username' => (string) ($block['user'] ?? ''),
            'password' => (string) ($block['pass'] ?? ''),
            'sslmode' => (string) ($block['sslmode'] ?? ''),
        ];
    }

    /**
     * The dump tool's argv and the environment it runs with. The password travels in the tool's
     * own environment variable (PGPASSWORD, MYSQL_PWD), never on the command line where the
     * process list would show it, and no shell is involved.
     *
     * @return array{command: list<string>, env: array<string, string>}
     */
    private function dumpCommand(string $backupFile): array
    {
        $db = $this->settings();

        if ($db['engine'] === 'pgsql') {
            $env = ['PGPASSWORD' => $db['password']];
            if ($db['sslmode'] !== '') {
                $env['PGSSLMODE'] = $db['sslmode'];
            }

            return [
                'command' => [
                    'pg_dump',
                    '--host=' . $db['host'],
                    '--port=' . $db['port'],
                    '--username=' . $db['username'],
                    '--no-password',
                    '--format=plain',
                    '--file=' . $backupFile,
                    $db['database'],
                ],
                'env' => $env,
            ];
        }

        if ($db['engine'] === 'mysql') {
            return [
                'command' => [
                    'mysqldump',
                    '--host=' . $db['host'],
                    '--port=' . $db['port'],
                    '--user=' . $db['username'],
                    '--single-transaction',
                    '--routines',
                    '--triggers',
                    '--result-file=' . $backupFile,
                    $db['database'],
                ],
                'env' => ['MYSQL_PWD' => $db['password']],
            ];
        }

        throw new \Exception("Unsupported database engine: {$db['engine']}");
    }

    /** @param array{command: list<string>, env: array<string, string>} $dump */
    private function runDump(array $dump): void
    {
        $env = array_merge(getenv(), $dump['env']);
        $process = proc_open($dump['command'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \Exception("Could not start {$dump['command'][0]}");
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($code !== 0) {
            throw new \Exception("{$dump['command'][0]} failed (exit {$code}): " . trim($output));
        }
    }

    private function createSQLiteBackup(string $backupFile): void
    {
        $databaseFile = $this->settings()['database'];

        if ($databaseFile === '' || !file_exists($databaseFile)) {
            throw new \Exception("SQLite database file not found: {$databaseFile}");
        }

        if (!copy($databaseFile, $backupFile)) {
            throw new \Exception("Failed to copy SQLite database file");
        }
    }

    /** Where backups are written and pruned: `app.paths.backups`, else storage/backups. */
    private function backupDirectory(): string
    {
        $configured = $this->getConfig('app.paths.backups');

        return is_string($configured) && $configured !== ''
            ? $configured
            : $this->getBasePath('storage/backups');
    }

    public function cleanOldBackups(int $retentionDays): void
    {
        try {
            $backupDir = $this->backupDirectory();

            if (!is_dir($backupDir)) {
                return;
            }

            $cutoffTime = time() - ($retentionDays * 24 * 60 * 60);
            $files = glob($backupDir . '/backup_*.sql');
            if ($files === false) {
                $files = [];
            }

            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    if (unlink($file)) {
                        $this->stats['old_backups_deleted']++;
                    }
                }
            }
        } catch (\Exception $e) {
            $this->stats['errors'][] = "Failed to clean old backups: " . $e->getMessage();
        }
    }

    public function logResults(): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $message = sprintf(
            "[%s] Database backup %s:\n" .
            "- Backup created: %s\n" .
            "- Backup file: %s\n" .
            "- Backup size: %s\n" .
            "- Old backups deleted: %d\n",
            $timestamp,
            $this->stats['backup_created'] && $this->stats['errors'] === [] ? 'completed' : 'failed',
            $this->stats['backup_created'] ? 'Yes' : 'No',
            $this->stats['backup_file'],
            $this->formatBytes($this->stats['backup_size']),
            $this->stats['old_backups_deleted']
        );

        if (count($this->stats['errors']) > 0) {
            $message .= "Errors:\n- " . implode("\n- ", $this->stats['errors']) . "\n";
        }

        $logFile = $this->getBasePath('storage/logs/database-backup.log');
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents($logFile, $message . "\n", FILE_APPEND);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 2) . ' ' . $units[$i];
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }

    private function getBasePath(string $path = ''): string
    {
        if ($this->context !== null) {
            return base_path($this->context, $path);
        }

        $root = getcwd() ?: '.';
        if ($path === '') {
            return $root;
        }

        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{backup_created: bool, backup_file: string, backup_size: int,
     *               old_backups_deleted: int, errors: string[]}
     */
    public function handle(array $parameters = []): array
    {
        $retentionDays = $parameters['retention_days'] ?? 7;

        $this->createBackup();
        $this->cleanOldBackups($retentionDays);
        $this->logResults();

        return $this->stats;
    }
}
