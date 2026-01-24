# Legacy ValidationException Migration Plan

**Status: ✅ COMPLETED**

## Overview

Migrate from `Glueful\Exceptions\ValidationException` (legacy) to `Glueful\Validation\ValidationException` (modern) and consolidate the `Glueful\Uploader\ValidationException`.

## Final State

Only one ValidationException class exists:
- `Glueful\Validation\ValidationException` - Feature-rich, modern ✅

Deleted:
- `Glueful\Exceptions\ValidationException` - Legacy ❌ (removed)
- `Glueful\Uploader\ValidationException` - Empty class ❌ (removed)

## Constructor Signature Differences

```php
// Legacy
new ValidationException(string|array $errors, int $statusCode = 422, ?array $details = null)
// - First param can be string message OR array of errors
// - Method: getErrors()

// Modern
new ValidationException(array $errors, array $customMessages = [], string $message = 'The given data was invalid.')
// - First param MUST be array of errors (field => [messages])
// - Method: errors()
// - Static factories: forField(), forFields(), withErrors()
```

## Migration Tasks

### Phase 1: Update Application Code ✅

#### 1.1 AuthController.php (~15 throws)
- [x] Update import
- [x] Convert string throws to `ValidationException::forField()` or proper array format
- [x] Update PHPDoc @throws annotations

#### 1.2 ValidationHelper.php (~11 throws)
- [x] Update import
- [x] Convert throws to use static factories

#### 1.3 ConfigController.php (2 throws)
- [x] Update import
- [x] Convert string throws to proper format

#### 1.4 BaseController.php (import only)
- [x] Update import
- [x] Update `handleException()` to use `errors()` method

### Phase 2: Update Scaffold Templates ✅

#### 2.1 ControllerCommand.php
- [x] Update import in generated code templates
- [x] Update throw statements in templates

### Phase 3: Update Exception Handling ✅

#### 3.1 Handler.php
- [x] Remove LegacyValidationException import
- [x] Remove from $dontReport array
- [x] Remove from $httpMapping array
- [x] Remove renderLegacyValidationException() method
- [x] Update render() match statement

#### 3.2 ExceptionHandler.php
- [x] Update import to new ValidationException
- [x] Update `errors()` method call

### Phase 4: Consolidate Uploader Exception ✅

#### 4.1 FileUploader.php
- [x] Update import to use Glueful\Validation\ValidationException
- [x] Update throw statements to use `forField()`

#### 4.2 Delete Uploader/ValidationException.php
- [x] Remove empty class file

### Phase 5: Cleanup ✅

#### 5.1 Delete Legacy Class
- [x] Remove src/Exceptions/ValidationException.php

#### 5.2 Verify No Remaining References
- [x] Run grep to confirm no legacy imports remain

## Rollback Plan

If issues arise:
1. Revert commits
2. Legacy class still exists until Phase 5 is complete

## Testing

After migration:
1. Run full test suite: `composer test`
2. Run static analysis: `composer run analyse`
3. Manual testing of validation error responses
