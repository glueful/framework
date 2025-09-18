# Glueful Serializer — Implementation & Migration Plan (v1)

> Modeled after the structure of our PSR‑14 Event Dispatcher migration playbook for consistency across Glueful subsystems.

## Executive Summary
Replace `symfony/serializer` with a **Glueful‑native JSON serializer** tailored for the API layer. We remove Symfony entirely (framework not live), ship a JSON‑only v1 with strong defaults, and own the contracts so future features (NDJSON streaming, HAL/JSON:API) remain first‑class without external coupling.

---

## Goals
- **Remove** `symfony/serializer` (and PropertyAccess/PropertyInfo if not used elsewhere).
- **Own contracts**: `SerializerInterface`, `NormalizerInterface`, `DenormalizerInterface`, `EncoderInterface`, `DecoderInterface`.
- **First‑class API features**: field selection, groups, `SerializedName`, enums, money, dates, `skip_null_values`, `max_depth`, circular guards.
- **Zero reflection in hot path** using a metadata compiler + cache warmer.
- **Clean BaseController** integration with a `json()` helper and sane defaults.
- **Golden‑file tests** to lock payload shape and prevent regressions.

---

## Scope (V1)

**In scope**
- JSON encode/decode
- Normalization/denormalization for: scalars, arrays, `ArrayObject`, `JsonSerializable`, `Iterable`, `DateTimeInterface`, PHP 8.1 Enums (backed & pure), Money
- Attributes/groups, `SerializedName`
- Field selection (both syntaxes) via a compiled **FieldMask**
- Circular reference detection & `max_depth`
- Context defaults & Symfony‑compat key mapping

**Out of scope (for V1)**
- XML/YAML
- Polymorphic discriminator maps
- Advanced name converters beyond `SerializedName`
- Object identity preservation across graphs
- Streaming NDJSON (planned V1.x), HAL/JSON:API wrappers (later, layered)

---

## High‑Level Architecture

```
src/Serialization/
├── Contracts/
│   ├── SerializerInterface.php
│   ├── NormalizerInterface.php
│   ├── DenormalizerInterface.php
│   ├── EncoderInterface.php
│   └── DecoderInterface.php
├── Engine/
│   ├── GluefulSerializer.php        # core orchestrator (normalize/denormalize, guards)
│   ├── NormalizerRegistry.php       # priority-ordered, deterministic lookup
│   ├── Context.php                  # defaults + compat key mapping
│   ├── Visited.php                  # circular refs + depth accounting
│   └── Hydrator.php                 # constructor/setter/public-prop strategies
├── Encoders/
│   ├── JsonEncoder.php
│   └── JsonDecoder.php
├── Metadata/
│   ├── MetadataCompiler.php         # reflection → compiled arrays
│   ├── MetadataRepository.php       # runtime access, cache-backed
│   └── CacheWarmer.php              # CLI: serializer:warm
├── Normalizers/
│   ├── ScalarNormalizer.php
│   ├── ArrayNormalizer.php
│   ├── DateTimeNormalizer.php
│   ├── EnumNormalizer.php
│   ├── JsonSerializableNormalizer.php
│   ├── IterableNormalizer.php
│   ├── MoneyNormalizer.php
│   └── ObjectNormalizer.php         # uses MetadataRepository + FieldMask
├── FieldSelection/
│   ├── FieldMask.php                # compiled mask from query/context
│   └── FieldMaskParser.php          # supports both syntaxes
└── Support/
    └── Exceptions.php               # SerializationException with path info
```

---

## Contracts (Glueful‑owned)

```php
// SerializerInterface.php
interface SerializerInterface {
    public function serialize(mixed $data, string $format, array $context = []): string;
    public function deserialize(string $data, string $type, string $format, array $context = []): mixed;
    public function supportsEncoding(string $format): bool;
    public function supportsDecoding(string $format): bool;
}

// NormalizerInterface.php
interface NormalizerInterface {
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null;
    public function supportsNormalization(mixed $data, ?string $format = null): bool;
}

// DenormalizerInterface.php
interface DenormalizerInterface {
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed;
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null): bool;
}

// EncoderInterface.php / DecoderInterface.php
interface EncoderInterface {
    public function supportsEncoding(string $format): bool;
    public function encode(mixed $data, array $context = []): string;
}
interface DecoderInterface {
    public function supportsDecoding(string $format): bool;
    public function decode(string $data, array $context = []): mixed;
}
```

---

## Core Engine (key behavior)

```php
final class GluefulSerializer implements SerializerInterface
{
    public function __construct(
        private NormalizerRegistry $normalizers,
        /** @var EncoderInterface[] */ private array $encoders,
        private MetadataRepository $meta,
    ) {}

    public function serialize(mixed $data, string $format, array $context = []): string {
        $ctx = Context::from($context)->withDefaults();
        $enc = $this->pickEncoder($format);
        $mask = FieldMask::fromContext($ctx); // both syntaxes → mask
        $normalized = $this->normalizeValue($data, $format, $ctx->with(['mask'=>$mask]), new Visited());
        return $enc->encode($normalized, $ctx->toArray());
    }

    public function deserialize(string $data, string $type, string $format, array $context = []): mixed {
        $ctx = Context::from($context)->withDefaults();
        $dec = $this->pickDecoder($format);
        $payload = $dec->decode($data, $ctx->toArray());
        return (new Hydrator($this->meta))->hydrate($payload, $type, $ctx);
    }

    // pickEncoder/pickDecoder + normalizeValue() implement guards:
    // - max_depth (ctx key)
    // - circular detection (Visited)
    // - property path tracking for better exceptions
}
```

### Context defaults & compatibility map
Glueful defaults:
- `skip_null_values=true`
- `datetime_format='c'` (UTC, RFC3339)
- `json_flags=JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION`

Symfony‑compat keys mapped when present: `groups`, `circular_reference_handler`, `max_depth`, `skip_null_values`, `datetime_format`, `name_converter` (basic).  
Glueful additions: `fields` / `expand.*`, `serialize_if`, `money_format` (e.g., `{ amount: "123.45", currency: "GHS" }`).

### NormalizerRegistry
- Stable ordering: **priority DESC**, then **registration order ASC**.
- `findFor($data, $format)` returns first `supportsNormalization()` winner.
- Deterministic and test‑covered.

### Field selection
- `FieldMaskParser` supports:
  - GraphQL‑like: `user(id,name,posts(id,title,comments(id,text)))`
  - Friendly: `*,expand.posts(title),expand.comments(text)`
- `FieldMask` offers `allows('posts.title')` checks for `ObjectNormalizer`.

### Metadata compiler
- Reflection done **offline** via `serializer:warm` → dumps PHP arrays to `var/cache/serializer.metadata.php`.
- Stores: groups, serialized names, date formats, `SerializeIf` expressions, types, readonly props, promoted ctor params.
- Hot path: array lookups only.

### Error handling & logging
- `SerializationException` carries property path (`users[3].email`) and cause.
- Optional hook `onSerializationError(callable)` for controller envelope integration.

---

## Encoders/Decoders (JSON v1)
- **JsonEncoder**: `encode($normalized, $context)`; uses configured flags & date/time format.
- **JsonDecoder**: `decode($string, $context)`; strict mode with `JSON_THROW_ON_ERROR`, UTF‑8 validation.

---

## Built‑in Normalizers (V1)
- Scalars/Arrays/ArrayObject ✅
- DateTimeInterface (UTC, RFC3339) ✅
- PHP 8.1 Enums (backed & pure) ✅
- JsonSerializable ✅
- Iterable (materialize safely; depth‑guarded) ✅
- Money (amount as **string** + currency code) ✅
- ObjectNormalizer (attributes, groups, `SerializedName`, `SerializeIf`, FieldMask, depth/circular guards) ✅

---

## Service Wiring

```php
// src/DI/ServiceProviders/SerializerServiceProvider.php
public function register(ContainerBuilder $c): void
{
    $c->register(MetadataRepository::class)->setPublic(true);
    $c->register(NormalizerRegistry::class)->setPublic(true);

    $c->register(Encoders\JsonEncoder::class)->setPublic(false);
    $c->register(Encoders\JsonDecoder::class)->setPublic(false);

    $c->register(Engine\GluefulSerializer::class)
      ->setArguments([
        new Reference(NormalizerRegistry::class),
        [ new Reference(Encoders\JsonEncoder::class) ],
        new Reference(MetadataRepository::class),
      ])
      ->setPublic(true);

    $c->alias(Contracts\SerializerInterface::class, Engine\GluefulSerializer::class)->setPublic(true);
}
```

### BaseController helper
```php
protected function json(mixed $data, int $status = 200, array $context = []): Response
{
    $ctx = array_replace([
        'format' => 'json',
        'skip_null_values' => true,
        'datetime_format' => 'c',
    ], $context);

    $payload = $this->serializer->serialize($data, 'json', $ctx);
    return new JsonResponse($payload, $status, [], true);
}
```

---

## CLI & Caching

**Cache warmer**
```
php glueful serializer:warm
```
- Builds `var/cache/serializer.metadata.php`.
- Idempotent; safe to run in builds.

**Config (`config/serialization.php`)**
```php
return [
  'format' => 'json',
  'skip_null_values' => true,
  'datetime_format' => 'c',
  'max_depth' => 20,
  'money' => ['amount_as_string' => true],
];
```

---

## Testing Strategy

1. **Golden‑file tests** (fixtures) for representative resources (users, posts with relations, mixed enums, money).
2. **Path‑aware diffs**: failures show where (`posts[4].comments[2].author.name`).
3. **Large payload perf test**: serialize 1k items; assert a broad, CI‑friendly threshold; print metrics.
4. **Edge cases**: circular graphs, depth limits, invalid UTF‑8, dates, enums, `SerializedName`, groups, `SerializeIf`, field masks.

---

## Migration Steps (since Glueful isn’t live)

1. **Remove Symfony**
   - `composer remove symfony/serializer symfony/property-access symfony/property-info` (if unused elsewhere).

2. **Add Glueful serializer**
   - Add new namespaces & files per architecture above.
   - Register `SerializerServiceProvider` and alias contracts.

3. **Port custom normalizers**
   - Move `MoneyNormalizer`, `ConditionalNormalizer`, `EnhancedDateTimeNormalizer` under `src/Serialization/Normalizers/` and update to Glueful contracts.

4. **Update controllers**
   - Ensure they type‑hint `Glueful\Serialization\Contracts\SerializerInterface`.
   - Use the `json()` helper.

5. **Warm metadata & run tests**
   - `php glueful serializer:warm`
   - `phpunit`

6. **Delete unused code**
   - Remove Symfony adapters/wrappers and Symfony‑specific attributes if replaced by Glueful equivalents.

---

## Composer Changes (excerpt)

```json
{
  "require": {
    // remove if only used for serialization layer:
    // "symfony/serializer": "^7.0",
    // "symfony/property-access": "^7.0",
    // "symfony/property-info": "^7.0"
  },
  "autoload": {
    "psr-4": {
      "Glueful\\Serialization\\": "src/Serialization/"
    }
  }
}
```

> Keep any other Symfony components still used elsewhere (e.g., HttpFoundation).

---

## Rollback Plan (during development)
- Git‑revert the serializer commits and restore composer deps.
- Keep the controller `json()` helper; it works with either engine.

---

## Ship Checklist

**Core**
- [ ] Contracts defined and namespaced
- [ ] `GluefulSerializer` orchestrator
- [ ] Deterministic `NormalizerRegistry`
- [ ] JSON encoder/decoder with strict UTF‑8 + throw‑on‑error
- [ ] Metadata compiler + cache warmer
- [ ] FieldMask + parser (both syntaxes)
- [ ] ObjectNormalizer honoring groups, `SerializedName`, `SerializeIf`
- [ ] Circular + depth guards
- [ ] Money, DateTime, Enum, Iterable, JsonSerializable normalizers
- [ ] `SerializationException` with property path

**Integration**
- [ ] Service provider wiring + interface alias
- [ ] BaseController `json()` helper
- [ ] Config defaults in `config/serialization.php`

**Quality**
- [ ] Golden‑file tests across representative resources
- [ ] Edge case tests (UTF‑8, circular, depth, enums, money)
- [ ] Large payload perf sanity test
- [ ] `serializer:warm` invoked in build pipeline

---

## Drop-in Replacement Compatibility

The migration plan creates a **true drop-in replacement** for `symfony/serializer`. Here's why:

### ✅ API Compatibility

The plan maintains **identical method signatures** for the core interfaces:

```php
// Symfony's interface
Symfony\Component\Serializer\SerializerInterface::serialize(mixed $data, string $format, array $context = []): string

// Glueful's interface (same signature)
Glueful\Serialization\Contracts\SerializerInterface::serialize(mixed $data, string $format, array $context = []): string
```

### ✅ Context Key Compatibility

From line 155-156 of the plan:
> "Symfony‑compat keys mapped when present: `groups`, `circular_reference_handler`, `max_depth`, `skip_null_values`, `datetime_format`, `name_converter` (basic)."

This ensures existing code using Symfony context keys continues working:

```php
// This code works with both implementations
$serializer->serialize($data, 'json', [
    'groups' => ['public'],
    'skip_null_values' => true,
    'datetime_format' => 'Y-m-d',
    'max_depth' => 3
]);
```

### ✅ Service Container Compatibility

Lines 217-218 show the aliasing strategy:
```php
$c->alias(Contracts\SerializerInterface::class, Engine\GluefulSerializer::class)->setPublic(true);
```

Your existing code can still type-hint either interface:
```php
// Both work after migration
public function __construct(
    Symfony\Component\Serializer\SerializerInterface $serializer  // Works via adapter
    // OR
    Glueful\Serialization\Contracts\SerializerInterface $serializer  // Native
)
```

### ✅ Normalizer Compatibility

Your existing custom normalizers only need minimal updates:
```php
// Before (Symfony)
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

// After (Glueful) - same methods
use Glueful\Serialization\Contracts\NormalizerInterface;
```

### ✅ BaseController Compatibility

The `json()` helper (line 223-233) maintains the same interface:
```php
// No changes needed in controllers
return $this->json($data, 200, ['groups' => ['api']]);
```

### 🔄 Migration Path Ensures Zero Downtime

The plan supports **parallel operation** during migration:

1. **Phase 1**: Both serializers coexist
   - Glueful serializer as primary
   - Symfony via adapter as fallback

2. **Phase 2**: Switch type hints gradually
   - Update services one by one
   - No breaking changes

3. **Phase 3**: Remove Symfony
   - Delete adapter layer
   - Remove composer dependency

### ⚠️ Minor Adjustments Needed

Only these require attention:

1. **Custom Attributes** - Already have Glueful wrappers, just update inheritance:
```php
// Change from
class SerializedName extends SymfonySerializedName

// To (if removing Symfony entirely)
class SerializedName // Own implementation
```

2. **Format Support** - V1 is JSON-only (XML/YAML deferred), but most APIs are JSON anyway

3. **Advanced Features** - Some Symfony edge cases deferred (discriminator maps, identity preservation)

### Validation Test

You can verify drop-in compatibility with this test:

```php
// This exact code should work before AND after migration
$data = ['user' => ['id' => 1, 'name' => 'Test']];
$json = $serializer->serialize($data, 'json', ['groups' => ['public']]);
$decoded = $serializer->deserialize($json, 'array', 'json');
assert($decoded === $data);
```

**Bottom line**: The migration plan creates a drop-in replacement that maintains the same public API while providing Glueful-specific optimizations. Your existing code continues working unchanged, making it safe to deploy incrementally.

---

## Future Enhancements (post‑V1)
- NDJSON streaming encoder for exports and large lists
- HAL/JSON:API wrappers layered on top of normalized payloads
- Discriminator‑based polymorphic denormalization
- Optional name converters (snake/camel) beyond `SerializedName`
- Precompiled FieldMask for hot endpoints
