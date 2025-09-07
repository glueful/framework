<?php

declare(strict_types=1);

namespace Glueful\Routing;

class Route
{
    private string $method;
    private string $path;
    private mixed $handler;
    /** @var array<string> */
    private array $middleware = [];
    private ?string $pattern = null;
    /** @var array<string> */
    private array $paramNames = [];
    private ?string $name = null;
    /** @var array<string, string> */
    private array $where = [];

    public function __construct(
        private Router $router, // Back-reference for named route registration
        string $method,
        string $path,
        mixed $handler
    ) {
        $this->method = $method;
        $this->path = $path;
        $this->handler = $handler;

        // Pre-compile pattern if dynamic
        if (str_contains($path, '{')) {
            $this->compilePattern();
        }
    }

    // Fluent middleware addition
    /**
     * @param string|array<string> $middleware
     */
    public function middleware(string|array $middleware): self
    {
        $this->middleware = array_merge($this->middleware, (array)$middleware);
        return $this;
    }

    // Parameter constraints
    /**
     * @param string|array<string, string> $param
     */
    public function where(string|array $param, ?string $regex = null): self
    {
        if (is_array($param)) {
            $this->where = array_merge($this->where, $param);
        } else {
            $this->where[$param] = $regex;
        }
        // Recompile pattern with constraints
        if ($this->pattern !== null) {
            $this->compilePattern();
        }
        return $this;
    }

    // Named routes with automatic registration
    public function name(string $name): self
    {
        $this->name = $name;
        $this->router->registerNamedRoute($name, $this);
        return $this;
    }

    // Compile {param} to regex with safety checks
    private function compilePattern(): void
    {
        $pattern = $this->path;

        // Extract parameter names
        preg_match_all('/\{([^}]+)\}/', $pattern, $matches);
        $this->paramNames = $matches[1];

        // Replace {param} with regex
        foreach ($this->paramNames as $param) {
            $constraint = $this->where[$param] ?? '[^/]+';

            // Validate constraint regex for safety
            if (!$this->isValidConstraint($constraint)) {
                throw new \InvalidArgumentException("Invalid regex constraint for parameter '{$param}': {$constraint}");
            }

            // Escape delimiter characters and wrap in capture group
            $safeConstraint = str_replace('#', '\\#', $constraint);
            $pattern = str_replace('{' . $param . '}', '(' . $safeConstraint . ')', $pattern);
        }

        // Escape the path delimiter
        $pattern = str_replace('#', '\\#', $pattern);
        $this->pattern = '#^' . $pattern . '$#u'; // Add unicode modifier
    }

    // Validate regex constraint for security
    private function isValidConstraint(string $constraint): bool
    {
        // Test the regex for validity and safety
        try {
            $testResult = preg_match('#' . str_replace('#', '\\#', $constraint) . '#u', 'test');
            return $testResult !== false;
        } catch (\Exception) {
            return false;
        }
    }

    // Match path and extract parameters
    /**
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if ($this->pattern === null) {
            // Static route
            return $this->path === $path ? [] : null;
        }

        // Dynamic route
        if (preg_match($this->pattern, $path, $matches)) {
            array_shift($matches); // Remove full match

            if (count($this->paramNames) === 0) {
                return [];
            }

            return array_combine($this->paramNames, $matches);
        }

        return null;
    }

    // Getters for compiler/cache
    public function getMethod(): string
    {
        return $this->method;
    }
    public function getPath(): string
    {
        return $this->path;
    }
    public function getHandler(): mixed
    {
        return $this->handler;
    }
    /**
     * @return array<string>
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }
    public function getName(): ?string
    {
        return $this->name;
    }
    public function getPattern(): ?string
    {
        return $this->pattern;
    }
    /**
     * @return array<string>
     */
    public function getParamNames(): array
    {
        return $this->paramNames;
    }
    /**
     * @return array<string, string>
     */
    public function getConstraints(): array
    {
        return $this->where;
    }

    // Generate URL from route parameters
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $query
     */
    public function generateUrl(array $params = [], array $query = []): string
    {
        $url = $this->path;

        // Replace parameters in path
        foreach ($this->paramNames as $param) {
            if (!isset($params[$param])) {
                throw new \InvalidArgumentException("Missing required parameter: {$param}");
            }

            $value = (string) $params[$param];

            // Validate against constraint if set
            if (isset($this->where[$param])) {
                $constraint = '#^' . str_replace('#', '\\#', $this->where[$param]) . '$#u';
                if (!preg_match($constraint, $value)) {
                    throw new \InvalidArgumentException(
                        "Parameter '{$param}' value '{$value}' does not match constraint"
                    );
                }
            }

            $url = str_replace('{' . $param . '}', rawurlencode($value), $url);
        }

        // Add query parameters
        if (count($query) > 0) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}
