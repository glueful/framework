<?php

declare(strict_types=1);

namespace Glueful\Validation\Middleware;

use Glueful\Routing\RouteMiddleware;
use Glueful\Validation\Attributes\Validate;
use Glueful\Validation\FormRequest;
use Glueful\Validation\Validator;
use Glueful\Validation\ValidationException;
use Glueful\Validation\Support\RuleParser;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Validation Middleware
 *
 * Automatically validates incoming requests based on:
 * 1. #[Validate] attributes on controller methods
 * 2. FormRequest type-hints in controller parameters
 *
 * @example
 * // Register in middleware stack
 * $router->get('/users', [UserController::class, 'store'])
 *     ->middleware(['validation']);
 *
 * // In controller
 * #[Validate(['email' => 'required|email'])]
 * public function store(ValidatedRequest $request): Response
 * {
 *     $email = $request->get('email');
 * }
 */
class ValidationMiddleware implements RouteMiddleware
{
    private RuleParser $ruleParser;

    public function __construct()
    {
        $this->ruleParser = new RuleParser();
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Get route information from request attributes
        $controller = $request->attributes->get('_controller');
        $method = $request->attributes->get('_controller_method');

        if ($controller === null || $method === null) {
            return $next($request);
        }

        try {
            // Check for FormRequest parameter
            $formRequest = $this->resolveFormRequest($controller, $method, $request);

            if ($formRequest !== null) {
                $formRequest->validate();
                $request->attributes->set('validated', $formRequest->validated());
                $request->attributes->set('form_request', $formRequest);
                return $next($request);
            }

            // Check for #[Validate] attribute
            $validateAttribute = $this->getValidateAttribute($controller, $method);

            if ($validateAttribute !== null) {
                $this->validateWithAttribute($request, $validateAttribute);
            }

            return $next($request);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e);
        }
    }

    /**
     * Resolve FormRequest from method parameters
     */
    protected function resolveFormRequest(
        string|object $controller,
        string $method,
        Request $request
    ): ?FormRequest {
        $reflection = new ReflectionMethod($controller, $method);

        foreach ($reflection->getParameters() as $parameter) {
            $type = $parameter->getType();

            // Only process named types (not union/intersection types)
            if (!$type instanceof ReflectionNamedType) {
                continue;
            }

            // Skip built-in types
            if ($type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (is_subclass_of($className, FormRequest::class)) {
                return new $className($request);
            }
        }

        return null;
    }

    /**
     * Get #[Validate] attribute from method
     */
    protected function getValidateAttribute(
        string|object $controller,
        string $method
    ): ?Validate {
        $reflection = new ReflectionMethod($controller, $method);
        $attributes = $reflection->getAttributes(Validate::class);

        if ($attributes === []) {
            return null;
        }

        return $attributes[0]->newInstance();
    }

    /**
     * Validate request using #[Validate] attribute
     *
     * @throws ValidationException
     */
    protected function validateWithAttribute(Request $request, Validate $attribute): void
    {
        $rules = $this->ruleParser->parse($attribute->rules);
        $validator = new Validator($rules);

        // Merge query and request data
        $data = array_merge(
            $request->query->all(),
            $request->request->all()
        );

        // Also merge JSON body if present
        if ($request->getContentTypeFormat() === 'json') {
            $jsonData = json_decode($request->getContent(), true);
            if (is_array($jsonData)) {
                $data = array_merge($data, $jsonData);
            }
        }

        $errors = $validator->validate($data);

        if ($errors !== []) {
            throw new ValidationException($errors, $attribute->messages);
        }

        // Store validated data in request attributes
        $request->attributes->set('validated', $validator->filtered());
    }

    /**
     * Create validation error response
     */
    protected function validationErrorResponse(ValidationException $e): HttpResponse
    {
        return Response::validation($e->errors(), $e->getMessage());
    }
}
