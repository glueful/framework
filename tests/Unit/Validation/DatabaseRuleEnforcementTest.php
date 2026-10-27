<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Validation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Attributes\Validate;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\FormRequest;
use Glueful\Validation\Middleware\ValidationMiddleware;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\Rules\DbUnique;
use Glueful\Validation\Rules\Exists;
use Glueful\Validation\Support\RuleParser;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class UniqueEmailFixture implements RequestData
{
    public function __construct(
        #[Rule('required|unique:users,email')] public string $email,
    ) {
    }
}

final class UniqueEmailFormRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required|unique:users,email'];
    }
}

final class ValidateAttributeController
{
    #[Validate(['email' => 'required|unique:users,email'])]
    public function store(): void
    {
    }
}

/**
 * `unique:` / `exists:` must actually hit the database on every validation path
 * (Validator, #[Rule] DTO hydration, FormRequest, #[Validate]) and must fail
 * closed — never silently pass — when no connection is available.
 */
final class DatabaseRuleEnforcementTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => ':memory:'],
            'pooling' => ['enabled' => false],
        ]);
        $pdo = $this->connection->getPDO();
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
        $pdo->exec("INSERT INTO users (id, email) VALUES (1, 'taken@example.com')");
    }

    private function context(): ApplicationContext
    {
        $context = new ApplicationContext(sys_get_temp_dir());
        $connection = $this->connection;
        $context->setContainer(new class ($connection, $context) implements ContainerInterface {
            public function __construct(private Connection $connection, private ApplicationContext $context)
            {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    Connection::class, 'database' => $this->connection,
                    ApplicationContext::class => $this->context,
                    default => throw new \RuntimeException("No entry {$id}"),
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [Connection::class, 'database', ApplicationContext::class], true);
            }
        });

        return $context;
    }

    /** @param array<string, string> $rules */
    private function parse(array $rules): array
    {
        return (new RuleParser())->parse($rules);
    }

    public function testUniqueStringRuleRejectsTakenValueWhenValidatorHasConnection(): void
    {
        $validator = new Validator($this->parse(['email' => 'unique:users,email']), connection: $this->connection);

        $errors = $validator->validate(['email' => 'taken@example.com']);

        self::assertArrayHasKey('email', $errors);
    }

    public function testUniqueStringRulePassesFreeValue(): void
    {
        $validator = new Validator($this->parse(['email' => 'unique:users,email']), connection: $this->connection);

        self::assertSame([], $validator->validate(['email' => 'free@example.com']));
    }

    public function testUniqueExceptIdExcludesCurrentRow(): void
    {
        $validator = new Validator($this->parse(['email' => 'unique:users,email,1']), connection: $this->connection);

        self::assertSame([], $validator->validate(['email' => 'taken@example.com']));
    }

    public function testExistsStringRuleRejectsMissingValue(): void
    {
        $validator = new Validator($this->parse(['email' => 'exists:users,email']), connection: $this->connection);

        self::assertArrayHasKey('email', $validator->validate(['email' => 'missing@example.com']));
        self::assertSame([], $validator->validate(['email' => 'taken@example.com']));
    }

    public function testValidatorResolvesConnectionFromApplicationContext(): void
    {
        $validator = new Validator($this->parse(['email' => 'unique:users,email']), $this->context());

        self::assertArrayHasKey('email', $validator->validate(['email' => 'taken@example.com']));
    }

    public function testUniqueFailsClosedWithoutAnyConnection(): void
    {
        $validator = new Validator($this->parse(['email' => 'unique:users,email']));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('database connection');
        $validator->validate(['email' => 'free@example.com']);
    }

    public function testExistsFailsClosedWithoutAnyConnection(): void
    {
        $this->expectException(\LogicException::class);
        (new Exists('users', 'email'))->validate('taken@example.com', ['field' => 'email']);
    }

    public function testDirectlyConstructedRuleAcceptsConnection(): void
    {
        $rule = new DbUnique('users', 'email');
        $rule->setConnection($this->connection);

        self::assertNotNull($rule->validate('taken@example.com', ['field' => 'email']));
    }

    public function testRequestDataHydratorEnforcesUniqueViaContext(): void
    {
        $hydrator = new RequestDataHydrator(null, $this->context());

        try {
            $hydrator->hydrate(UniqueEmailFixture::class, ['email' => 'taken@example.com']);
            self::fail('Expected ValidationException for a taken email');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('email', $e->errors());
        }

        $dto = $hydrator->hydrate(UniqueEmailFixture::class, ['email' => 'free@example.com']);
        self::assertInstanceOf(UniqueEmailFixture::class, $dto);
    }

    public function testFormRequestEnforcesUniqueViaContext(): void
    {
        $form = new UniqueEmailFormRequest(new Request([], ['email' => 'taken@example.com']));
        $form->setContext($this->context());

        $this->expectException(ValidationException::class);
        $form->validate();
    }

    public function testValidateAttributeMiddlewareEnforcesUniqueViaContext(): void
    {
        $middleware = new ValidationMiddleware($this->context());
        $request = new Request([], ['email' => 'taken@example.com']);
        $request->attributes->set('_controller', ValidateAttributeController::class);
        $request->attributes->set('_controller_method', 'store');

        $response = $middleware->handle($request, static fn () => new Response('ok', 200));

        self::assertSame(422, $response->getStatusCode());
    }
}
