<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Scaffold;

use Glueful\Console\BaseCommand;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scaffold Model Command
 *
 * Creates ORM model classes with various options:
 * - Generate model with soft deletes
 * - Create associated migration file
 * - Generate model factory
 * - Include common traits
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:model',
    description: 'Scaffold an ORM model class'
)]
class ModelCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setDescription('Scaffold an ORM model class')
            ->setHelp('This command scaffolds a model class with optional migration, factory, and traits.')
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the model to generate (e.g., User, Post, OrderItem)'
            )
            ->addOption(
                'migration',
                'm',
                InputOption::VALUE_NONE,
                'Create a migration file for the model'
            )
            ->addOption(
                'soft-deletes',
                's',
                InputOption::VALUE_NONE,
                'Include the SoftDeletes trait'
            )
            ->addOption(
                'timestamps',
                't',
                InputOption::VALUE_NONE,
                'Include timestamps (enabled by default)'
            )
            ->addOption(
                'fillable',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of fillable attributes',
                ''
            )
            ->addOption(
                'table',
                null,
                InputOption::VALUE_OPTIONAL,
                'The database table name (defaults to snake_case plural of model name)',
                ''
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing files without confirmation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $modelName */
        $modelName = $input->getArgument('name');
        /** @var bool $migration */
        $migration = (bool) $input->getOption('migration');
        /** @var bool $softDeletes */
        $softDeletes = (bool) $input->getOption('soft-deletes');
        /** @var string|null $fillable */
        $fillable = $input->getOption('fillable');
        $fillable = is_string($fillable) ? $fillable : null;
        /** @var string|null $table */
        $table = $input->getOption('table');
        $table = is_string($table) ? $table : null;
        /** @var bool $force */
        $force = (bool) $input->getOption('force');

        // Normalize model name
        $modelName = $this->normalizeModelName($modelName);

        if (!$this->validateModelName($modelName)) {
            return self::FAILURE;
        }

        try {
            $this->info("Scaffolding model: {$modelName}");

            // Generate the model file
            $filePath = $this->createModel($modelName, $softDeletes, $fillable, $table, $force);

            $this->success("Model scaffolded successfully!");
            $this->table(['Property', 'Value'], [
                ['File Path', $filePath],
                ['Table Name', $this->getTableName($modelName, $table)],
                ['Soft Deletes', $softDeletes ? 'Yes' : 'No'],
                ['Fillable', $fillable ?: '(none specified)'],
            ]);

            // Create migration if requested
            if ($migration) {
                $this->createMigration($modelName, $softDeletes);
            }

            $this->displayNextSteps($modelName);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to scaffold model: " . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function normalizeModelName(string $name): string
    {
        // Remove any file extension
        $name = preg_replace('/\.php$/', '', $name) ?? $name;

        // Ensure PascalCase
        $name = str_replace(['-', '_'], ' ', $name);
        $name = str_replace(' ', '', ucwords($name));

        return ucfirst($name);
    }

    private function validateModelName(string $name): bool
    {
        if ($name === '') {
            $this->error('Model name cannot be empty.');
            return false;
        }

        if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Model name must be in PascalCase (e.g., User, BlogPost, OrderItem).');
            $this->tip('Example: User, Post, OrderItem, ProductCategory');
            return false;
        }

        // Check for reserved names
        $reserved = ['Model', 'Builder', 'Collection', 'Scope'];
        if (in_array($name, $reserved, true)) {
            $this->error("'{$name}' is a reserved class name. Please choose a different name.");
            return false;
        }

        return true;
    }

    private function createModel(
        string $modelName,
        bool $softDeletes,
        ?string $fillable,
        ?string $table,
        bool $force
    ): string {
        $modelsDir = base_path('api/Models');
        $fileName = $modelName . '.php';
        $filePath = $modelsDir . '/' . $fileName;

        // Ensure directory exists
        if (!is_dir($modelsDir)) {
            @mkdir($modelsDir, 0755, true);
        }

        // Check for existing files
        $disk = $this->makeStorage($modelsDir);
        if ($disk->fileExists($fileName) && !$force) {
            if (!$this->confirm("Model file already exists: {$fileName}. Overwrite?", false)) {
                throw new \Exception('Model scaffolding cancelled.');
            }
        }

        $this->info('Generating model content...');
        $content = $this->generateModelContent($modelName, $softDeletes, $fillable, $table);

        $this->info('Writing model file...');
        $disk->write($fileName, $content);

        return $filePath;
    }

    private function generateModelContent(
        string $modelName,
        bool $softDeletes,
        ?string $fillable,
        ?string $table
    ): string {
        $namespace = 'App\\Models';
        $tableName = $this->getTableName($modelName, $table);
        $fillableArray = $this->parseFillable($fillable);

        // Build use statements
        $useStatements = ["use Glueful\\Database\\ORM\\Model;"];

        if ($softDeletes) {
            $useStatements[] = "use Glueful\\Database\\ORM\\Concerns\\SoftDeletes;";
        }

        $useBlock = implode("\n", $useStatements);

        // Build traits
        $traits = [];
        if ($softDeletes) {
            $traits[] = 'SoftDeletes';
        }

        $traitsBlock = '';
        if (count($traits) > 0) {
            $traitsBlock = "\n    use " . implode(', ', $traits) . ";\n";
        }

        // Build fillable array
        $fillableBlock = '';
        if (count($fillableArray) > 0) {
            $fillableItems = array_map(fn($item) => "        '{$item}'", $fillableArray);
            $fillableBlock = "\n    /**\n"
                . "     * The attributes that are mass assignable\n"
                . "     *\n"
                . "     * @var array<string>\n"
                . "     */\n"
                . "    protected array \$fillable = [\n"
                . implode(",\n", $fillableItems) . ",\n    ];\n";
        }

        // Build casts array
        $castsBlock = '';
        if ($softDeletes) {
            $castsBlock = "\n    /**\n"
                . "     * The attributes that should be cast\n"
                . "     *\n"
                . "     * @var array<string, string>\n"
                . "     */\n"
                . "    protected array \$casts = [\n"
                . "        'deleted_at' => 'datetime',\n"
                . "    ];\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

{$useBlock}

/**
 * {$modelName} Model
 *
 * Represents a {$this->humanize($modelName)} in the database.
 *
 * @package {$namespace}
 */
class {$modelName} extends Model
{{$traitsBlock}
    /**
     * The table associated with the model
     *
     * @var string
     */
    protected string \$table = '{$tableName}';

    /**
     * The primary key for the model
     *
     * @var string
     */
    protected string \$primaryKey = 'id';

    /**
     * Indicates if the model uses timestamps
     *
     * @var bool
     */
    protected bool \$timestamps = true;
{$fillableBlock}{$castsBlock}
    // TODO: Define your model relationships
    //
    // Example HasMany relationship:
    // public function posts(): HasMany
    // {
    //     return \$this->hasMany(Post::class);
    // }
    //
    // Example BelongsTo relationship:
    // public function user(): BelongsTo
    // {
    //     return \$this->belongsTo(User::class);
    // }
    //
    // Example BelongsToMany relationship:
    // public function roles(): BelongsToMany
    // {
    //     return \$this->belongsToMany(Role::class);
    // }

    // TODO: Define query scopes
    //
    // Example local scope:
    // public function scopeActive(Builder \$query): Builder
    // {
    //     return \$query->where('status', 'active');
    // }

    // TODO: Define accessors and mutators
    //
    // Example using Attribute class:
    // protected function fullName(): Attribute
    // {
    //     return Attribute::make(
    //         get: fn () => \$this->first_name . ' ' . \$this->last_name,
    //     );
    // }
}
PHP;
    }

    private function getTableName(string $modelName, ?string $table): string
    {
        if ($table !== null && $table !== '') {
            return $table;
        }

        // Convert PascalCase to snake_case and pluralize
        $snakeCase = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $modelName) ?? $modelName);

        return $this->pluralize($snakeCase);
    }

    private function pluralize(string $word): string
    {
        // Simple pluralization rules
        $irregulars = [
            'child' => 'children',
            'person' => 'people',
            'man' => 'men',
            'woman' => 'women',
            'foot' => 'feet',
            'tooth' => 'teeth',
            'goose' => 'geese',
            'mouse' => 'mice',
        ];

        if (isset($irregulars[$word])) {
            return $irregulars[$word];
        }

        // Words ending in s, x, z, ch, sh
        if (preg_match('/(s|x|z|ch|sh)$/', $word)) {
            return $word . 'es';
        }

        // Words ending in y (preceded by consonant)
        if (preg_match('/[^aeiou]y$/', $word)) {
            return substr($word, 0, -1) . 'ies';
        }

        // Words ending in f or fe
        if (preg_match('/f$/', $word)) {
            return substr($word, 0, -1) . 'ves';
        }

        if (preg_match('/fe$/', $word)) {
            return substr($word, 0, -2) . 'ves';
        }

        return $word . 's';
    }

    /**
     * @return array<string>
     */
    private function parseFillable(?string $fillable): array
    {
        if ($fillable === null || $fillable === '') {
            return [];
        }

        return array_map('trim', explode(',', $fillable));
    }

    private function humanize(string $name): string
    {
        // Convert PascalCase to words
        $words = preg_replace('/(?<!^)[A-Z]/', ' $0', $name) ?? $name;
        return strtolower($words);
    }

    private function createMigration(string $modelName, bool $softDeletes): void
    {
        $tableName = $this->getTableName($modelName, null);
        $timestamp = date('Y_m_d_His');
        $migrationName = "create_{$tableName}_table";
        $fileName = "{$timestamp}_{$migrationName}.php";

        $migrationsDir = base_path('migrations');

        // Ensure directory exists
        if (!is_dir($migrationsDir)) {
            @mkdir($migrationsDir, 0755, true);
        }

        $disk = $this->makeStorage($migrationsDir);

        $this->info("Creating migration: {$fileName}");

        $content = $this->generateMigrationContent($tableName, $softDeletes);
        $disk->write($fileName, $content);

        $this->success("Migration created: migrations/{$fileName}");
    }

    private function generateMigrationContent(string $tableName, bool $softDeletes): string
    {
        $softDeleteColumn = $softDeletes ? "\n            \$table->timestamp('deleted_at')->nullable();" : '';

        return <<<PHP
<?php

declare(strict_types=1);

use Glueful\Database\\Migrations\\Migration;
use Glueful\\Database\\Schema\\Blueprint;
use Glueful\\Database\\Schema\\SchemaBuilder;

return new class extends Migration
{
    /**
     * Run the migrations
     *
     * @param SchemaBuilder \$schema
     * @return void
     */
    public function up(SchemaBuilder \$schema): void
    {
        \$schema->create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            // TODO: Add your columns here
            // \$table->string('name');
            // \$table->string('email')->unique();
            // \$table->text('description')->nullable();
            \$table->timestamps();{$softDeleteColumn}
        });
    }

    /**
     * Reverse the migrations
     *
     * @param SchemaBuilder \$schema
     * @return void
     */
    public function down(SchemaBuilder \$schema): void
    {
        \$schema->dropIfExists('{$tableName}');
    }
};
PHP;
    }

    private function displayNextSteps(string $modelName): void
    {
        $this->line('');
        $this->info('Next steps:');
        $this->line("1. Define fillable attributes in your model");
        $this->line("2. Add relationships (hasMany, belongsTo, belongsToMany, etc.)");
        $this->line("3. Define query scopes for common queries");
        $this->line("4. Add accessors/mutators using the Attribute class");
        $this->line("5. Run migrations if you created one: php glueful migrate:run");
        $this->line('');
        $this->info('Example usage:');
        $this->line("  // Find by ID");
        $this->line("  \${$this->camelCase($modelName)} = {$modelName}::find(1);");
        $this->line('');
        $this->line("  // Query builder");
        $this->line("  \$items = {$modelName}::where('status', 'active')->get();");
        $this->line('');
        $this->line("  // Create new record");
        $this->line("  \${$this->camelCase($modelName)} = {$modelName}::create(['name' => 'Example']);");
        $this->line('');
    }

    private function camelCase(string $value): string
    {
        return lcfirst($value);
    }

    private function makeStorage(string $root): \League\Flysystem\FilesystemOperator
    {
        $cfg = [
            'default' => 'scaffold',
            'disks' => [
                'scaffold' => [
                    'driver' => 'local',
                    'root' => $root,
                    'visibility' => 'private',
                ],
            ],
        ];
        $sm = new StorageManager($cfg, new PathGuard());
        return $sm->disk('scaffold');
    }
}
