<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Scaffold;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scaffold Test Command
 *
 * Generates a new test class for unit or feature testing.
 *
 * @package Glueful\Console\Commands\Scaffold
 */
#[AsCommand(
    name: 'scaffold:test',
    description: 'Scaffold a new test class'
)]
class TestCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setDescription('Scaffold a new test class')
            ->setHelp($this->getDetailedHelp())
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the test class (e.g., UserServiceTest)'
            )
            ->addOption(
                'unit',
                'u',
                InputOption::VALUE_NONE,
                'Generate a unit test (default)'
            )
            ->addOption(
                'feature',
                null,
                InputOption::VALUE_NONE,
                'Generate a feature/integration test'
            )
            ->addOption(
                'class',
                'c',
                InputOption::VALUE_OPTIONAL,
                'The class being tested (for unit tests)',
                null
            )
            ->addOption(
                'methods',
                'm',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated test methods to generate',
                null
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing file if it exists'
            )
            ->addOption(
                'path',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Custom path for the test file',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $name */
        $name = $input->getArgument('name');
        /** @var bool $force */
        $force = (bool) $input->getOption('force');
        /** @var string|null $customPath */
        $customPath = $input->getOption('path');
        $customPath = is_string($customPath) ? $customPath : null;

        // Determine test type
        $isFeature = (bool) $input->getOption('feature');
        // Default to unit if neither is specified
        $testType = $isFeature ? 'feature' : 'unit';

        // Get test options
        /** @var string|null $classOption */
        $classOption = $input->getOption('class');
        $testedClass = is_string($classOption) ? $classOption : null;

        /** @var string|null $methodsOption */
        $methodsOption = $input->getOption('methods');
        $methods = $methodsOption !== null ? array_filter(array_map('trim', explode(',', $methodsOption))) : [];

        // Normalize the name
        $name = $this->normalizeTestName($name);

        // Validate the name
        if (!$this->isValidClassName($name)) {
            $this->error("Invalid test name: {$name}");
            $this->line('Class names must be PascalCase and contain only letters and numbers.');
            return self::FAILURE;
        }

        // Determine the file path
        $basePath = $customPath ?? $this->getDefaultTestPath($testType);
        $filePath = $this->buildFilePath($basePath, $name);

        // Check if file exists
        if (file_exists($filePath) && !$force) {
            $this->error("Test already exists: {$filePath}");
            $this->line('Use --force to overwrite.');
            return self::FAILURE;
        }

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true)) {
                $this->error("Failed to create directory: {$directory}");
                return self::FAILURE;
            }
        }

        // Generate the class content
        $content = $this->generateTestClass($name, $testType, $testedClass, $methods);

        // Write the file
        if (file_put_contents($filePath, $content) === false) {
            $this->error("Failed to write file: {$filePath}");
            return self::FAILURE;
        }

        $this->success("Test scaffolded successfully!");
        $this->line("File: {$filePath}");
        $this->line('');

        $className = $this->extractClassName($name);

        $this->table(['Property', 'Value'], [
            ['Test Type', ucfirst($testType)],
            ['Class', $className],
            ['Methods', $methods !== [] ? count($methods) : '1 (example)'],
        ]);

        $this->line('');
        $this->info('Next steps:');
        $this->line('1. Implement your test methods');
        $this->line('2. Set up any required fixtures in setUp()');
        $this->line('3. Run the test with PHPUnit');
        $this->line('');
        $this->line('Run tests:');
        $this->line("  vendor/bin/phpunit --filter=\"{$className}\"");
        $this->line('');
        $this->line('Or run all tests:');
        $this->line('  composer test');

        return self::SUCCESS;
    }

    /**
     * Normalize the test name
     */
    private function normalizeTestName(string $name): string
    {
        // Remove .php extension if provided
        $name = preg_replace('/\.php$/', '', $name) ?? $name;

        // Ensure PascalCase for each path segment
        $parts = explode('/', str_replace('\\', '/', $name));
        $parts = array_map(fn($part) => ucfirst($part), $parts);
        $name = implode('/', $parts);

        // Add Test suffix if not present
        if (!str_ends_with($name, 'Test')) {
            $name .= 'Test';
        }

        return $name;
    }

    /**
     * Validate the class name
     */
    private function isValidClassName(string $name): bool
    {
        $parts = explode('/', str_replace('\\', '/', $name));

        foreach ($parts as $part) {
            if (!preg_match('/^[A-Z][a-zA-Z0-9]*$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the default path for test files
     */
    private function getDefaultTestPath(string $type): string
    {
        $basePath = base_path('tests');

        if ($type === 'feature') {
            return $basePath . '/Feature';
        }

        return $basePath . '/Unit';
    }

    /**
     * Build the full file path from the base path and name
     */
    private function buildFilePath(string $basePath, string $name): string
    {
        // Handle nested namespaces (e.g., Services/UserServiceTest)
        $name = str_replace('\\', '/', $name);

        return rtrim($basePath, '/') . '/' . $name . '.php';
    }

    /**
     * Extract class name from potentially nested path
     */
    private function extractClassName(string $name): string
    {
        $parts = explode('/', str_replace('\\', '/', $name));
        return end($parts);
    }

    /**
     * Generate the test class content
     *
     * @param string $name Test name
     * @param string $type Test type (unit or feature)
     * @param string|null $testedClass The class being tested
     * @param array<int, string> $methods Test methods to generate
     */
    private function generateTestClass(
        string $name,
        string $type,
        ?string $testedClass,
        array $methods
    ): string {
        if ($type === 'feature') {
            return $this->generateFeatureTest($name, $methods);
        }

        return $this->generateUnitTest($name, $testedClass, $methods);
    }

    /**
     * Generate a unit test class
     *
     * @param string $name Test name
     * @param string|null $testedClass The class being tested
     * @param array<int, string> $methods Test methods
     */
    private function generateUnitTest(string $name, ?string $testedClass, array $methods): string
    {
        $className = $this->extractClassName($name);
        $namespace = $this->buildNamespace($name, 'unit');

        $testedClassUse = '';
        $testedClassProperty = '';
        $testedClassSetup = '';

        if ($testedClass !== null) {
            $shortClass = $this->getShortClassName($testedClass);
            $testedClassUse = "use {$testedClass};\n";
            $testedClassProperty = "\n    private {$shortClass} \$instance;\n";
            $testedClassSetup = "\n        \$this->instance = new {$shortClass}();";
        }

        $testMethods = $this->generateTestMethods($methods, 'unit');

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use PHPUnit\Framework\TestCase;
{$testedClassUse}
/**
 * {$className}
 *
 * Unit tests for testing isolated components.
 *
 * @package {$namespace}
 */
class {$className} extends TestCase
{{$testedClassProperty}
    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();{$testedClassSetup}

        // Set up test fixtures here
    }

    /**
     * Tear down test fixtures
     */
    protected function tearDown(): void
    {
        // Clean up after each test

        parent::tearDown();
    }
{$testMethods}}

PHP;
    }

    /**
     * Generate a feature test class
     *
     * @param string $name Test name
     * @param array<int, string> $methods Test methods
     */
    private function generateFeatureTest(string $name, array $methods): string
    {
        $className = $this->extractClassName($name);
        $namespace = $this->buildNamespace($name, 'feature');

        $testMethods = $this->generateTestMethods($methods, 'feature');

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace};

use PHPUnit\Framework\TestCase;

/**
 * {$className}
 *
 * Feature/integration tests for testing complete workflows.
 *
 * @package {$namespace}
 */
class {$className} extends TestCase
{
    /**
     * Set up test fixtures
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Set up test fixtures here
        // Example: Initialize database, mock services, etc.
    }

    /**
     * Tear down test fixtures
     */
    protected function tearDown(): void
    {
        // Clean up after each test
        // Example: Reset database, clear cache, etc.

        parent::tearDown();
    }
{$testMethods}}

PHP;
    }

    /**
     * Generate test methods
     *
     * @param array<int, string> $methods Method names
     * @param string $type Test type
     */
    private function generateTestMethods(array $methods, string $type): string
    {
        if ($methods === []) {
            // Generate a single example method
            if ($type === 'feature') {
                return $this->generateFeatureExampleMethod();
            }
            return $this->generateUnitExampleMethod();
        }

        $result = '';
        foreach ($methods as $method) {
            $methodName = $this->normalizeMethodName($method);
            $result .= $this->generateTestMethod($methodName, $type);
        }

        return $result;
    }

    /**
     * Generate a single test method
     *
     * @param string $methodName Test method name
     * @param string $type Test type (reserved for future differentiation)
     */
    private function generateTestMethod(string $methodName, string $type): string
    {
        unset($type); // Reserved for future use to differentiate test method generation
        $description = $this->methodNameToDescription($methodName);

        return <<<PHP

    /**
     * {$description}
     */
    public function {$methodName}(): void
    {
        // Arrange - set up test data
        // \$expected = 'expected value';

        // Act - perform the action
        // \$actual = \$this->instance->someMethod();

        // Assert - verify the result
        \$this->assertTrue(true);
    }

PHP;
    }

    /**
     * Generate example method for unit tests
     */
    private function generateUnitExampleMethod(): string
    {
        return <<<'PHP'

    /**
     * Test example functionality
     */
    public function testExample(): void
    {
        // Arrange - set up test data
        $expected = true;

        // Act - perform the action
        $actual = true;

        // Assert - verify the result
        $this->assertEquals($expected, $actual);
    }

PHP;
    }

    /**
     * Generate example method for feature tests
     */
    private function generateFeatureExampleMethod(): string
    {
        return <<<'PHP'

    /**
     * Test example workflow
     */
    public function testExample(): void
    {
        // Arrange - set up test environment
        // $this->seedDatabase();
        // $this->authenticateUser();

        // Act - perform the workflow
        // $response = $this->get('/api/endpoint');

        // Assert - verify the outcome
        // $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue(true);
    }

PHP;
    }

    /**
     * Normalize method name to follow PHPUnit conventions
     */
    private function normalizeMethodName(string $method): string
    {
        // Remove 'test' prefix if present
        if (str_starts_with(strtolower($method), 'test')) {
            $method = substr($method, 4);
        }

        // Convert to camelCase and add test prefix
        $method = str_replace(['-', '_'], ' ', $method);
        $method = ucwords($method);
        $method = str_replace(' ', '', $method);

        return 'test' . $method;
    }

    /**
     * Convert method name to human-readable description
     */
    private function methodNameToDescription(string $methodName): string
    {
        // Remove 'test' prefix
        $description = preg_replace('/^test/', '', $methodName) ?? $methodName;

        // Convert camelCase to words
        $description = preg_replace('/([a-z])([A-Z])/', '$1 $2', $description) ?? $description;

        return 'Test ' . strtolower($description);
    }

    /**
     * Get short class name from fully qualified name
     */
    private function getShortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }

    /**
     * Build the namespace from the class name
     */
    private function buildNamespace(string $name, string $type): string
    {
        // Check if we're in an app or framework context
        if (is_dir(base_path('app'))) {
            $baseNamespace = $type === 'feature' ? 'App\\Tests\\Feature' : 'App\\Tests\\Unit';
        } else {
            $baseNamespace = $type === 'feature' ? 'Glueful\\Tests\\Feature' : 'Glueful\\Tests\\Unit';
        }

        // Handle nested paths
        $parts = explode('/', str_replace('\\', '/', $name));

        // Remove the class name from parts
        array_pop($parts);

        if ($parts !== []) {
            return $baseNamespace . '\\' . implode('\\', $parts);
        }

        return $baseNamespace;
    }

    /**
     * Get detailed help text
     */
    private function getDetailedHelp(): string
    {
        return <<<HELP
Scaffold a new test class for unit or feature testing.

Test classes allow you to verify your application's behavior through
automated testing with PHPUnit.

Examples:
  php glueful scaffold:test UserServiceTest
  php glueful scaffold:test UserApiTest --feature
  php glueful scaffold:test PaymentTest --methods=testCharge,testRefund,testCancel
  php glueful scaffold:test Services/UserServiceTest --class=App\\Services\\UserService

The generated class will be placed in tests/Unit/ or tests/Feature/.

Options:
  --unit      Generate a unit test (default)
  --feature   Generate a feature/integration test
  --class     The fully-qualified class being tested (for unit tests)
  --methods   Comma-separated test method names to generate

Test Types:
  Unit Tests:
    - Test isolated components in isolation
    - Mock external dependencies
    - Fast execution
    - Located in tests/Unit/

  Feature Tests:
    - Test complete workflows
    - May use real database, HTTP requests
    - Test integration between components
    - Located in tests/Feature/

Running Tests:
  # Run all tests
  composer test

  # Run a specific test class
  vendor/bin/phpunit --filter="UserServiceTest"

  # Run a specific test method
  vendor/bin/phpunit --filter="testUserCanLogin"

  # Run with coverage
  composer run test:coverage
HELP;
    }
}
