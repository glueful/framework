# Error Handling

How to handle errors and return proper responses.

## Global Handling

Glueful centralizes exception handling; see `docs/ERROR_HANDLING.md` for full details.

## Example: Rate Limit

```php
use Glueful\\Exceptions\\RateLimitExceededException;
use Symfony\\Component\\HttpFoundation\\JsonResponse;

try {
    // ... application code
} catch (RateLimitExceededException $e) {
    return new JsonResponse(
        ['message' => 'Too Many Requests'],
        429,
        ['Retry-After' => (string) $e->getRetryAfter()]
    );
}
```

## Validation and 4xx

Throw the appropriate framework exceptions or return `JsonResponse` with
status code and body. See `docs/VALIDATION.md` for guidance.
