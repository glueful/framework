# Extension System Upgrade Guide

This release re-architects how Glueful discovers and activates extensions. The
mental model is now: **Composer discovers, one `enabled` list activates, a pure
resolver orders and validates.** The four overlapping discovery sources and the
multi-key config files are gone.

> This is a breaking change to `config/extensions.php` and `config/serviceproviders.php`.
> No application code that *uses* extensions needs to change — only the config files
> and the way you install/activate extensions.

## What changed (at a glance)

| Before | After |
|---|---|
| Discovery from 4 sources: `enabled`, `dev_only`, local-folder scan, Composer | **Composer only** — installed `glueful-extension` packages are the candidates |
| `extensions.only` (exclusive allow-list) | The single `enabled` list **is** the allow-list — nothing loads unless listed |
| `extensions.disabled` (blacklist) | Just remove it from `enabled` (or never add it) |
| `extensions.dev_only` (env-gated load) | Require it as a Composer **dev dependency**; enable it the same way |
| `extensions.local_path` (scan `extensions/` dir) | Composer **path repositories** (see `create:extension`) |
| `extensions.scan_composer` toggle | Always on — Composer discovery is the only discovery |
| `::class` constants in the array | Plain **string FQCNs** (so the CLI can edit the list safely) |
| Installing a package auto-loaded it | Installing does nothing until you **enable** it |
| Dev resolved live, prod wrote cache lazily | Dev resolves live; **prod must `extensions:cache`** (boot fails if missing) |
| `extensions:why` command | Folded into `extensions:list` (the state column is the reason) |

## Config key mapping

### `config/extensions.php`

Old → new:

```php
// BEFORE (illustrative)
return [
    'only'         => [/* ... */],   // exclusive allow-list
    'enabled'      => [Vendor\Ext\Provider::class],
    'dev_only'     => [Vendor\Debug\Provider::class],
    'disabled'     => [Vendor\Broken\Provider::class],
    'local_path'   => 'extensions',
    'scan_composer'=> true,
];

// AFTER
return [
    'enabled' => [
        'Vendor\\Ext\\Provider',
        // moved here from `only`; `disabled` entries simply omitted;
        // `dev_only` entries listed here too (see note below)
    ],
];
```

Migration rules:

- **`only` → `enabled`.** The new `enabled` is already exclusive: nothing loads
  unless its provider FQCN is present. Copy `only` entries into `enabled`.
- **`enabled` → `enabled`,** but convert `Foo\Bar::class` to the string `'Foo\\Bar'`.
- **`disabled` → (delete).** To disable, remove the entry from `enabled`. There is
  no blacklist.
- **`dev_only` → `enabled` + Composer dev dependency.** Require the extension under
  `require-dev` in the consuming app so it is simply absent in production; list its
  provider in `enabled`. (Resolution is environment-independent; "dev only" is now a
  packaging concern, not a config flag.)
- **`local_path` → (delete).** Use a Composer path repository for local extensions
  (`create:extension` adds one for you).
- **`scan_composer` → (delete).** Composer discovery is unconditional.

### `config/serviceproviders.php`

The application's own providers collapse to the same single-key shape:

```php
// AFTER
return [
    'enabled' => [
        'App\\Providers\\AppServiceProvider',
        'App\\Providers\\EventServiceProvider',
    ],
];
```

`only` / `dev_only` / `disabled` for app providers are removed — list exactly the
providers you want, in order. App providers are always loaded (they are not gated by
`extensions.enabled`, which is for composer-discovered extensions only).

## Installing & enabling an extension

```bash
# 1. Install the package (Packagist or a path repository)
composer require glueful/aegis

# 2. Activate it (adds the provider FQCN to config/extensions.php, recompiles cache)
php glueful extensions:enable aegis

# 3. Inspect state at any time
php glueful extensions:list
```

`extensions:enable` / `extensions:disable` **validate before writing**: enabling an
extension whose dependency is not enabled is refused (and the config is left
untouched); disabling an extension that another enabled extension depends on is
refused. They edit the `enabled` string list in place and recompile the cache.

### Local extensions (development)

`php glueful create:extension <name>` scaffolds a real Composer package under
`extensions/<slug>/` (type `glueful-extension`, `extra.glueful.provider`, PSR-4
autoload) and registers a Composer **path repository** in your app's `composer.json`.
It does **not** run Composer — it prints the commands to finish:

```bash
php glueful create:extension widgets
# then:
composer require glueful/widgets:@dev
php glueful extensions:enable widgets
```

## Declaring requirements (extension authors)

In your extension's `composer.json`:

```json
{
  "type": "glueful-extension",
  "extra": {
    "glueful": {
      "provider": "Vendor\\Ext\\Provider",
      "requires": {
        "glueful": ">=1.46.0",
        "extensions": ["Vendor\\Base\\Provider"]
      }
    }
  }
}
```

- `requires.glueful` is a Composer version constraint matched with `composer/semver`.
  A mismatch is a resolver error (the extension will not load and `extensions:cache`
  refuses to build).
- `requires.extensions` lists provider FQCNs that must also be enabled. They are
  **not** auto-enabled — the resolver reports a missing-dependency error if they are
  not in `enabled`. Enabled dependencies are automatically ordered before dependents.

## Production deployment

Production **must** boot from the compiled manifest — it never resolves live:

```bash
php glueful extensions:cache   # strict: fails loudly on any resolver error
```

If the cache is missing in production, boot throws with instructions to run
`extensions:cache`. Add it to your deploy/build step (after `composer install`).

`php glueful extensions:diagnose` reports candidate state, resolver errors, the
resolved load order, and (in production) whether the compiled cache is present.
