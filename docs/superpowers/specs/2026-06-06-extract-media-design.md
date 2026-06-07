# Extract Rich Media → `glueful/media` — Design Note

**Status:** Draft v2 — review folded in (`StorageInterface` added to `generateThumbnail()`; seam moved to `Glueful\Uploader\Contracts`; `ImageSecurityValidator` core-binding gap fixed + removed from extension layout; `ImageProcessorIntegration.md` move; required-coverage section); no code yet · **Scope:** `src/Services/ImageProcessor*.php`, `src/Services/ImageSecurityValidator.php`, `src/Container/Providers/ImageProvider.php`, `src/Uploader/ThumbnailGenerator.php`, `src/Uploader/MediaMetadata*.php`, `src/Uploader/FileUploader.php` (rewire only), `src/Controllers/UploadController.php` (rewire only), `src/helpers.php` (`image()`), `config/image.php`, the thumbnail keys in `config/filesystem.php`/`config/uploads.php`, and the two heavy deps in `composer.json`. Upload/storage primitives (storage drivers, validation, temp/private storage, signed URLs, blob persistence) stay in core.

## Problem

Rich media processing — image transformations/variants, thumbnail generation, and media metadata extraction — ships inside framework core today, dragging two heavy runtime deps into every install:

- `intervention/image: ^4.1` (`composer.json:29`) — only consumed by `ImageProcessor` (`src/Services/ImageProcessor.php:8-15`) and `ImageProvider` (`src/Container/Providers/ImageProvider.php:19-43`).
- `james-heinrich/getid3: ^1.9` (`composer.json:30`) — only consumed by `MediaMetadataExtractor` (`src/Uploader/MediaMetadataExtractor.php:7,22,137-146`).

Per the agreed boundary policy (**core = primitives, stable contracts, zero-infra reference implementations, and capabilities core itself depends on**), none of this is a primitive. Core never resizes an image or reads a duration for its own sake — it only does so on behalf of an *upload* feature. The seam is conceptually clean: **raw upload + storage stays; rich processing moves.** But this is the **highest-call-site-surface extraction of the planned set** — `ImageProcessor` is wired through the DI container, the `image()` global helper, the `FileUploader::uploadMedia()` path, and the on-demand variant endpoint in `UploadController::show()`.

Concrete coupling in core today:

1. **DI container always registers the media graph.** `ContainerFactory.php:141` registers `ImageProvider`, which unconditionally builds `Intervention\Image\ImageManager`, `ImageSecurityValidator`, and `ImageProcessorInterface`/`ImageProcessor` (`ImageProvider.php:19-84`). Booting fails hard if neither GD nor Imagick is present (`ImageProvider.php:27,36`), even for an app that never touches images.
2. **`FileUploader` hard-constructs the media collaborators.** `new ThumbnailGenerator(...)` and `new MediaMetadataExtractor()` in the constructor (`FileUploader.php:66-67`), with `MediaMetadataExtractor` typed as a concrete property (`:54`). `uploadMedia()` calls `$this->metadataExtractor->extract()` (`:127`) and `maybeGenerateThumbnail()` → `$this->thumbnailGenerator` (`:135,:572-598`). The metadata fields (`type/width/height/durationSeconds`) shape the whole result array (`:144-155`).
3. **`UploadController` calls `ImageProcessor` statically.** On-demand variant serving uses `ImageProcessor::make($temp, ...)` + `applyResizeOptions()` (`UploadController.php:18,322-324,487-522`); the controller type-hints the concrete `ImageProcessor` in a private method signature (`:487`).
4. **The `image()` helper resolves the concrete interface.** `src/helpers.php:479-485` does `app($context, ImageProcessorInterface::class)::make($source)` — currently always resolvable.
5. **Framework boot pokes the processor.** `Framework.php:332` calls `ImageProcessor::setContext($this->context)` during core-service init (`Framework.php:36,324-332`).
6. **Config + env live in core.** `config/image.php` (full `IMAGE_*` surface), thumbnail keys in `config/filesystem.php:74-84` (`THUMBNAIL_*`) and `config/uploads.php:43-58` (`UPLOADS_IMAGE_PROCESSING`, `UPLOADS_THUMBNAILS`).

## Guardrails

- **G1 — Uploads must keep working with zero rich-media deps.** After extraction, with `glueful/media` *not* installed, a plain file upload (`UploadController::upload` → `FileUploader::uploadMedia`) must still store the file, persist the blob row, and return a usable result. No fatal, no class-not-found. (This is the load-bearing guarantee.)
- **G2 — Absence is a clean no-op/fallback, never a silent lie.** With no media processor bound: thumbnail = `null`, metadata = a minimal `MediaMetadata` derived from MIME alone (`type` only, no dimensions/duration), variant endpoint returns the original bytes or a clear 415/501 — never a corrupt/empty image. Behavior is explicit per operation.
- **G3 — One seam, container-resolved, optional.** Core defines a single `MediaProcessorInterface` and consumes it only if bound. No `class_exists()` sniffing of Intervention in core (`ThumbnailGenerator.php:121` is exactly the anti-pattern we're deleting). The extension binds the real implementation.
- **G4 — Don't break the upload/storage primitive.** Storage drivers, `StorageManager`, `UrlGenerator`, `BlobRepository`, validation (`ImageSecurityValidator` is MIME/dimension/size policy — *not* an Intervention type), temp/private storage, signed URLs (`SignedUrl`), and the `serveFile()`/Range/ETag path (`UploadController.php:350-482`) all stay in core untouched.
- **G5 — Breaking changes are allowed but must be documented.** Framework is public with few users; per policy we take the clean break now (move whole classes, remove deps) and ship explicit upgrade notes. No back-compat shims for the moved concrete classes.
- **G6 — No provider-specific knowledge leaks back into core.** Encoder/driver/format specifics (`JpegEncoder`, `Direction`, GD/Imagick selection) live entirely in the extension behind the interface.
- **G7 — Config moves with its owner, but embedded keys stay put.** `config/image.php` is 100% media and **moves** to the extension (merged via `mergeConfig('image', …)`), with `IMAGE_*` env documented as extension-owned. The thumbnail / image-processing keys **embedded inside the shared core files** (`config/filesystem.php:74-84`, `config/uploads.php:43-58`) **stay in those core files** — splitting them out is churn for no gain — and simply become **media-gated no-ops** (the code reading them moved) unless `glueful/media` is installed. Consistent with Decision §6.

## Files: move vs stay

| File | Decision | Rationale |
|---|---|---|
| `src/Services/ImageProcessor.php` | **MOVE** → `Glueful\Extensions\Media\ImageProcessor` | Sole consumer of `intervention/image`; pure rich processing. |
| `src/Services/ImageProcessorIntegration.md` | **MOVE/REWRITE** → extension | Doc that lives beside `ImageProcessor` describing the in-core processor/`image()` pattern; moves with it (or is rewritten as the extension README). Must not be left in core describing a moved class. See Decisions §10. |
| `src/Services/ImageProcessorInterface.php` | **MOVE** → `Glueful\Extensions\Media\Contracts\ImageProcessorInterface` | Fluent transformation API — *not* the core seam. The core seam is the new minimal `MediaProcessorInterface` (below). Keeping this fat fluent interface in core would re-introduce the coupling we're removing. See Decisions §2. |
| `src/Services/ImageSecurityValidator.php` | **STAY** (in core) | No Intervention dependency (`grep` confirms only `BusinessLogicException`); upload *validation* policy (dimensions/MIME/size/URL allowlist) — a primitive. **But its only binding today is in `ImageProvider` (`:46-51`), which moves** — so core must add its own binding (see Rewiring → new core binding, and Decisions §4). |
| `src/Container/Providers/ImageProvider.php` | **MOVE** → extension `ServiceProvider::services()` | Builds `ImageManager` + processor; belongs with the impl. Remove from `ContainerFactory.php:141`. |
| `src/Uploader/ThumbnailGenerator.php` | **MOVE** → `Glueful\Extensions\Media\ThumbnailGenerator` | Uses `ImageProcessor`; the `class_exists` guard (`:121`) is replaced by the `MediaProcessorInterface` seam. |
| `src/Uploader/MediaMetadataExtractor.php` | **MOVE** → `Glueful\Extensions\Media\MediaMetadataExtractor` | Sole consumer of `getid3`. |
| `src/Uploader/MediaMetadata.php` | **STAY** (in core) | Plain readonly value object, zero deps (`MediaMetadata.php` — no imports). It is the *return contract* of the seam; both core (no-op path) and the extension construct it. Moving it would force core to depend on the extension. See Decisions §3. |
| `src/Uploader/FileUploader.php` | **STAY, rewired** | Upload/storage primitive. Constructor stops `new`-ing media collaborators; takes an optional `MediaProcessorInterface`. |
| `src/Controllers/UploadController.php` | **STAY, rewired** | Upload endpoint primitive. Variant serving goes through the seam; remove `use ImageProcessor`. |
| `src/helpers.php` `image()` | **MOVE** (helper relocates to extension) | See Decisions §5. |
| `src/Framework.php:332` | **STAY, edited** | Remove the `ImageProcessor::setContext()` call from core boot. |
| `config/image.php` | **MOVE** → extension, merged via `mergeConfig('image', …)` | |
| `config/filesystem.php:74-84` thumbnail keys, `config/uploads.php:43-58` image keys | **STAY in core files, documented as media-gated** | These files also hold non-media upload config; the media keys become no-ops without the extension (consumed by moved code). Extension may `mergeConfig` defaults. See Decisions §6. |
| `composer.json` `intervention/image`, `james-heinrich/getid3` (`:29-30`) | **MOVE** to extension `require` | Removed from core `require`. |

**New in core:** `src/Uploader/Contracts/MediaProcessorInterface.php` — the seam, in the **`Glueful\Uploader\Contracts`** namespace (core-owned, alongside the `MediaMetadata` VO and `StorageInterface` it references). It is deliberately **not** under `Glueful\Extensions\Media\*` — that root belongs entirely to the extension, so core and the extension never share a namespace (see Decisions §9).

## Core seam: `MediaProcessorInterface`

A **narrow, upload-facing** interface — only the operations `FileUploader` and `UploadController` actually need, not the 30-method fluent `ImageProcessorInterface`. Grounded in the real call sites:

- `FileUploader::uploadMedia()` needs: **metadata extraction** (`:127`) and **thumbnail generation** (`:135` → `:572-598`).
- `UploadController::serveResizedImage()` needs: **on-demand variant bytes** from a source path with width/height/quality/format/fit options (`:320-339`, options assembled in `applyResizeOptions()` `:487-522`).

```php
namespace Glueful\Uploader\Contracts;          // core-owned (see Decisions §9)

use Glueful\Uploader\MediaMetadata;            // stays in core
use Glueful\Uploader\Storage\StorageInterface; // stays in core

interface MediaProcessorInterface
{
    /**
     * Extract type/dimensions/duration from a stored-or-temp file.
     * Implementations may use getID3 / getimagesize.
     */
    public function extractMetadata(string $filepath, string $mimeType): MediaMetadata;

    /**
     * Generate a thumbnail (writing it through the caller's storage) and return its
     * public URL, or null if the MIME type is unsupported or generation failed.
     * The processor MUST write via the passed-in $storage so the thumb lands on the
     * same disk the upload used — it never constructs its own storage.
     */
    public function generateThumbnail(
        StorageInterface $storage,
        string $sourcePath,
        string $storagePath,
        string $originalFilename,
        array $options = []          // width,height,quality,subdirectory
    ): ?string;

    /** True if a thumbnail can be produced for this MIME type. */
    public function supportsThumbnail(string $mimeType): bool;

    /**
     * Render an on-demand variant; returns encoded bytes + mime.
     * $options: width,height,quality,format,fit (contain|cover|fill).
     * @return array{data: string, mime: string}
     */
    public function renderVariant(string $sourcePath, array $options): array;
}
```

Notes:
- `MediaMetadata` (core value object) is the return type, so core has no compile-time dependency on the extension — only on its own VO.
- The extension's `MediaProcessor` composes the moved `ThumbnailGenerator` + `MediaMetadataExtractor` + `ImageProcessor` behind this interface. `generateThumbnail()` takes the upload's `StorageInterface` **as an explicit parameter** (not constructed by the extension), so the thumb lands on the same disk the upload used — `FileUploader` passes its own `$this->storage` through (see rewiring).

### Absent-processor behavior (the no-op)

Core ships a private/default null behavior **inline in `FileUploader`** (no separate Null class needed in the public API): when no `MediaProcessorInterface` is bound, `uploadMedia()`:
- skips thumbnailing → `thumb_url = null`;
- derives a minimal `MediaMetadata` from MIME prefix only (`type` via `str_starts_with($mime, 'image/'|'video/'|'audio/')`, else `'file'`; width/height/duration `null`) — this duplicates the trivial MIME→type mapping that already lives in `MediaMetadataExtractor::determineMediaType()` (`:51-59`), which is cheap and dependency-free;
- still stores the file and persists the blob (G1).

`UploadController::show()` with resize params and no processor: skip the variant branch (`:171`) and serve the **original** via `serveFile()` (already the fallback when `uploads.image_processing.enabled` is false), or return `501 Not Implemented` if a `format`/`fit` was explicitly requested — recommend **serve original** for `width/height/quality` (graceful) and **415/501** only when a format conversion is explicitly requested and impossible. (Decision §7.)

## How core is rewired

### `FileUploader` (`src/Uploader/FileUploader.php`)
- Remove `private ThumbnailGenerator $thumbnailGenerator;` / `private MediaMetadataExtractor $metadataExtractor;` (`:53-54`) and the two `new` calls (`:66-67`).
- Add `private ?MediaProcessorInterface $media = null;` constructor-injected (nullable, last param) so existing positional callers still work; `StorageProvider.php:54-68` resolves it from the container *if bound* (e.g. `$c->has(MediaProcessorInterface::class) ? $c->get(...) : null`).
- `uploadMedia()`:
  - `:127` → `$metadata = $this->media?->extractMetadata($file['tmp_name'], $mime) ?? $this->minimalMetadata($mime);`
  - `maybeGenerateThumbnail()` (`:572-598`) → delegate to `$this->media?->generateThumbnail($this->storage, $sourcePath, $storagePath, $originalFilename, $opts)` — **passing the uploader's own `$this->storage`** so the thumb writes to the same disk; gate on `$this->media !== null && $this->media->supportsThumbnail($mime)`; return `null` otherwise. The `class_exists(ImageProcessor::class)` guard moves *out* of core entirely.
- Remove the public `getThumbnailGenerator()` / `getMetadataExtractor()` accessors (`:315-326`) **or** retype them to the seam — recommend removing (they leak moved concrete types; no internal callers found). Documented as a breaking change.

### `UploadController` (`src/Controllers/UploadController.php`)
- Remove `use Glueful\Services\ImageProcessor;` (`:18`).
- Inject `?MediaProcessorInterface $media` via the constructor (`StorageProvider.php:71-80` resolves optionally).
- `serveResizedImage()` (`:320-339`): replace `ImageProcessor::make($temp,…)` + `applyResizeOptions()` + `getImageData()`/`getMimeType()` with one `$this->media->renderVariant($temp, $resize+limits)` call returning `['data','mime']`. The `max_width`/`max_height` clamping currently in `applyResizeOptions()` (`:489-500`) moves *into* the options passed to `renderVariant`, computed from `uploads.image_processing.*` config (which stays in core config).
- The guard at `:171` becomes `$isImage && $resize !== null && $this->media !== null && (bool) config('uploads.image_processing.enabled')` — when `$this->media` is null, fall through to `serveFile()` (serve original). Drop `applyResizeOptions()` (its logic lives in the extension's `renderVariant`).

### `StorageProvider` (`src/Container/Providers/StorageProvider.php:54-80`)
- `FileUploader` and `UploadController` factories additionally pass the optionally-resolved `MediaProcessorInterface`. The extension's `ServiceProvider` binds the concrete `MediaProcessor`; core never binds it.

### `Framework.php:332`
- Delete `ImageProcessor::setContext($this->context);`. The extension's `ServiceProvider::boot()` sets context on its own processor if it keeps the static-factory convenience.

### `ContainerFactory.php:141`
- Remove `ImageProvider::class` from the core provider list.

### New core binding for `ImageSecurityValidator` (must-fix)
`ImageSecurityValidator` stays in core but its **only** binding today is the `FactoryDefinition` in `ImageProvider` (`:46-51`, building it from `config('security')` + `config('limits')`) — which is moving. Without a replacement, resolving `ImageSecurityValidator::class` fails after extraction. **Move that one `FactoryDefinition` into a core provider** — `StorageProvider` is the natural home (it already owns the upload/storage graph the validator serves). The extension's `ImageProcessor` then resolves the validator from core unchanged. (Alternative considered and rejected: have the extension construct it from core config itself — that makes the extension responsible for building a core-owned class, which is the wrong ownership direction. Decision §4.)
- Note: currently no core code *other than the moving `ImageProcessor`* consumes the validator (`FileUploader` does not). It stays in core as the upload-validation primitive the upload boundary *should* use, and that the extension reuses — but if a future review decides it should instead move with the image stack, that's a clean alternative; this spec keeps it in core per Decisions §4.

## Extension layout: `glueful/media`

```
glueful/media/
  composer.json            # canonical Glueful extension manifest; require intervention/image ^4.1 + james-heinrich/getid3 ^1.9; glueful/framework in require-dev; extra.glueful.provider = Glueful\Extensions\Media\MediaServiceProvider
  src/
    MediaServiceProvider.php
    Contracts/ImageProcessorInterface.php     # moved fluent interface (Glueful\Extensions\Media\Contracts)
    ImageProcessor.php                         # moved (Glueful\Extensions\Media\); resolves ImageSecurityValidator from CORE
    ImageProcessorIntegration.md               # moved alongside ImageProcessor (or rewritten as the extension README)
    ThumbnailGenerator.php                     # moved
    MediaMetadataExtractor.php                 # moved
    MediaProcessor.php                         # implements core Glueful\Uploader\Contracts\MediaProcessorInterface
  config/image.php          # moved from core
  helpers.php              # image() helper (autoloaded via composer "files")
```

> `ImageSecurityValidator` is **not** in this layout — it **stays in core** (see Decisions §4). The extension's `ImageProcessor` resolves it from the container (`$c->get(\Glueful\Services\ImageSecurityValidator::class)`), exactly as today.

`MediaServiceProvider` (`Glueful\Extensions\Media\MediaServiceProvider`):
- `services()` returns DI defs for `ImageManager` (driver selection, moved from `ImageProvider.php:19-43`), `ImageProcessorInterface`/`ImageProcessor`, and `MediaProcessorInterface => MediaProcessor` (shared). `ImageSecurityValidator` is resolved from **core**.
- `register()`: `mergeConfig('image', require __DIR__.'/config/image.php')`; optionally merge thumbnail defaults.
- `boot()`: if keeping the static `ImageProcessor::make()` convenience, call `ImageProcessor::setContext($context)` here (replacing the deleted `Framework.php:332`).
- `composer.json` `extra.glueful.provider` points at `Glueful\Extensions\Media\MediaServiceProvider`; `require` carries the two heavy runtime deps (`intervention/image`, `james-heinrich/getid3`); `glueful/framework` sits in `require-dev` (the host provides it at runtime). See the plan for the full canonical manifest.

## Decisions

1. **Whole rich-media stack moves; only the value object + the validator stay.** The clean seam is a *new narrow* `MediaProcessorInterface` in core, not the existing fluent interface. (G3, G5)
2. **`ImageProcessorInterface` moves (does NOT stay as the seam).** It is a 30-method fluent transformation API tightly bound to image semantics; leaving it in core would re-import the very coupling we're removing and tempt callers back onto it. The core seam is the 4-method upload-facing `MediaProcessorInterface`. (G3, G6)
3. **`MediaMetadata` stays in core** as the seam's return contract — it is a zero-dependency readonly VO and is *documented in CLAUDE.md* as the public shape `uploadMedia()` returns. Core's no-op path constructs it directly. (G2)
4. **`ImageSecurityValidator` stays in core — and core must add its own binding for it.** It has no Intervention dependency and is upload-validation policy (a primitive); the extension resolves it from core (passed into the moved `ImageProcessor` ctor, as today at `ImageProvider.php:75`). **Because its sole binding lives in the moving `ImageProvider` (`:46-51`), that `FactoryDefinition` must relocate into a core provider (`StorageProvider`)** — otherwise `ImageSecurityValidator::class` is unresolvable post-move. Rejected alternative: extension builds it from core config (wrong ownership direction). (Caveat: today only the moving `ImageProcessor` consumes it; kept in core as the upload-validation primitive — a defensible call, with "move it with the image stack" as the clean alternative if revisited.)
5. **The `image()` helper moves to the extension** (registered via the extension's `composer.json` `autoload.files`). Rationale: the current helper unconditionally resolves `ImageProcessorInterface` (`helpers.php:483`); if it stayed in core it would have to throw or feature-detect, which is worse DX than the function simply not existing until `glueful/media` is installed. A call to `image()` without the extension becomes an undefined-function error with a clear upgrade note — preferable to a runtime "image processing not installed" exception masquerading as a helper. (Documented in upgrade notes; flagged as breaking.)
6. **Image/thumbnail config keys stay in their existing core files but become media-gated.** `config/filesystem.php:74-84` and `config/uploads.php:43-58` mix media keys with general upload config; splitting them is churn for no gain. They simply have no effect without the extension (the code that reads them moved). `config/image.php` moves wholesale (it is 100% media). The extension `mergeConfig`s `image` defaults so `IMAGE_*` env keeps working once installed. (G7)
7. **Variant-endpoint absence = serve original for size/quality; 415 only for impossible format conversion.** Graceful by default; explicit only when the request cannot be honored. (G2)
8. **No back-compat shims for moved classes.** `Glueful\Services\ImageProcessor`, `Glueful\Uploader\ThumbnailGenerator`, `Glueful\Uploader\MediaMetadataExtractor` are gone from core; apps/extensions referencing them must `composer require glueful/media` and update namespaces. (G5)
9. **Core seam lives in `Glueful\Uploader\Contracts`, not `Glueful\Extensions\Media`.** The seam (`MediaProcessorInterface`) is core-owned and sits beside the `MediaMetadata` VO and `StorageInterface` it references (`Glueful\Uploader\*`). `Glueful\Extensions\Media\*` is owned **exclusively by the extension** — so the two packages never share a namespace root (no PSR-4 autoload ambiguity, clean ownership). Chosen over `Glueful\Extensions\Media\Contracts\MediaProcessorInterface`, which would have split the `Glueful\Extensions\Media` namespace across core + extension.
10. **`src/Services/ImageProcessorIntegration.md` moves/rewrites with the code.** It documents the in-core processor + `image()` pattern; leaving it in core would describe a class that no longer exists there. It moves into `glueful/media` (or is rewritten as the extension README). Listed in the files table + upgrade-notes doc list alongside CLAUDE.md.

## Upgrade notes (for the changelog / UPGRADE doc)

- **Uploads and storage are unchanged.** Plain file upload, blob storage, visibility, signed URLs, Range/ETag serving all keep working with no new dependency.
- **Image processing, thumbnails, and media metadata now require `composer require glueful/media`.** Without it:
  - `uploadMedia()` returns `thumb_url: null` and metadata limited to `type` (no `width`/`height`/`duration_s`);
  - the on-demand resize query params on `GET /blobs/{uuid}` serve the original image (or `415` for an explicit format conversion);
  - the `image()` global helper is undefined;
  - `ImageProcessor`, `ThumbnailGenerator`, `MediaMetadataExtractor` are not autoloadable from `Glueful\Services\*` / `Glueful\Uploader\*`.
- **Namespace changes** (after installing `glueful/media`): `Glueful\Services\ImageProcessor` → `Glueful\Extensions\Media\ImageProcessor`; `Glueful\Services\ImageProcessorInterface` → `Glueful\Extensions\Media\Contracts\ImageProcessorInterface`; `Glueful\Uploader\ThumbnailGenerator` → `Glueful\Extensions\Media\ThumbnailGenerator`; `Glueful\Uploader\MediaMetadataExtractor` → `Glueful\Extensions\Media\MediaMetadataExtractor`. `Glueful\Uploader\MediaMetadata` is **unchanged** (stays in core).
- **Removed public methods:** `FileUploader::getThumbnailGenerator()` / `getMetadataExtractor()`.
- **Config/env:** `IMAGE_*` (and `config/image.php`) are now owned by `glueful/media`; they have no effect until it is installed. `THUMBNAIL_*` and `UPLOADS_THUMBNAILS`/`UPLOADS_IMAGE_PROCESSING` likewise gate moved code.
- **Removed core dependencies:** `intervention/image`, `james-heinrich/getid3` — leaner default install (smaller vendor tree, no GD/Imagick requirement to boot).
- **Docs to update/move:** **CLAUDE.md** Image Processing and "File Uploads & Blob Storage → Media Metadata Extraction" sections must note the extension requirement; **`src/Services/ImageProcessorIntegration.md`** moves into `glueful/media` (or is rewritten as its README) — it must not remain in core describing a moved class (Decisions §10).

## Verification (required coverage)

The implementation plan must include these.

**1. Core-only (extension absent — the G1/G2 gate):**
- core boots with **no** `intervention/image`, `james-heinrich/getid3`, and on a host with **neither GD nor Imagick** — no fatal at container build (the `ImageProvider` hard-failure at `:27,36` is gone);
- `ImageSecurityValidator::class` still **resolves** from the container (the relocated core binding — new core binding step);
- `uploadMedia()` stores the file, persists the blob, and returns `thumb_url: null` with **type-only** `MediaMetadata` (no `width`/`height`/`duration_s`);
- `GET /blobs/{uuid}` with `width/height/quality` serves the **original** bytes; an explicit `format`/`fit` conversion returns the chosen `415/501` (Decision §7);
- a grep of runtime/core source (`src/`, `config/`, `routes/` — excluding `docs/`/specs/test fixtures) finds **no** references to `ImageProcessor`, `ThumbnailGenerator`, `MediaMetadataExtractor`, or `ImageProvider`, and core `composer.json` no longer requires `intervention/image` or `james-heinrich/getid3`;
- the `image()` global helper is **undefined** (function-not-found), per Decision §5.

**2. Extension installed (`glueful/media`):**
- `MediaProcessorInterface` resolves to the extension's `MediaProcessor`; `FileUploader`/`UploadController` pick it up via optional resolution;
- `uploadMedia()` generates a thumbnail — and the thumb is written through the **uploader's `StorageInterface`** (assert it lands on the same disk/path prefix the upload used, proving the storage param is honored) — and extracts full metadata (dimensions/duration);
- `renderVariant()` produces resized bytes + correct mime for the on-demand endpoint;
- the `image()` helper exists and resolves the fluent `ImageProcessor`;
- the extension's `ImageProcessor` resolves `ImageSecurityValidator` from **core** (not a duplicate).

## Risks

- **Highest call-site surface of the planned extractions.** Four distinct integration points (DI provider, `image()` helper, `FileUploader::uploadMedia`, `UploadController` variant serving) plus `Framework.php` boot. Mitigation: the narrow `MediaProcessorInterface` + optional resolution localizes every change; each call site has a defined absent-behavior.
- **`uploadMedia()` result-shape regression.** Downstream code reading `width`/`height`/`duration_s` (`FileUploader.php:150-152`) gets `null` when media isn't installed. This is intended (G2) but is a behavioral change apps must expect — call it out prominently.
- **Boot-time hard failure removed is a feature, but watch DI graph.** Today missing GD/Imagick throws at `ImageProvider` build (`:27,36`). After extraction core never builds that graph; the failure surfaces only inside the extension when actually processing — verify the extension surfaces a clear error (not a deep Intervention stack trace).
- **`generateThumbnail()` storage dependency — resolved in the contract.** The moved `ThumbnailGenerator` takes `StorageInterface` + context today (`ThumbnailGenerator.php:39-43`); the seam now passes `StorageInterface` as an **explicit first parameter** of `generateThumbnail()` and `FileUploader` hands its own `$this->storage` through (rewiring), so the extension never constructs storage and thumbs land on the upload's disk. Verified by the extension-installed test (Verification §2). (Was a gap in v1.)
- **Tests:** no framework tests reference these classes today (`grep tests/` empty), so core test churn is low; but the extension needs its own suite (GD-driver image round-trip, getID3 metadata, thumbnail URL) and core needs a no-processor `uploadMedia` test proving G1/G2.
- **Two configs reference image processing from non-media files** (`config/uploads.php`, `config/filesystem.php`) — leaving keys in place avoids churn but means "where does image config live" is split (core files own the keys, extension owns `image.php` + the code). Documented in Decisions §6; acceptable.
