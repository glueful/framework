<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Http\Responses\CollectionResponse;
use Glueful\Http\Responses\PaginatedResponse;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\ResponseStatus;
use Glueful\Routing\Route;
use Glueful\Routing\Router;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Attributes\Validate;
use Glueful\Validation\Contracts\RequestData;

/**
 * Code-first OpenAPI path generator.
 *
 * Derives OpenAPI operations directly from the live {@see Router} route table
 * rather than from PHPDoc/JSON fragments. Each registered {@see Route} becomes a
 * single operation: security is computed from the route's middleware (via the
 * {@see SecuritySchemeRegistry}), path parameters from its param/constraint maps,
 * and a minimal-but-valid response set from its rate-limit/scope configuration.
 *
 * The result is a plain OpenAPI `paths` map that callers merge into a spec via
 * {@see DocGenerator::mergePaths()}. This class deliberately does not know about
 * schemas or prose descriptions — those are a later phase.
 */
final class RouteReflectionDocGenerator
{
    private OperationIdGenerator $operationIds;

    public function __construct(
        private readonly SecuritySchemeRegistry $registry,
        private readonly ?ApplicationContext $context = null,
    ) {
        $this->operationIds = new OperationIdGenerator();
    }

    /**
     * Build an OpenAPI `paths` map from every route registered on the router.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function generate(Router $router): array
    {
        // Reset the operationId counters so calling generate() more than once on
        // the same instance does not carry collision suffixes across runs.
        $this->operationIds = new OperationIdGenerator();

        $paths = [];

        foreach ($this->collectRoutes($router) as $route) {
            if (!$this->shouldInclude($route)) {
                continue;
            }

            $path = $route->getPath();
            $verb = strtolower($route->getMethod());
            $paths[$path][$verb] = $this->buildOperation($route);
        }

        return $paths;
    }

    /**
     * Collect every Route object from the static and dynamic route tables.
     *
     * @return list<Route>
     */
    private function collectRoutes(Router $router): array
    {
        $routes = array_values($router->getStaticRoutes());

        foreach ($router->getDynamicRoutes() as $bucket) {
            foreach ($bucket as $route) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    /**
     * Build a single OpenAPI operation object for a route.
     *
     * @return array<string, mixed>
     */
    private function buildOperation(Route $route): array
    {
        $method = $route->getMethod();
        $path = $route->getPath();
        $name = $route->getName();

        $operationId = $this->operationIds->register(
            ($name !== null && $name !== '')
                ? $this->operationIds->fromRouteName($name)
                : $this->operationIds->fromMethodAndPath($method, $path)
        );

        $security = $this->buildSecurity($route);
        $isSecured = $security !== [];
        $rateLimited = $route->getRateLimitConfig() !== [];

        $apiOperation = $this->apiOperationFor($route->getHandler());

        $summary = $this->deriveSummary($route);
        $tags = [$this->deriveTag($path)];

        if ($apiOperation !== null) {
            if ($apiOperation->summary !== '') {
                $summary = $apiOperation->summary;
            }
            if ($apiOperation->tags !== []) {
                $tags = $apiOperation->tags;
            }
            if ($apiOperation->operationId !== null) {
                $operationId = $apiOperation->operationId;
            }
        }

        $operation = [
            'operationId' => $operationId,
            'summary' => $summary,
            'tags' => $tags,
        ];

        $parameters = array_merge(
            $this->buildParameters($route),
            $this->buildFieldSelectionParameters($route),
        );
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($isSecured) {
            $operation['security'] = $security;
        }

        $scopeDescription = $this->buildScopeDescription($route);
        $attributeDescription = $apiOperation !== null ? $apiOperation->description : '';

        // Lead with the hand-authored description; append the auto scope prose so
        // both survive when present. Either alone falls back to existing behavior.
        if ($attributeDescription !== '' && $scopeDescription !== '') {
            $description = $attributeDescription . "\n\n" . $scopeDescription;
        } elseif ($attributeDescription !== '') {
            $description = $attributeDescription;
        } else {
            $description = $scopeDescription;
        }

        if ($description !== '') {
            $operation['description'] = $description;
        }

        if ($apiOperation !== null && $apiOperation->deprecated) {
            $operation['deprecated'] = true;
        }

        $requestBody = $this->buildRequestBody($route, $method);
        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        $defaults = $this->buildResponses($isSecured, $rateLimited);

        // Document the success response from a ResponseData return type, slotted
        // BETWEEN the defaults and the attribute overlay so an explicit
        // #[ApiResponse] (applied last) still wins at the same status.
        $inferred = $this->buildResponseFromReturnType($route->getHandler());
        if ($inferred !== null) {
            foreach ($inferred as $status => $response) {
                // A non-200 inferred success means there is no bare 200 at runtime —
                // drop the vestigial description-only 200 seeded by buildResponses().
                // An explicit #[ApiResponse(200)] is re-added later by the attribute overlay.
                if ((string) $status !== '200') {
                    unset($defaults['200']);
                }
                $defaults[(string) $status] = $response;
            }
        }

        // A handler with a typed RequestData parameter runs validation, so a 422
        // is possible — auto-emit it. Slotted before the attribute overlay so an
        // explicit #[ApiResponse(422)] (applied last) still wins.
        $handlerReflection = $this->handlerReflection($route->getHandler());
        if ($handlerReflection !== null && $this->findRequestDataParam($handlerReflection) !== null) {
            $defaults['422'] = $this->buildValidationErrorResponse();
        }

        $operation['responses'] = $this->mergeAttributeResponses($defaults, $route->getHandler());

        return $operation;
    }

    /**
     * Overlay `#[ApiResponse]` attribute responses on top of the default set.
     *
     * The generator's defaults (200, plus 401/403 when secured and 429 when
     * rate-limited) form the base; each `#[ApiResponse]` then REPLACES the entry
     * for its status. So an explicit `#[ApiResponse(200, …)]` supplants the
     * minimal default 200 while the auto 401/403/429 remain unless an attribute
     * overrides them, and a handler with no attributes is left exactly as today.
     *
     * @param  array<int|string, mixed> $defaults
     * @return array<int|string, mixed>
     */
    private function mergeAttributeResponses(array $defaults, mixed $handler): array
    {
        foreach ($this->buildResponsesFromAttributes($handler) as $status => $response) {
            $defaults[(string) $status] = $response;
        }

        return $defaults;
    }

    /**
     * Build response objects from a handler method's `#[ApiResponse]` attributes.
     *
     * The handler's {@see \ReflectionMethod} is resolved with the same guarded
     * resolver used for `#[Validate]`. Each attribute becomes
     * `{description, content: {<contentType>: {schema}}}`, where the body schema
     * is reflected from the typed DTO via {@see ClassSchemaReflector} and then
     * optionally wrapped as a collection and/or Glueful's success envelope. A
     * schema-less attribute yields a description-only response. Reflection and
     * attribute instantiation are fully guarded, so generation never throws.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildResponsesFromAttributes(mixed $handler): array
    {
        $reflection = $this->handlerReflection($handler);
        if ($reflection === null) {
            return [];
        }

        $responses = [];
        foreach ($reflection->getAttributes(ApiResponse::class) as $attribute) {
            try {
                $instance = $attribute->newInstance();
            } catch (\Throwable) {
                continue;
            }

            $responses[$instance->status] = $this->buildResponseObject($instance);
        }

        return $responses;
    }

    /**
     * Read the first `#[ApiOperation]` from a route handler, or null.
     *
     * Resolves the handler's {@see \ReflectionMethod} with the same guarded
     * resolver used for `#[Validate]`/`#[ApiResponse]`. Returns null when the
     * handler cannot be reflected, carries no `#[ApiOperation]`, or attribute
     * instantiation fails — so operation building never throws.
     */
    private function apiOperationFor(mixed $handler): ?ApiOperation
    {
        $reflection = $this->handlerReflection($handler);
        if ($reflection === null) {
            return null;
        }

        try {
            $attributes = $reflection->getAttributes(ApiOperation::class);
            if ($attributes === []) {
                return null;
            }

            return $attributes[0]->newInstance();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Assemble a single OpenAPI response object from one `#[ApiResponse]`.
     *
     * @return array<string, mixed>
     */
    private function buildResponseObject(ApiResponse $response): array
    {
        $object = [
            'description' => $response->description !== ''
                ? $response->description
                : self::reasonPhrase($response->status),
        ];

        if ($response->schema === null) {
            return $object;
        }

        $body = ClassSchemaReflector::toSchema($response->schema);

        if ($response->collection) {
            $body = ['type' => 'array', 'items' => $body];
        }

        if ($response->envelope) {
            $body = $this->wrapInEnvelope($body);
        }

        $object['content'] = [
            $response->contentType => ['schema' => $body],
        ];

        return $object;
    }

    /**
     * Wrap a body schema in Glueful's standard success envelope.
     *
     * Mirrors the runtime envelope (`{success, message, data}`) the router
     * applies to a returned {@see ResponseData}. Shared by the `#[ApiResponse]`
     * path and the return-type inference path so both render identically.
     *
     * @param  array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function wrapInEnvelope(array $schema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => $schema,
            ],
        ];
    }

    /**
     * Infer the success response from a handler's {@see ResponseData} return type.
     *
     * When the handler's return type is a single, non-builtin class implementing
     * {@see ResponseData}, document the success response as the envelope-wrapped
     * schema of that DTO (via {@see ClassSchemaReflector}) at the status declared
     * by `#[ResponseStatus]` (default 200). A nullable `?Dto` return type still
     * infers from `Dto`; union/intersection types, builtins, and non-ResponseData
     * classes yield null. Reflection is guarded so generation never throws — but
     * a malformed `#[ResponseStatus]` is allowed to surface (fail-loud), matching
     * the attribute's own load-time contract.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildResponseFromReturnType(mixed $handler): ?array
    {
        $reflection = $this->handlerReflection($handler);
        if ($reflection === null) {
            return null;
        }

        $returnType = $reflection->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $class = $returnType->getName();

        // Collection / pagination return types document a list of items; the
        // item class comes from the `@return Type<Item>` docblock when present.
        if ($class === CollectionResponse::class) {
            $status = $this->returnStatus($reflection);
            $schema = $this->wrapInEnvelope([
                'type' => 'array',
                'items' => $this->collectionItemSchema($reflection),
            ]);
            return [$status => [
                'description' => self::reasonPhrase($status),
                'content' => ['application/json' => ['schema' => $schema]],
            ]];
        }

        if ($class === PaginatedResponse::class) {
            // Pagination always renders at 200 (ApiResponse::paginated()).
            $schema = $this->wrapInPaginatedEnvelope($this->collectionItemSchema($reflection));
            return [200 => [
                'description' => self::reasonPhrase(200),
                'content' => ['application/json' => ['schema' => $schema]],
            ]];
        }

        if (!class_exists($class) || !is_subclass_of($class, ResponseData::class)) {
            return null;
        }

        $status = $this->returnStatus($reflection);
        $envelope = $this->wrapInEnvelope(ClassSchemaReflector::toSchema($class));

        return [$status => [
            'description' => self::reasonPhrase($status),
            'content' => ['application/json' => ['schema' => $envelope]],
        ]];
    }

    /**
     * The success status for a return-type-inferred response: the method's
     * #[ResponseStatus] value, or 200 when absent. The attribute constructor's
     * own validation is intentionally NOT caught — a malformed #[ResponseStatus]
     * must surface (consistent with the runtime fail-loud rule).
     */
    private function returnStatus(\ReflectionMethod $reflection): int
    {
        $statusAttributes = $reflection->getAttributes(ResponseStatus::class);
        if ($statusAttributes !== []) {
            return $statusAttributes[0]->newInstance()->status;
        }
        return 200;
    }

    /**
     * Wrap an item schema in Glueful's flat pagination envelope, mirroring the
     * runtime keys produced by {@see \Glueful\Http\Response::paginated()} — which
     * always emits every key, so all are marked `required`. (This is a DISTINCT
     * shape from {@see PaginationSchemaBuilder::envelopeFor()}, the Resource-path
     * envelope, which carries `from`/`to`/`links` and no `message`.)
     *
     * @param  array<string, mixed> $itemSchema
     * @return array<string, mixed>
     */
    private function wrapInPaginatedEnvelope(array $itemSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean'],
                'message' => ['type' => 'string'],
                'data' => ['type' => 'array', 'items' => $itemSchema],
                'current_page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
                'total' => ['type' => 'integer'],
                'total_pages' => ['type' => 'integer'],
                'has_next_page' => ['type' => 'boolean'],
                'has_previous_page' => ['type' => 'boolean'],
            ],
            'required' => [
                'success', 'message', 'data', 'current_page', 'per_page',
                'total', 'total_pages', 'has_next_page', 'has_previous_page',
            ],
        ];
    }

    /**
     * Resolve the item schema for a CollectionResponse/PaginatedResponse return.
     * The item class comes from a `@return Type<Item>` docblock; absent or
     * unresolvable, the items fall back to a generic object schema.
     *
     * @return array<string, mixed>
     */
    private function collectionItemSchema(\ReflectionMethod $reflection): array
    {
        $itemClass = $this->itemClassFromReturnDocblock($reflection);
        if ($itemClass !== null && class_exists($itemClass)) {
            return ClassSchemaReflector::toSchema($itemClass);
        }
        return ['type' => 'object'];
    }

    /**
     * Parse a `@return CollectionResponse<Item>` / `@return PaginatedResponse<Item>`
     * docblock and resolve the item class. A fully-qualified name is used as-is; a
     * short name is resolved against the method's declaring-class namespace (same
     * approach as ClassSchemaReflector for `@var` array item types). Returns null
     * when there is no such docblock or the name doesn't resolve. (Use-statement
     * aliases on the item type are not resolved — write the FQCN or a same-namespace
     * name, or document explicitly with #[ApiResponse].)
     */
    private function itemClassFromReturnDocblock(\ReflectionMethod $reflection): ?string
    {
        $doc = $reflection->getDocComment();
        if ($doc === false) {
            return null;
        }
        if (preg_match('/@return\s+\\\\?(?:CollectionResponse|PaginatedResponse)<\\\\?([\\\\\w]+)>/', $doc, $m) !== 1) {
            return null;
        }
        $name = $m[1];
        if (class_exists($name)) {
            return $name;
        }
        $fqcn = $reflection->getDeclaringClass()->getNamespaceName() . '\\' . $name;
        return class_exists($fqcn) ? $fqcn : null;
    }

    /**
     * Map an HTTP status code to a short reason phrase for a default description.
     */
    private static function reasonPhrase(int $status): string
    {
        return match ($status) {
            200 => 'Successful response',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthenticated',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => 'Response',
        };
    }

    /**
     * Infer a JSON request body from the handler's typed input.
     *
     * Only body-bearing verbs (POST/PUT/PATCH) produce a request body. A typed
     * {@see RequestData} parameter takes precedence: when the handler accepts one,
     * its constructor-promoted `#[Rule]` attributes plus the DTO's reflected shape
     * drive the body (see {@see buildRequestBodyFromRequestData()}). Otherwise a
     * `#[Validate]` attribute describes the JSON payload (on GET/DELETE that
     * attribute validates the query string, which is out of scope here). The
     * handler's {@see \ReflectionMethod} is resolved from `[Class, 'method']` or
     * `"Class::method"`; closures, invokables and unresolvable handlers are
     * skipped. All reflection is guarded so generation never throws.
     *
     * @return array<string, mixed>|null
     */
    private function buildRequestBody(Route $route, string $method): ?array
    {
        if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        $fromDto = $this->buildRequestBodyFromRequestData($route->getHandler());
        if ($fromDto !== null) {
            return $fromDto;
        }

        $rules = $this->validationRules($route->getHandler());
        if ($rules === null || $rules === []) {
            return null;
        }

        $schema = ValidationRuleSchema::toObjectSchema($rules);
        $example = (new ExampleDeriver())->fromValidationRules($rules);

        return [
            'required' => ($schema['required'] ?? []) !== [],
            'content' => [
                'application/json' => array_filter([
                    'schema' => $schema,
                    'example' => $example !== [] ? $example : null,
                ], static fn ($v): bool => $v !== null),
            ],
        ];
    }

    /**
     * Build a request body from a handler's typed {@see RequestData} parameter.
     *
     * The DTO's reflected shape (via {@see ClassSchemaReflector}) is authoritative
     * for property TYPES and structure (nested DTOs, enums, arrays). The DTO's
     * constructor-promoted `#[Rule]` strings (via {@see ValidationRuleSchema}) then
     * overlay validation constraints (`format`, `enum`, length/bounds/item counts)
     * and supply the rule-driven `required` list. When the handler has no
     * RequestData parameter — or any reflection fails — null is returned so the
     * caller falls through to the `#[Validate]` path.
     *
     * @return array<string, mixed>|null
     */
    private function buildRequestBodyFromRequestData(mixed $handler): ?array
    {
        $reflection = $this->handlerReflection($handler);
        if ($reflection === null) {
            return null;
        }

        try {
            $dtoClass = $this->findRequestDataParam($reflection);
            if ($dtoClass === null) {
                return null;
            }

            $rules = $this->collectRuleStrings(new \ReflectionClass($dtoClass));

            $shape = ClassSchemaReflector::toSchema($dtoClass);
            $rulesSchema = ValidationRuleSchema::toObjectSchema($rules);

            $merged = $this->mergeShapeWithRules($shape, $rulesSchema);
            $required = $rulesSchema['required'] ?? [];
            $example = (new ExampleDeriver())->fromValidationRules($rules);

            return [
                'required' => $required !== [],
                'content' => [
                    'application/json' => array_filter([
                        'schema' => $merged,
                        'example' => $example !== [] ? $example : null,
                    ], static fn ($v): bool => $v !== null),
                ],
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Find the first parameter whose type implements {@see RequestData}.
     *
     * @return class-string<RequestData>|null
     */
    private function findRequestDataParam(\ReflectionMethod $method): ?string
    {
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $name = $type->getName();
            if (class_exists($name) && is_a($name, RequestData::class, true)) {
                /** @var class-string<RequestData> $name */
                return $name;
            }
        }

        return null;
    }

    /**
     * Collect a DTO's constructor-promoted `#[Rule]` strings, keyed by param name.
     *
     * Mirrors the hydrator: v1 reads `#[Rule]` only from constructor parameters
     * (promoted properties carry the attribute on the parameter).
     *
     * @param  \ReflectionClass<object> $dto
     * @return array<string, string>
     */
    private function collectRuleStrings(\ReflectionClass $dto): array
    {
        $ctor = $dto->getConstructor();
        if ($ctor === null) {
            return [];
        }

        $rules = [];
        foreach ($ctor->getParameters() as $param) {
            foreach ($param->getAttributes(Rule::class) as $attr) {
                $rules[$param->getName()] = $attr->newInstance()->rules;
            }
        }

        return $rules;
    }

    /**
     * Merge a reflected DTO shape with rule-derived constraints.
     *
     * Property TYPES/structure come from the shape (`type`, `items`, nested
     * objects win); validation constraint keys (`format`, `enum`, `minLength`,
     * `maxLength`, `minimum`, `maximum`, `minItems`, `maxItems`) are overlaid from
     * the rule schema. The rule schema's `required` list is authoritative for the
     * request body (property nullability is ignored — `required` is rule-driven).
     *
     * @param  array<string, mixed> $shape
     * @param  array{
     *     type: string,
     *     properties: array<string, array<string, mixed>>,
     *     required?: list<string>
     * } $rulesSchema
     * @return array<string, mixed>
     */
    private function mergeShapeWithRules(array $shape, array $rulesSchema): array
    {
        $constraintKeys = [
            'format',
            'enum',
            'minLength',
            'maxLength',
            'minimum',
            'maximum',
            'minItems',
            'maxItems',
        ];

        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($shape['properties'] ?? null) ? $shape['properties'] : [];
        $ruleProperties = $rulesSchema['properties'];

        foreach ($properties as $field => $propertySchema) {
            $ruleProperty = $ruleProperties[$field] ?? null;
            if (!is_array($ruleProperty)) {
                continue;
            }
            foreach ($constraintKeys as $key) {
                if (array_key_exists($key, $ruleProperty)) {
                    $propertySchema[$key] = $ruleProperty[$key];
                }
            }
            $properties[$field] = $propertySchema;
        }

        $merged = [
            'type' => is_string($shape['type'] ?? null) ? $shape['type'] : 'object',
            'properties' => $properties,
        ];

        // Keep `required` ⊆ documented properties. `#[Rule]` is collected from ALL
        // constructor-promoted params, but ClassSchemaReflector only documents PUBLIC
        // properties — so a non-public promoted #[Rule] param would otherwise leave
        // `required` referencing a property that isn't there (invalid OpenAPI).
        $required = array_values(array_intersect(
            is_array($rulesSchema['required'] ?? null) ? $rulesSchema['required'] : [],
            array_keys($properties),
        ));
        if ($required !== []) {
            $merged['required'] = $required;
        }

        return $merged;
    }

    /**
     * Extract `#[Validate]` rules from a route handler, or null when absent.
     *
     * Resolves the handler to a {@see \ReflectionMethod} from an
     * `[Class, 'method']` pair or a `"Class::method"` string, guarding every
     * step (`class_exists`/`method_exists` + try/catch) so a missing class,
     * closure handler, or reflection failure simply yields null.
     *
     * @return array<string, string|list<string>>|null
     */
    private function validationRules(mixed $handler): ?array
    {
        $reflection = $this->handlerReflection($handler);
        if ($reflection === null) {
            return null;
        }

        try {
            $attributes = $reflection->getAttributes(Validate::class);
            if ($attributes === []) {
                return null;
            }

            /** @var mixed $rules */
            $rules = $attributes[0]->newInstance()->rules;
            if (!is_array($rules)) {
                return null;
            }

            // Keep only string|list<string> rule values; drop Rule-object arrays
            // and other shapes the schema mapper cannot interpret.
            $clean = [];
            foreach ($rules as $field => $rule) {
                if (is_string($rule)) {
                    $clean[(string) $field] = $rule;
                } elseif (is_array($rule) && array_is_list($rule)) {
                    $stringParts = array_values(array_filter(
                        $rule,
                        static fn (mixed $r): bool => is_string($r),
                    ));
                    if (count($stringParts) === count($rule)) {
                        /** @var list<string> $stringParts */
                        $clean[(string) $field] = $stringParts;
                    }
                }
            }

            return $clean;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve a route handler to a `[class-string, method]` pair, or null.
     *
     * Handles `[Class::class, 'method']` arrays and `"Class::method"` strings.
     * Closures, invokables, bare class-strings, and handlers whose class or
     * method does not exist all return null.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private function resolveHandlerMethod(mixed $handler): ?array
    {
        $class = null;
        $method = null;

        if (is_array($handler) && isset($handler[0], $handler[1])) {
            $controller = $handler[0];
            $class = is_object($controller) ? $controller::class : (is_string($controller) ? $controller : null);
            $method = is_string($handler[1]) ? $handler[1] : null;
        } elseif (is_string($handler) && str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);
        }

        if ($class === null || $method === null || $method === '') {
            return null;
        }
        if (!class_exists($class) || !method_exists($class, $method)) {
            return null;
        }

        return [$class, $method];
    }

    /**
     * Resolve a route handler to its {@see \ReflectionMethod}, or null.
     *
     * Shared by request-body (`#[Validate]`) and response-body (`#[ApiResponse]`)
     * reflection. Builds on {@see resolveHandlerMethod()} and guards reflection
     * construction so an unresolvable handler or reflection failure yields null
     * rather than throwing.
     */
    private function handlerReflection(mixed $handler): ?\ReflectionMethod
    {
        $resolved = $this->resolveHandlerMethod($handler);
        if ($resolved === null) {
            return null;
        }

        try {
            return new \ReflectionMethod($resolved[0], $resolved[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Derive a readable one-line summary for an operation.
     */
    private function deriveSummary(Route $route): string
    {
        $name = $route->getName();
        if ($name !== null && $name !== '') {
            $words = preg_split('/[._\-\/]+/', $name) ?: [];
            $words = array_filter($words, static fn (string $w): bool => $w !== '');
            if ($words !== []) {
                return ucwords(implode(' ', $words));
            }
        }

        return $route->getMethod() . ' ' . $route->getPath();
    }

    /**
     * Derive an OpenAPI tag from the path's first meaningful segment.
     *
     * Strips a leading version segment (e.g. `v1`) and any path parameters,
     * title-casing the result. Falls back to "Default" when nothing remains.
     */
    private function deriveTag(string $path): string
    {
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn (string $s): bool => $s !== '',
        ));

        foreach ($segments as $segment) {
            // Skip path parameters like {id}.
            if (str_starts_with($segment, '{')) {
                continue;
            }
            // Skip a leading version segment like v1, v2, v10.
            if (preg_match('/^v\d+$/', $segment) === 1) {
                continue;
            }
            return ucwords(str_replace(['-', '_'], ' ', $segment));
        }

        return 'Default';
    }

    /**
     * Build path parameter objects from the route's param names and constraints.
     *
     * Path parameters are always required. A `where()` constraint, if present,
     * is surfaced as the schema `pattern`.
     *
     * @return list<array<string, mixed>>
     */
    private function buildParameters(Route $route): array
    {
        $constraints = $route->getConstraints();
        $parameters = [];

        foreach ($route->getParamNames() as $param) {
            $schema = ['type' => 'string'];
            if (isset($constraints[$param]) && $constraints[$param] !== '') {
                $schema['pattern'] = $constraints[$param];
            }

            $parameters[] = [
                'name' => $param,
                'in' => 'path',
                'required' => true,
                'description' => '',
                'schema' => $schema,
            ];
        }

        return $parameters;
    }

    /**
     * Build `fields` and `expand` query parameters when the route advertises
     * GraphQL-style field selection via {@see Route::getFieldsConfig()}.
     *
     * When the config declares an `allowed` whitelist, the permitted field names
     * are listed in the `fields` parameter description.
     *
     * @return list<array<string, mixed>>
     */
    private function buildFieldSelectionParameters(Route $route): array
    {
        $config = $route->getFieldsConfig();
        if ($config === null) {
            return [];
        }

        $description = 'Comma-separated list of fields to include in the response.';
        $allowed = $config['allowed'] ?? null;
        if (is_array($allowed) && $allowed !== []) {
            $names = array_values(array_filter(
                array_map(static fn ($f): string => is_string($f) ? $f : '', $allowed),
                static fn (string $f): bool => $f !== '',
            ));
            if ($names !== []) {
                $description .= ' Allowed fields: ' . implode(', ', $names) . '.';
            }
        }

        return [
            [
                'name' => 'fields',
                'in' => 'query',
                'required' => false,
                'description' => $description,
                'schema' => ['type' => 'string'],
            ],
            [
                'name' => 'expand',
                'in' => 'query',
                'required' => false,
                'description' => 'Comma-separated list of related resources to expand.',
                'schema' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Compute the OpenAPI security requirement for a route from its middleware.
     *
     * Route middleware may carry runtime parameters (e.g.
     * `require_content_scope:read:content`, `rate_limit:60,1`); these are
     * stripped to their bare name before the registry lookup, which keys on
     * exact middleware names.
     *
     * @return list<array<string, list<string>>>
     */
    private function buildSecurity(Route $route): array
    {
        $bareNames = [];
        foreach ($route->getMiddleware() as $middleware) {
            $bareNames[] = explode(':', $middleware, 2)[0];
        }

        return $this->registry->securityFor($bareNames);
    }

    /**
     * Render the route's required-scope configuration as a description sentence.
     *
     * OpenAPI apiKey schemes cannot carry scopes natively, so required scopes
     * are documented in prose. The outer list is AND; each inner list is OR.
     */
    private function buildScopeDescription(Route $route): string
    {
        $groups = $route->getRequireScopeConfig();
        if ($groups === []) {
            return '';
        }

        $rendered = [];
        foreach ($groups as $orGroup) {
            $scopes = array_values(array_filter($orGroup, static fn (string $s): bool => $s !== ''));
            if ($scopes === []) {
                continue;
            }
            $rendered[] = count($scopes) === 1
                ? $scopes[0]
                : '(' . implode(' OR ', $scopes) . ')';
        }

        if ($rendered === []) {
            return '';
        }

        return 'Requires scope: ' . implode(' AND ', $rendered) . '.';
    }

    /**
     * Build a minimal but valid responses object.
     *
     * Always includes a 200. Secured routes add 401/403. Rate-limited routes
     * add a 429 carrying the standard rate-limit headers.
     *
     * @return array<int|string, mixed>
     */
    private function buildResponses(bool $isSecured, bool $rateLimited): array
    {
        $responses = [
            '200' => ['description' => 'Successful response'],
        ];

        if ($isSecured) {
            $responses['401'] = ['description' => 'Unauthenticated'];
            $responses['403'] = ['description' => 'Forbidden'];
        }

        if ($rateLimited) {
            $responses['429'] = [
                'description' => 'Too Many Requests',
                'headers' => [
                    'Retry-After' => [
                        'description' => 'Seconds to wait before retrying.',
                        'schema' => ['type' => 'integer'],
                    ],
                    'X-RateLimit-Limit' => [
                        'description' => 'Request quota for the current window.',
                        'schema' => ['type' => 'integer'],
                    ],
                    'X-RateLimit-Remaining' => [
                        'description' => 'Requests remaining in the current window.',
                        'schema' => ['type' => 'integer'],
                    ],
                ],
            ];
        }

        return $responses;
    }

    /**
     * The auto-derived 422 response for a handler that takes a RequestData param.
     * Mirrors the runtime body of
     * {@see \Glueful\Http\Exceptions\Handler::renderValidationException()}:
     * `{success:false, message:string, errors:{<field>:[string]}}`.
     *
     * @return array<string, mixed>
     */
    private function buildValidationErrorResponse(): array
    {
        return [
            'description' => 'Validation failed',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'message' => ['type' => 'string'],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'required' => ['success', 'message', 'errors'],
            ]]],
        ];
    }

    /**
     * Decide whether a route should be documented, honouring origin scoping.
     *
     * App routes are always included. Framework routes (`Glueful\…`) require
     * `documentation.sources.include_framework_routes`; extension/vendor routes
     * require `documentation.options.include_extensions`. Both default to true
     * (including when no context is available).
     */
    private function shouldInclude(Route $route): bool
    {
        $origin = self::originOf($this->handlerClass($route->getHandler()));

        return match ($origin) {
            'framework' => $this->flag('documentation.sources.include_framework_routes'),
            'extension' => $this->flag('documentation.options.include_extensions'),
            default => true,
        };
    }

    /**
     * Resolve a fully-qualified controller class name from a route handler, if any.
     *
     * Returns null for closures or handlers whose class cannot be determined.
     */
    private function handlerClass(mixed $handler): ?string
    {
        if (is_array($handler) && isset($handler[0])) {
            $controller = $handler[0];
            if (is_object($controller)) {
                return $controller::class;
            }
            if (is_string($controller)) {
                return $controller;
            }
            return null;
        }

        if (is_string($handler)) {
            // "Class::method" or an invokable class-string.
            return str_contains($handler, '::')
                ? explode('::', $handler, 2)[0]
                : $handler;
        }

        return null;
    }

    /**
     * Classify a handler class by origin: app, framework, or extension.
     *
     * Pure function (no state) so it is directly unit-testable. A null or
     * unresolvable class (e.g. a closure handler) is treated as an app route
     * and therefore included.
     *
     * @return 'app'|'framework'|'extension'
     */
    public static function originOf(?string $handlerClass): string
    {
        if ($handlerClass === null || $handlerClass === '') {
            return 'app';
        }

        $class = ltrim($handlerClass, '\\');

        if (str_starts_with($class, 'App\\')) {
            return 'app';
        }
        if (str_starts_with($class, 'Glueful\\')) {
            return 'framework';
        }

        return 'extension';
    }

    /**
     * Read a boolean documentation flag from config, defaulting to true.
     */
    private function flag(string $key): bool
    {
        if ($this->context === null) {
            return true;
        }

        return (bool) $this->context->getConfig($key, true);
    }
}
