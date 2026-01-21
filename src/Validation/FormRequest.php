<?php

declare(strict_types=1);

namespace Glueful\Validation;

use Glueful\Exceptions\ApiException;
use Glueful\Validation\Contracts\Rule;
use Glueful\Validation\Support\RuleParser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base FormRequest class for complex validation scenarios
 *
 * Extend this class to encapsulate validation logic, authorization,
 * and request preparation in a single, testable class.
 *
 * @example
 * class CreateUserRequest extends FormRequest
 * {
 *     public function rules(): array
 *     {
 *         return [
 *             'email' => 'required|email|unique:users,email',
 *             'password' => 'required|min:8|confirmed',
 *             'name' => 'required|string|max:255',
 *         ];
 *     }
 *
 *     public function authorize(): bool
 *     {
 *         return $this->user()?->isAdmin() ?? false;
 *     }
 * }
 */
abstract class FormRequest
{
    /**
     * The underlying HTTP request
     */
    protected Request $request;

    /**
     * The validated data
     *
     * @var array<string, mixed>
     */
    protected array $validated = [];

    /**
     * The validator instance
     */
    protected ?Validator $validator = null;

    /**
     * The rule parser instance
     */
    protected ?RuleParser $ruleParser = null;

    /**
     * Create a new FormRequest instance
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Get the validation rules that apply to the request
     *
     * @return array<string, string|array<Rule>>
     */
    abstract public function rules(): array;

    /**
     * Determine if the user is authorized to make this request
     *
     * Override this method to add authorization logic.
     * Return false to automatically return a 403 response.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get custom messages for validation errors
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Get custom attribute names for validation errors
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [];
    }

    /**
     * Prepare the data for validation
     *
     * Override this method to modify request data before validation.
     */
    protected function prepareForValidation(): void
    {
        // Override in subclass
    }

    /**
     * Handle a passed validation attempt
     *
     * Called after validation passes, before the controller method.
     */
    protected function passedValidation(): void
    {
        // Override in subclass
    }

    /**
     * Handle a failed validation attempt
     *
     * Override to customize the exception thrown on failure.
     *
     * @param array<string, array<string>> $errors
     * @throws ValidationException
     */
    protected function failedValidation(array $errors): void
    {
        throw new ValidationException($errors, $this->messages());
    }

    /**
     * Handle a failed authorization attempt
     *
     * @throws ApiException
     */
    protected function failedAuthorization(): void
    {
        throw new ApiException('This action is unauthorized.', 403);
    }

    /**
     * Validate the request
     *
     * @throws ValidationException
     * @throws ApiException
     */
    public function validate(): void
    {
        // Check authorization first
        if (!$this->authorize()) {
            $this->failedAuthorization();
        }

        // Prepare data
        $this->prepareForValidation();

        // Build and run validator
        $rules = $this->parseRules($this->rules());
        $this->validator = new Validator($rules);

        $errors = $this->validator->validate($this->all());

        if ($errors !== []) {
            $this->failedValidation($errors);
        }

        $this->validated = $this->validator->filtered();
        $this->passedValidation();
    }

    /**
     * Parse string rules into Rule objects
     *
     * @param array<string, string|array<Rule>> $rules
     * @return array<string, array<Rule>>
     */
    protected function parseRules(array $rules): array
    {
        if ($this->ruleParser === null) {
            $this->ruleParser = new RuleParser();
        }

        return $this->ruleParser->parse($rules);
    }

    /**
     * Get all input data from the request
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $data = array_merge(
            $this->request->query->all(),
            $this->request->request->all()
        );

        // Also merge JSON body if present
        if ($this->request->getContentTypeFormat() === 'json') {
            $jsonData = json_decode($this->request->getContent(), true);
            if (is_array($jsonData)) {
                $data = array_merge($data, $jsonData);
            }
        }

        // Include files
        $files = $this->request->files->all();
        if ($files !== []) {
            $data = array_merge($data, $files);
        }

        return $data;
    }

    /**
     * Get the validated data
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        return $this->validated;
    }

    /**
     * Get a specific validated input value
     */
    public function validatedInput(string $key, mixed $default = null): mixed
    {
        return $this->validated[$key] ?? $default;
    }

    /**
     * Get only specified keys from validated data
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->validated, array_flip($keys));
    }

    /**
     * Get all validated data except specified keys
     *
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public function except(array $keys): array
    {
        return array_diff_key($this->validated, array_flip($keys));
    }

    /**
     * Get a specific input value
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /**
     * Check if the request has a specific input
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Check if the request has any of the given inputs
     *
     * @param array<string> $keys
     */
    public function hasAny(array $keys): bool
    {
        $all = $this->all();
        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the underlying request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Get a route parameter
     */
    public function route(string $key, mixed $default = null): mixed
    {
        $routeParams = $this->request->attributes->get('route_params', []);
        return $routeParams[$key] ?? $default;
    }

    /**
     * Get the authenticated user (if any)
     */
    public function user(): mixed
    {
        return $this->request->attributes->get('user');
    }

    /**
     * Merge additional data into the request
     *
     * @param array<string, mixed> $data
     */
    public function merge(array $data): self
    {
        foreach ($data as $key => $value) {
            $this->request->request->set($key, $value);
        }
        return $this;
    }

    /**
     * Replace specific input values
     *
     * @param array<string, mixed> $data
     */
    public function replace(array $data): self
    {
        $this->request->request->replace($data);
        return $this;
    }

    /**
     * Dynamically access request input
     */
    public function __get(string $name): mixed
    {
        return $this->input($name);
    }

    /**
     * Check if input exists
     */
    public function __isset(string $name): bool
    {
        return $this->has($name);
    }
}
