# Extract Rich Media → `glueful/media` — Implementation Plan

> **For agentic workers:** implement task-by-task with TDD (failing test first, run it red, implement, run it green, commit). Each task is independently green and reviewable; don't start a task before the previous is green. Design spec (authoritative — do not re-litigate its Decisions): `docs/superpowers/specs/2026-06-06-extract-media-design.md`.

**Goal:** Move rich media processing (image transformations/variants, thumbnail generation, media metadata extraction) and its two heavy deps (`intervention/image`, `james-heinrich/getid3`) out of framework core into a new `glueful/media` extension, behind one narrow core seam — `Glueful\Uploader\Contracts\MediaProcessorInterface` — that core consumes *only if bound*. Core must still boot and serve plain uploads with **zero** rich-media deps and no GD/Imagick present.

**Compatibility / Tech:** **Intentional clean break, shipped in a coordinated breaking release** — the framework is past 1.0 and no backward compatibility is chosen for these extractions (spec G5); no back-compat shims. Moved classes leave their old `Glueful\Services\*` / `Glueful\Uploader\*` namespaces; the `image()` helper and `FileUploader::getThumbnailGenerator()/getMetadataExtractor()` are removed from core. PHP 8.3, PHPUnit 10.5; framework/library tests extend `PHPUnit\Framework\TestCase` with the lightweight SQLite/container harness (no `App\Tests\TestCase`). Verification gate per task: `composer test` (targeted green), `composer run analyse` (no new PHPStan errors), `composer run phpcs`.

> **Core stays green every task — copy-first, then one atomic removal.** The Phase A/B work is **in-place core refactoring**: it adds the `MediaProcessorInterface` seam, relocates the `ImageSecurityValidator` binding into `StorageProvider`, and rewires `FileUploader`/`UploadController`/`StorageProvider` onto the *optional* seam with inline no-op fallbacks — all **while the heavy deps and the moved classes are still present in core**. Those are incremental green core commits (NOT copy-first); they are precisely what lets core stay green when the removal lands. The Phase D tasks that **populate the `glueful/media` extension** **COPY** the rich-media classes (`ImageProcessor`, `ImageProcessorInterface`, `ThumbnailGenerator`, `MediaMetadataExtractor`), `config/image.php`, and the `ImageProcessorIntegration.md` doc into the new package — the **core originals stay untouched**, so core compiles and its full suite passes after every Phase D task (the extension is built/tested standalone against the framework as a dependency). **Task C1 is the single, atomic core-removal commit** that deletes all the now-duplicated core sources (including the now-unused `ImageProvider` class file), drops the two heavy deps from core `require`, deletes `config/image.php`, and removes the `image()` block from core `src/helpers.php` — all together. (Note: the `ImageProvider` *registration* is removed from `ContainerFactory` earlier, in the in-place **Task B4** — that's what makes core stop building the media graph while staying green; C1 only deletes the now-orphaned provider class file.) The `image()` helper is **not a file move**: it lives inside the shared `src/helpers.php`, so it is *deleted from* core's `helpers.php` (in the atomic removal) and *re-authored* in the extension's own `helpers.php` (Phase D). At no point is core left referencing a class that no longer exists. **Sequencing:** Phase A/B (in-place seam + rewiring, green each step) → Phase D (copy classes/config/helper into the extension, core untouched) → **Task C1 (single atomic core-removal)**. Phase D is authored alongside the Phase A/B PR so cross-references resolve, but the atomic core deletion only lands after the extension is proven green.

**Grounding note (verified during planning):** the four core call sites in the spec are exhaustive — `grep` of `src/ config/ routes/` finds **no** consumer of `ImageProcessor`/`ThumbnailGenerator`/`MediaMetadataExtractor` beyond the DI provider (`ImageProvider`), the `image()` helper (`helpers.php:479`), `FileUploader` (`:53-54,66-67,127,135,315-326,572-598`), and `UploadController` (`:18,322-325,487-522`), plus the `Framework.php:332` boot poke. **One extra wiring detail not called out in the spec body:** `UploadController::upload()` also constructs a `FileUploader` *directly* at `:94` (`new FileUploader(storageDriver: $disk, context: ...)`) for the non-default-disk path — that ad-hoc instance will silently get a `null` media processor (no-op) unless rewired, so Task B3 must address it. No framework tests reference any of these classes today (`grep tests/` empty), so core test churn is additive.

---

## Phase A — Core: introduce the seam, no behavior change yet

### Task A1 — Add the core seam `MediaProcessorInterface`

**Create**
- `src/Uploader/Contracts/MediaProcessorInterface.php` — namespace `Glueful\Uploader\Contracts`. Imports `Glueful\Uploader\MediaMetadata` and `Glueful\Uploader\Storage\StorageInterface` (both stay in core). Four methods exactly as the spec §"Core seam":
  - `public function extractMetadata(string $filepath, string $mimeType): MediaMetadata;`
  - `public function generateThumbnail(StorageInterface $storage, string $sourcePath, string $storagePath, string $originalFilename, array $options = []): ?string;` — **`StorageInterface` is an explicit first param** (caller passes its own storage; processor never constructs storage).
  - `public function supportsThumbnail(string $mimeType): bool;`
  - `public function renderVariant(string $sourcePath, array $options): array;` — `@return array{data: string, mime: string}`.
- `tests/Unit/Uploader/Contracts/MediaProcessorInterfaceTest.php`

**Steps**
- [ ] Write failing test: an in-suite fake implementing `MediaProcessorInterface` satisfies the contract — assert `interface_exists(MediaProcessorInterface::class)`, that a fake returns a `MediaMetadata` from `extractMetadata`, a `?string` from `generateThumbnail` (given a stub `StorageInterface`), a `bool` from `supportsThumbnail`, and `['data'=>..., 'mime'=>...]` from `renderVariant`. Run → red (interface missing).
- [ ] Create the interface file with full PHPDoc (copy the seam block from the spec verbatim, including the `MUST write via the passed-in $storage` note on `generateThumbnail`).
- [ ] Run test → green.
- [ ] `composer run analyse` + `composer run phpcs` → clean. Commit.

**Rollback risk:** None — pure addition, no consumer yet.

---

### Task A2 — Relocate the `ImageSecurityValidator` binding into core (`StorageProvider`)

`ImageSecurityValidator` **stays in core** (Decisions §4) but its *only* binding today is the `FactoryDefinition` in the moving `ImageProvider` (`:46-51`). Without a replacement, `ImageSecurityValidator::class` becomes unresolvable after `ImageProvider` is removed. Move that one factory into `StorageProvider` (it owns the upload/storage graph the validator serves).

**Modify**
- `src/Container/Providers/StorageProvider.php` — add a `FactoryDefinition` for `\Glueful\Services\ImageSecurityValidator::class`, copied from `ImageProvider.php:46-51`:
  ```php
  $security = function_exists('config') ? (array) config($this->context, 'image.security', []) : [];
  $limits   = function_exists('config') ? (array) config($this->context, 'image.limits', []) : [];
  return new \Glueful\Services\ImageSecurityValidator(array_merge($security, $limits));
  ```
  (Reads `image.security`/`image.limits` — those keys remain readable from the extension-merged `image` config once `glueful/media` is installed; absent the extension they default to `[]`, which `ImageSecurityValidator` already tolerates. Validator behavior is unchanged.)

**Create**
- `tests/Unit/Container/Providers/StorageProviderImageValidatorTest.php`

**Steps**
- [ ] Write failing test: build a container from `StorageProvider` (lightweight container harness, config returning empty `image.*`) and assert `$c->get(\Glueful\Services\ImageSecurityValidator::class)` returns an `ImageSecurityValidator` instance. Run → red (no binding in `StorageProvider` yet; `ImageProvider` still owns it but this test targets `StorageProvider` in isolation).
- [ ] Add the `FactoryDefinition` to `StorageProvider::defs()`.
- [ ] Run test → green.
- [ ] `composer test` (targeted) + `analyse` + `phpcs` → clean. Commit.

> Note: at this point the validator is bound by **both** `StorageProvider` and `ImageProvider`; that's transient and harmless (idempotent factory). The `ImageProvider` *registration* is removed in **Task B4**; the provider *class file* is deleted in **Task C1**. Do **not** remove the validator from `ImageProvider` here — Phase A keeps the old graph working so each task stays green.

**Rollback risk:** Low — additive binding; revert = delete the factory.

---

## Phase B — Core: rewire consumers onto the optional seam (with inline no-op)

### Task B1 — `FileUploader`: optional `MediaProcessorInterface`, inline no-op fallback

**Modify** `src/Uploader/FileUploader.php`
- Remove fields `private ThumbnailGenerator $thumbnailGenerator;` and `private MediaMetadataExtractor $metadataExtractor;` (`:53-54`).
- Remove the two `new` calls in the ctor (`:66-67`).
- Add a nullable **last** constructor param `?\Glueful\Uploader\Contracts\MediaProcessorInterface $media = null` (so existing positional callers — `StorageProvider:54-68` and the ad-hoc `UploadController:94` — keep compiling), stored as `private ?MediaProcessorInterface $media`.
- `uploadMedia()`:
  - `:127` → `$metadata = $this->media?->extractMetadata($file['tmp_name'], $mime) ?? $this->minimalMetadata($mime);`
  - rewrite `maybeGenerateThumbnail()` (`:572-598`): drop the `$this->thumbnailGenerator` calls; gate on `$this->media !== null && $this->media->supportsThumbnail($mime)` (still honoring the `filesystem.uploader.thumbnail_enabled` global and the `generate_thumbnail` per-upload option); when generating, call `$this->media->generateThumbnail($this->storage, $sourcePath, $storagePath, $filename, [...])` — **passing `$this->storage`** so the thumb lands on the upload's disk; return `null` otherwise.
- Add `private function minimalMetadata(string $mime): MediaMetadata` — maps MIME prefix to type (`image/`→`image`, `video/`→`video`, `audio/`→`audio`, else `file`) and returns `new MediaMetadata($type)` (width/height/duration `null`). This is the same trivial mapping as `MediaMetadataExtractor::determineMediaType()` (`:51-59`), now duplicated in core (cheap, dependency-free) per spec §"Absent-processor behavior".
- Remove the public accessors `getThumbnailGenerator()` (`:315-318`) and `getMetadataExtractor()` (`:320-326`) — they leak moved concrete types and have no internal callers (verified). Documented breaking change.

**Create**
- `tests/Unit/Uploader/FileUploaderNoMediaTest.php`

**Steps**
- [ ] Write failing test: construct `FileUploader` with `$media = null` (use the lightweight container/storage harness — in-memory/local flysystem disk). Call `uploadMedia()` with a small fake image file; assert it (a) stores the file (file exists on disk), (b) returns `thumb_url === null`, (c) returns **type-only** metadata: `result['type'] === 'image'`, `result['width'] === null`, `result['height'] === null`, `result['duration_s'] === null`, (d) persists a blob row when `save_to_blobs` true. Run → red (still `new`-ing `MediaMetadataExtractor`, so width/height would be populated and the field/ctor changes don't exist).
- [ ] Apply the modifications above.
- [ ] Run test → green.
- [ ] Add a second test asserting `method_exists(FileUploader::class, 'getThumbnailGenerator') === false` and same for `getMetadataExtractor` (locks the removal). Run → green.
- [ ] `analyse` + `phpcs` → clean. Commit.

**Rollback risk:** Medium — touches the upload happy path and removes public methods. Mitigated: the no-op path is fully unit-covered; revert = restore the two fields, `new`s, accessors, and the direct `metadataExtractor->extract()`/`thumbnailGenerator` calls.

---

### Task B2 — `StorageProvider`: pass the optionally-resolved processor into `FileUploader`

**Modify** `src/Container/Providers/StorageProvider.php`
- In the `FileUploader` `FactoryDefinition` (`:54-68`), change the closure to receive the container and pass the optional media processor as the new last arg:
  ```php
  function (\Psr\Container\ContainerInterface $c): \Glueful\Uploader\FileUploader {
      // ... existing $uploadsDir / $cdnBaseUrl / $disk ...
      $media = $c->has(\Glueful\Uploader\Contracts\MediaProcessorInterface::class)
          ? $c->get(\Glueful\Uploader\Contracts\MediaProcessorInterface::class)
          : null;
      return new \Glueful\Uploader\FileUploader($uploadsDir, $cdnBaseUrl, $disk, $this->context, $media);
  }
  ```
  (Confirm the container exposes `has()` — the closures already receive `ContainerInterface`; if the concrete container lacks `has`, use a `try/get`-and-catch on `NotFoundException`. Verify against `src/Container` during implementation.)

**Create**
- `tests/Unit/Container/Providers/StorageProviderFileUploaderMediaTest.php`

**Steps**
- [ ] Write failing test: build a container from `StorageProvider` with **no** `MediaProcessorInterface` bound; assert `$c->get(FileUploader::class)` resolves without error (media → null). Then bind a fake `MediaProcessorInterface` and assert the resolved `FileUploader` picks it up (prove via behavior — e.g. `uploadMedia` returns a non-null `thumb_url` produced by the fake). Run → red (factory doesn't pass `$media` yet).
- [ ] Apply the closure change.
- [ ] Run test → green. `analyse` + `phpcs`. Commit.

**Rollback risk:** Low — additive optional resolution; revert = restore the no-arg closure.

---

### Task B3 — `UploadController`: route variant serving through the seam; serve-original fallback

**Modify** `src/Controllers/UploadController.php`
- Remove `use Glueful\Services\ImageProcessor;` (`:18`).
- Add ctor param `?\Glueful\Uploader\Contracts\MediaProcessorInterface $media = null`, stored as `private ?MediaProcessorInterface $media` (`:33-46`).
- Guard at `:171` → `if ($isImage && $resize !== null && $this->media !== null && (bool) $this->getConfig('uploads.image_processing.enabled', true))` — when `$this->media` is null, fall through to `serveFile()` (serve **original**, per Decision §7).
- `serveResizedImage()` (`:320-344`): replace `ImageProcessor::make($temp,…)` + `applyResizeOptions()` + `getImageData()`/`getMimeType()` with a single `['data'=>$data,'mime'=>$mime] = $this->media->renderVariant($temp, $opts);`, where `$opts` merges the request `$resize` (width/height/quality/format/fit) with the `max_width`/`max_height` clamps and `default_quality` from `uploads.image_processing.*` (config stays in core). Keep the existing `max_variant_bytes` check, cache write, and `binaryResponse` flow unchanged.
- **Format/fit-conversion guard** (Decision §7): inside the `$media !== null` branch, if `$resize['format']` (or `fit` requiring conversion) is set but the processor cannot honor it, return `Response::error('...', 415)` / `501`. For width/height/quality with no processor, the `:171` guard already falls through to serve-original — no 415. Implement the explicit-format/no-processor case as: if `$resize['format'] !== null && $this->media === null`, return 415 (don't silently serve a differently-typed original).
- Delete the private `applyResizeOptions(ImageProcessor $processor, …)` method (`:487-522`) — its clamp/fit/quality/format logic moves into the extension's `renderVariant` (the controller now just assembles the options array).

**Modify** the ad-hoc `FileUploader` construction at `UploadController.php:94` — pass the optional media processor through so the non-default-disk upload path also gets thumbnails when the extension is installed:
```php
new FileUploader(storageDriver: $disk, context: $this->getContext(), media: $this->media)
```
(This is the spec-grounding gap flagged above; without it, non-default-disk uploads silently skip thumbnailing even with `glueful/media` installed.)

**Modify** `src/Container/Providers/StorageProvider.php` — the `UploadController` `FactoryDefinition` (`:71-80`) passes the optionally-resolved `MediaProcessorInterface` as the new last ctor arg (same `$c->has(...) ? $c->get(...) : null` pattern as B2).

**Create**
- `tests/Unit/Controllers/UploadControllerVariantTest.php`

**Steps**
- [ ] Write failing test (no-media): construct `UploadController` with `$media = null` and a blob fixture for an image; `GET /blobs/{uuid}?width=100` → assert it serves the **original** bytes (falls through to `serveFile`, original mime, original length) and does **not** 500. Add a case: `?format=webp` with `$media = null` → assert **415**. Run → red (controller still references `ImageProcessor` / `applyResizeOptions`).
- [ ] Apply the modifications (remove import, add ctor param, rewrite guard + `serveResizedImage`, delete `applyResizeOptions`, fix `:94` construction, update provider factory).
- [ ] Run test → green.
- [ ] Add a media-present unit case using a **fake** `MediaProcessorInterface` whose `renderVariant` returns known bytes+mime; assert `?width=100` returns those bytes and mime. Run → green.
- [ ] `analyse` + `phpcs` → clean. Commit.

**Rollback risk:** Medium — variant endpoint rewrite + two provider factories + the `:94` ad-hoc path. Mitigated by both no-media and fake-media unit coverage. Revert = restore the `ImageProcessor` import, `applyResizeOptions`, and the static `make()` call.

---

### Task B4 — Remove the `Framework.php` boot poke and the `ImageProvider` registration

**Modify**
- `src/Framework.php` — delete `ImageProcessor::setContext($this->context);` (`:332`) and remove the now-unused `use Glueful\Services\ImageProcessor;` (`:36`). (Context for the extension's processor is set in its own `ServiceProvider::boot()`.)
- `src/Container/Bootstrap/ContainerFactory.php` — remove `\Glueful\Container\Providers\ImageProvider::class,` from the core provider list (`:141`).

**Create**
- `tests/Unit/Framework/CoreBootWithoutMediaTest.php` (or extend an existing boot test) — the **G1/G2 core-only gate**, runnable even though the deps are still in `composer.json` at this point (the assertion is structural, not dep-absence; dep removal is **Task C1**):
  - container builds without registering `ImageProvider` and without poking `ImageProcessor` — assert no fatal;
  - `ImageSecurityValidator::class` still resolves (the relocated binding from A2);
  - `MediaProcessorInterface::class` is **not** bound by core (`$c->has(...) === false`).

**Steps**
- [ ] Write the failing test (assert `ImageProcessor` is no longer referenced in boot — e.g. resolving the full core container does not require the `Intervention\Image\ImageManager` binding; and `MediaProcessorInterface` unbound). Run → red (`ImageProvider` still in the list, `setContext` still called).
- [ ] Remove the `setContext` line + import; remove `ImageProvider` from the provider list.
- [ ] Run test → green. Run **full** `composer test` to catch any boot regression. `analyse` + `phpcs`. Commit.

**Rollback risk:** Medium — touches global boot + container provider set. Full-suite run is the safety net. Revert = re-add the line, import, and provider entry.

---

## Phase C — Core: the single atomic removal (runs AFTER Phase D)

### Task C1 — Atomic core-removal: delete moved classes + `image()` helper + `config/image.php`; drop deps

> **This is the single, atomic core-removal commit.** Every core deletion + edit below lands together so core goes from green (with rich media) to green (without rich media) in one step — there is no intermediate broken state, because Phases A/B already routed every consumer through the optional seam with inline no-ops, and Phase D already copied the classes/config/helper into the extension and proved them green in the extension suite. Removing the deps (`composer update`) and the classes that need them must land together so the suite stays green. **Run this only after Phase D is complete and green.**

**Delete (from core)**
- `src/Services/ImageProcessor.php`
- `src/Services/ImageProcessorInterface.php`
- `src/Container/Providers/ImageProvider.php`
- `src/Uploader/ThumbnailGenerator.php`
- `src/Uploader/MediaMetadataExtractor.php`
- `src/Services/ImageProcessorIntegration.md` (already copied into the extension in Task D1 — this deletes the core original)

**Modify**
- `src/helpers.php` — delete the entire `if (!function_exists('image')) { function image(...) {...} }` block (`:469-486`). **This is a delete-from-core, not a file move** — `image()` lives inside the shared `src/helpers.php` (it cannot be `git mv`-ed); it is *re-authored* in the extension's own `helpers.php` in Phase D (Task D1), and *deleted from* core's `helpers.php` here. Core's `composer.json` `autoload.files` keeps only `src/helpers.php` (no change to that array — the file stays, just minus the `image()` function).
- `composer.json` — remove `"intervention/image": "^4.1"` (`:29`) and `"james-heinrich/getid3": "^1.9"` (`:30`) from `require`. (Leave `config/image.php` deletion to the next bullet.)
- Delete `config/image.php` (already copied into the extension in Task D1 — this deletes the core original).

**Keep (verify untouched, now media-gated no-ops per Decisions §6):**
- `config/uploads.php:42-62` (`image_processing.*`, `thumbnails.*`) and `config/filesystem.php:68-85` (`uploader.thumbnail_*`) — stay in their core files; the code reading them moved, so they have no effect until `glueful/media` is installed. Do **not** split them out.

**User-facing docs ship IN this breaking commit (not after it)**
- `CHANGELOG.md` `[Unreleased]` — add a **Breaking Changes** entry (full content specified in Task C2). It must land **in this same commit** as the deletions — a breaking removal must never ship without its changelog/upgrade notes.
- `UPGRADE.md` (or the repo's upgrade-notes location) — append the media-extraction section: `composer require glueful/media` to restore image processing/thumbnails/metadata; without it `uploadMedia()` returns `thumb_url: null` + type-only `MediaMetadata`, the resize endpoint serves the original (415 only for explicit format conversion), and the `image()` helper is **undefined** (function-not-found — not a stub that errors); plus the namespace map. **CLAUDE.md is handled separately in C2 (local-only, unstaged).**

**Steps**
- [ ] Run `composer remove intervention/image james-heinrich/getid3` (or edit `composer.json` + `composer update`) and delete the six files above + `config/image.php` + the `image()` block.
- [ ] Write the `CHANGELOG.md` `[Unreleased]` Breaking Changes entry + the `UPGRADE.md` section (content per C2).
- [ ] Run the **core-only verification grep** as a test/CI assertion (codify in `tests/Unit/Architecture/MediaExtractionBoundaryTest.php`): grep `src/ config/ routes/` (excluding `docs/`, specs, test fixtures) finds **no** match for `ImageProcessor`, `ThumbnailGenerator`, `MediaMetadataExtractor`, or `ImageProvider`; and `composer.json` `require` no longer contains `intervention/image` or `james-heinrich/getid3`. Run → green.
- [ ] **Grep `tests/` for string-keyed references to the removed surface** — `ImageProcessor`/`ThumbnailGenerator`/`MediaMetadataExtractor`/`ImageProvider` used by string, the `image()` helper, and `image.*`/`IMAGE_*`/`THUMBNAIL_*` config keys — not just FQCNs. **(Archive precedent:** its full-suite run caught an obsolete `CapabilityMigrationsTest` referencing a removed capability *by string*, which the FQCN grep missed.) Update/remove obsolete tests in this commit.
- [ ] Run full `composer test` → green (proves core boots + uploads work with deps gone). Run `composer run analyse` — expect zero references to the removed classes; fix any stragglers. `phpcs`.
- [ ] Commit — explicitly `git add` the deleted/modified source files **+ `CHANGELOG.md` + the UPGRADE doc** together (never `git add -A`, never stage `CLAUDE.md`).

**Rollback risk:** High — this is the single, atomic breaking removal. Mitigated: Phases A/B already routed every consumer through the optional seam with inline no-ops, so by here nothing in core references the deleted classes; and Phase D already proved the copied classes/config/helper work in the extension suite. Verified by the boundary grep test + full suite. Revert = `git revert` (restores files + deps).

---

### Task C2 — Core docs: CLAUDE.md (local-only) — **same release unit as C1**

> **C1 and C2 are one release unit.** The user-facing `CHANGELOG.md` `[Unreleased]` + `UPGRADE.md` notes ship **inside the C1 breaking commit** (moved there so the break can't land without docs). C2 covers only the **local, uncommitted** `CLAUDE.md` edit, which per project memory is never staged. Do not ship C1 without these docs.

**CHANGELOG content (authored in C1's commit):** a **Breaking Changes** entry covering — deps removed (`intervention/image`, `james-heinrich/getid3`); classes moved to `glueful/media` with the namespace map (`Glueful\Services\ImageProcessor` → `Glueful\Extensions\Media\ImageProcessor`; `Glueful\Services\ImageProcessorInterface` → `Glueful\Extensions\Media\Contracts\ImageProcessorInterface`; `Glueful\Uploader\ThumbnailGenerator` → `Glueful\Extensions\Media\ThumbnailGenerator`; `Glueful\Uploader\MediaMetadataExtractor` → `Glueful\Extensions\Media\MediaMetadataExtractor`; `Glueful\Uploader\MediaMetadata` **unchanged**); `image()` helper now extension-provided; `FileUploader::getThumbnailGenerator()/getMetadataExtractor()` removed; `IMAGE_*`/`config/image.php` now extension-owned; `THUMBNAIL_*`/`UPLOADS_THUMBNAILS`/`UPLOADS_IMAGE_PROCESSING` gate moved code.

**Modify (local only, NOT committed)**
- `CLAUDE.md` — the **Image Processing** section and the **File Uploads & Blob Storage → Media Metadata Extraction** section must state these now require `composer require glueful/media`, document the no-media behavior (`thumb_url: null`, type-only `MediaMetadata`, variant endpoint serves original / 415 on format conversion, `image()` undefined), and the namespace changes. *(Per project memory: edit `CLAUDE.md` locally but do NOT stage/commit it.)*

**Steps**
- [ ] Edit `CLAUDE.md` locally (unstaged — never `git add` it).

**Rollback risk:** None (local docs only; the committed CHANGELOG/UPGRADE live in C1).

---

## Phase D — Populate the `glueful/media` extension (copy-first; runs BEFORE the Task C1 removal)

> Authored in a sibling package dir (e.g. `../media` symlinked, mirroring the aegis/users cross-repo dev pattern in project memory). Its tests run in the extension's own suite. The framework PR references it via a path/VCS repository for the extension-installed verification (Task D4). **Copy-first:** these tasks **COPY** the rich-media classes, `config/image.php`, and the integration doc into the package — the **core originals stay untouched** throughout Phase D, so core compiles and its full suite passes after every Phase D task. The originals are deleted only in **Task C1, the single atomic core-removal**, which runs after Phase D is green.

### Task D1 — Scaffold + copy the rich-media classes (namespace rewrite)

**Create (package tree)**
```
glueful/media/
  composer.json            # concrete object below
  phpstan.neon             # level 8 + treatPhpDocTypesAsCertain: false + reportUnmatchedIgnoredErrors: false
                           #   (mirror the framework's PHPStan posture; analyze script runs `phpstan analyse`).
                           #   Without it, level 8 flags "always true/false" nits core suppresses → code divergence.
  phpunit.xml              # Glueful\Extensions\Media\Tests testsuite
  src/MediaServiceProvider.php
  src/Contracts/ImageProcessorInterface.php   # COPY of src/Services/ImageProcessorInterface.php → namespace Glueful\Extensions\Media\Contracts
  src/ImageProcessor.php                       # COPY of src/Services/ImageProcessor.php → namespace Glueful\Extensions\Media; implements Glueful\Extensions\Media\Contracts\ImageProcessorInterface; resolves Glueful\Services\ImageSecurityValidator from CORE
  src/ThumbnailGenerator.php                   # COPY of src/Uploader/ThumbnailGenerator.php → namespace Glueful\Extensions\Media; uses Glueful\Extensions\Media\ImageProcessor; class_exists guard (:121) DELETED
  src/MediaMetadataExtractor.php               # COPY of src/Uploader/MediaMetadataExtractor.php → namespace Glueful\Extensions\Media (returns the CORE Glueful\Uploader\MediaMetadata)
  src/MediaProcessor.php                       # NEW — implements core Glueful\Uploader\Contracts\MediaProcessorInterface
  config/image.php                             # COPY of core config/image.php (verbatim)
  helpers.php                                  # image() helper RE-AUTHORED here (namespace-global), resolving Glueful\Extensions\Media\Contracts\ImageProcessorInterface
  ImageProcessorIntegration.md                 # COPY of src/Services/ImageProcessorIntegration.md — rewritten for the extension
```

**`glueful/media/composer.json`** — the canonical Glueful extension manifest (matching the `glueful/email-notification` shape: `name`/`description`/`type`/`license` MIT/`authors`/`keywords`/`homepage`, `glueful/framework` in **require-dev**, dev tooling phpunit/phpcs/phpstan, `autoload`/`autoload-dev`, `test`/`phpcs`(PSR-12)/`phpcbf`(PSR-12)/`analyze` (`phpstan analyse`, config-driven by `phpstan.neon`) scripts, rich `extra.glueful`, `config.sort-packages`). This is **strict JSON** (no `//` comments). Like `email-notification` keeps `symfony/mailer` in `require`, Media's two heavy runtime deps (`intervention/image`, `james-heinrich/getid3` — real constraints lifted from core `composer.json:29-30`) go in **`require`**, and the `image()` helper is autoloaded via `autoload.files`:
```json
{
    "name": "glueful/media",
    "description": "Rich media processing for Glueful (image transforms, thumbnails, media metadata).",
    "type": "glueful-extension",
    "license": "MIT",
    "authors": [{ "name": "Michael Tawiah Sowah", "email": "michael@glueful.dev" }],
    "keywords": ["media", "image", "thumbnails", "metadata", "glueful"],
    "require": {
        "php": "^8.3",
        "intervention/image": "^4.1",
        "james-heinrich/getid3": "^1.9"
    },
    "require-dev": {
        "glueful/framework": "^1.52.0",
        "phpunit/phpunit": "^10.5",
        "squizlabs/php_codesniffer": "^3.6",
        "phpstan/phpstan": "^1.0"
    },
    "homepage": "https://github.com/glueful/media",
    "autoload": {
        "psr-4": { "Glueful\\Extensions\\Media\\": "src/" },
        "files": ["helpers.php"]
    },
    "autoload-dev": {
        "psr-4": { "Glueful\\Extensions\\Media\\Tests\\": "tests/" }
    },
    "scripts": {
        "test": "vendor/bin/phpunit",
        "phpcs": "vendor/bin/phpcs --standard=PSR12 src",
        "phpcbf": "vendor/bin/phpcbf --standard=PSR12 src",
        "analyze": "vendor/bin/phpstan analyse"
    },
    "extra": {
        "glueful": {
            "name": "Media",
            "displayName": "Media",
            "description": "Rich media processing for Glueful.",
            "version": "1.0.0",
            "categories": ["media", "images"],
            "publisher": "glueful-team",
            "provider": "Glueful\\Extensions\\Media\\MediaServiceProvider",
            "requires": { "glueful": ">=1.52.0", "extensions": [] }
        }
    },
    "config": { "sort-packages": true }
}
```
**Important — strict JSON, heavy deps in `require`, `image()` via `autoload.files`.** The block above is JSONC-free strict JSON (no `//` comments). The two heavy runtime deps live in `require` (not `require-dev`) because they are needed at runtime when the extension is installed; `glueful/framework` is a **require-dev** peer (the host app/framework provides it at runtime). The `image()` global helper is registered through `autoload.files: ["helpers.php"]`. **Version note:** `^1.52.0` (and `extra.glueful.requires.glueful: ">=1.52.0"`) is the coordinated breaking release that performs the core removal (Task C1) — **fill in the real version** once the release is cut. Local pre-release development resolves `glueful/framework` from a **project-level path repository** (as `php glueful create:extension` scaffolds), which is a developer-machine `composer.json`/`composer.local` concern and is **not committed here**. The package ships its own `test`/`phpcs`/`phpcbf`/`analyze` scripts + dev tooling so the per-task gates run **from the package root**; they do not depend on the framework's composer scripts.

**Details**
- `ImageProcessor`: keep `Glueful\Services\ImageSecurityValidator` (core) and `Glueful\Uploader\MediaMetadata` references pointing at **core** namespaces (don't move those). Its ctor + `make()/fromUrl/fromUpload/create` are unchanged except the class/interface namespace.
- `ThumbnailGenerator`: delete the `class_exists(ImageProcessor::class)` guard (`:121-124`) — the extension's presence guarantees the class exists. It still takes `StorageInterface` + context, but `MediaProcessor` (D2) calls it with the storage passed through `generateThumbnail`.
- `MediaMetadataExtractor`: returns the **core** `Glueful\Uploader\MediaMetadata` VO (import from core).
- `helpers.php`: `function image(ApplicationContext $context, string $source): \Glueful\Extensions\Media\Contracts\ImageProcessorInterface { return app($context, \Glueful\Extensions\Media\Contracts\ImageProcessorInterface::class)::make($source); }`.

**Steps**
- [ ] Scaffold the package (`composer.json` per the JSONC object above, dir tree). **Copy** the four classes into the package (core originals stay) and apply the namespace edits on the copies (use Edit, not sed — macOS sed mangles backslashes per project memory). **Copy** `config/image.php` verbatim. **Re-author** `helpers.php`'s `image()` (it is not copied from core's `helpers.php` — see Task C1's delete-from-core note).
- [ ] Extension test: `composer install` in the extension resolves; classes autoload under `Glueful\Extensions\Media\*`. Commit (in the extension repo).

**Rollback risk:** Low — **core is untouched** (its `Glueful\Services\*` / `Glueful\Uploader\*` originals remain), so core stays green; the copies live only in the package. Revert = delete the package directory.

### Task D2 — `MediaProcessor` implementing the core seam

**Create** `src/MediaProcessor.php` (`Glueful\Extensions\Media`) implementing `Glueful\Uploader\Contracts\MediaProcessorInterface`:
- ctor injects `ThumbnailGenerator`-less collaborators — compose `MediaMetadataExtractor`, and use `ImageProcessor::make()` for variants; thumbnailing delegates to a `ThumbnailGenerator` constructed **with the storage passed into `generateThumbnail`** (not a ctor-held storage), honoring the seam's "never construct your own storage" rule.
- `extractMetadata()` → `MediaMetadataExtractor::extract()`.
- `generateThumbnail(StorageInterface $storage, ...)` → build/operate a `ThumbnailGenerator` over the **passed** `$storage` and return its URL (or null).
- `supportsThumbnail()` → MIME check (reuse `ThumbnailGenerator::supports()` logic / `DEFAULT_THUMBNAIL_FORMATS`).
- `renderVariant($sourcePath, $options)` → `ImageProcessor::make($sourcePath)`, apply width/height/quality/format/fit (the clamp/fit/quality/format logic relocated from the deleted `UploadController::applyResizeOptions`), return `['data'=>$processor->getImageData($format), 'mime'=>$processor->getMimeType()]`.

**Create** `tests/MediaProcessorTest.php` (extension suite, GD driver):
- [ ] Failing test: `extractMetadata` on a fixture image returns full dims; `renderVariant` resizes and returns bytes+mime; `supportsThumbnail('image/jpeg')` true / `'application/pdf'` false; `generateThumbnail` writes **through the passed `StorageInterface`** (assert the thumb lands on that storage's path prefix). Run → red.
- [ ] Implement `MediaProcessor`. Run → green. Commit (extension).

**Rollback risk:** Low (new, extension-local).

### Task D3 — `MediaServiceProvider` wiring

**Create** `src/MediaServiceProvider.php`:
- `services()` returns DI defs for `Intervention\Image\ImageManager` (driver-selection factory moved verbatim from `ImageProvider.php:19-43`, including GD/Imagick fallback), `Glueful\Extensions\Media\Contracts\ImageProcessorInterface` ⇒ `ImageProcessor` factory (moved from `ImageProvider.php:56-80`, resolving `Glueful\Services\ImageSecurityValidator` from **core**), an alias `ImageProcessor::class` → the interface, and **`Glueful\Uploader\Contracts\MediaProcessorInterface` ⇒ `MediaProcessor` (shared)**. Does **not** bind `ImageSecurityValidator` (core owns it now via `StorageProvider`).
- `register()`: `mergeConfig('image', require __DIR__.'/config/image.php')` (so `IMAGE_*` env works once installed); optionally merge thumbnail defaults.
- `boot()`: `\Glueful\Extensions\Media\ImageProcessor::setContext($context)` (replaces the deleted `Framework.php:332`).

**Steps**
- [ ] Extension test: build a container with core + `MediaServiceProvider`; assert `MediaProcessorInterface::class` resolves to `MediaProcessor`, `ImageProcessorInterface::class` resolves, and the provider does **not** re-bind `ImageSecurityValidator` (it resolves the core one). Run red → implement → green. Commit (extension).

**Rollback risk:** Low.

### Task D4 — Extension-installed verification (cross-package)

**Create** `tests/Integration/MediaInstalledTest.php` (extension suite, GD present) — the spec §"Verification 2" gate:
- [ ] `MediaProcessorInterface` resolves to `MediaProcessor`; a `FileUploader` built from the core `StorageProvider` (with `MediaServiceProvider` also registered) picks it up via optional resolution.
- [ ] `uploadMedia()` generates a thumbnail **written through the uploader's `StorageInterface`** — assert the thumb path shares the upload's disk/prefix (proves the `$storage` param is honored, closing the v1 gap) — and returns full metadata (width/height; duration for a/v fixtures).
- [ ] `GET /blobs/{uuid}?width=...` (or a direct `renderVariant` call through the controller) returns resized bytes + correct mime.
- [ ] `image()` helper exists and resolves the fluent `ImageProcessor`.
- [ ] The extension's `ImageProcessor` resolves `ImageSecurityValidator` from **core** (same instance the core container binds — assert not a duplicate class).
- [ ] Run → green. Commit (extension).

**Rollback risk:** Low (test-only).

---

## Cross-cutting verification (run before declaring done)

**Core-only gate (extension absent)** — codified across A2, B1, B3, B4, C1 tests, plus a final manual check:
- core boots with **no** `intervention/image` / `james-heinrich/getid3` installed and on a host with **neither GD nor Imagick** — no fatal at container build (the `ImageProvider:27,36` hard-fail is gone); confirm by running `composer test` after `composer remove` of the two deps (C1) and, ideally, in a CI job with `gd`/`imagick` disabled;
- `ImageSecurityValidator::class` resolves (A2);
- `uploadMedia()` → stored file + blob row + `thumb_url: null` + type-only `MediaMetadata` (B1);
- `GET /blobs/{uuid}?width/height/quality` serves the original; explicit `format` conversion → 415 (B3);
- boundary grep test (C1) finds no `ImageProcessor`/`ThumbnailGenerator`/`MediaMetadataExtractor`/`ImageProvider` in `src/ config/ routes/`, and core `composer.json` no longer requires the two deps;
- `image()` is undefined (function-not-found).

**Extension-installed gate** — D4 (thumb through passed storage, full metadata, `renderVariant`, `image()` exists, validator resolved from core).

**Per-task gate:** `composer test` (targeted; full suite on B4 and C1), `composer run analyse` (no new PHPStan errors), `composer run phpcs`.

## Self-review (completed during planning)

- **Spec coverage:** seam in `Glueful\Uploader\Contracts` with `StorageInterface` param (A1) ✓; `ImageSecurityValidator` stays in core + relocated binding (A2) ✓; `FileUploader` optional injection + inline no-op + accessor removal (B1) ✓; provider rewiring for both `FileUploader` and `UploadController` incl. the ad-hoc `:94` construction (B2/B3) ✓; variant serve-original / 415 (B3) ✓; `Framework.php:332` + `ContainerFactory:141` removal (B4) ✓; **single atomic core-removal** of classes/deps/config/`image()` block + docs (C1/C2) ✓; extension populated copy-first — `services()`/`register()`/`boot()`, `MediaProcessor`, copied fluent interface under `Glueful\Extensions\Media\Contracts`, re-authored `image()` in extension `helpers.php`, `ImageProcessorIntegration.md` copied (D1–D3) ✓; both verification groups (cross-cutting + D4) ✓.
- **No placeholders:** every task names exact files, line anchors, and concrete test cases.
- **Ordering (copy-first, then one atomic removal):** Phase A/B in-place seam + optional rewiring (green at each step with deps/classes still present) → Phase D **copies** classes/config + **re-authors** the `image()` helper into the extension (core untouched, green throughout) → **Task C1, the single atomic core-removal** (delete classes/`image()` block/`config/image.php`, drop the two deps, remove `ImageProvider`). No step deletes a class still referenced by green-but-unrewired core, and core compiles + passes its suite after every task.
