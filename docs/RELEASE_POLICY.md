# Release Policy

This document defines how Glueful versions, deprecates, and ships releases so users can upgrade safely and predictably.

## Versioning

- Standard: Semantic Versioning 2.0.0 (MAJOR.MINOR.PATCH)
- Compatibility: Public APIs are stable across PATCH and MINOR releases; breaking changes only in MAJOR releases.
- Pre‑releases: `-alpha.N`, `-beta.N`, `-rc.N` suffixes may be published before stable tags.
- Tagging: Every release is tagged in Git (e.g., `v1.4.2`).

## Supported Scope

- Public API: Namespaces intended for application use (documented in `docs/`), CLI surface, configuration keys under `config/`, and route/middleware contracts. These follow SemVer.
- Internal API: Namespaces marked `Internal` or not documented may change without notice between MINOR versions.

## Deprecation Policy

- Process: Features slated for removal are first deprecated, then removed in a later MAJOR.
- Signals:
  - PHPDoc `@deprecated` with the deprecating version and planned removal version.
  - Runtime notice in development (`APP_ENV != production`) when feasible.
  - CHANGELOG entry under “Deprecated”.
- Timeline: A deprecated API remains available for at least two MINOR releases before removal (e.g., deprecated in 1.4, earliest removal in 1.6; actual removal in next MAJOR preferred).

## Backports and Support Window

- Active: Latest MINOR on the latest MAJOR receives features, fixes, and security updates.
- Maintenance: Latest MINOR on the previous MAJOR receives critical bug fixes and security updates only.
- Security: Security fixes are backported to all supported MAJOR lines.

## Release Cadence

- MINOR: Approximately every 6–10 weeks, when meaningful changes accumulate.
- PATCH: As needed for bug fixes and security updates.
- MAJOR: Infrequent; only when breaking changes are necessary and after a migration path is documented.

## CHANGELOG

- Format: “Keep a Changelog” style with categories: Added, Changed, Deprecated, Removed, Fixed, Security.
- Location: Repository root `CHANGELOG.md`.
- Discipline: Every user‑visible change must be reflected in the CHANGELOG for the next release.

## Stability Guarantees

- Config: Changing default values is allowed in MINOR if not breaking; renaming or removing keys is breaking and requires a MAJOR with migration notes.
- Exceptions: Clearly documented exceptions (e.g., experimental features) may evolve faster and are prefixed or marked as experimental.

## Release Process (Maintainers)

1. Ensure CI is green on `main` and all docs are updated.
2. Update `CHANGELOG.md`:
   - Move Unreleased changes under the new version heading; add date.
   - Ensure breaking changes are called out with upgrade notes.
3. Create a tag `vX.Y.Z` and push.
4. Create a GitHub release with highlights and upgrade notes.
5. Announce changes (internal channels and release feed as applicable).

## Deprecation Annotations (Example)

```
/**
 * @deprecated since 1.4.0, will be removed in 2.0.0. Use NewClass::method() instead.
 */
class OldClass {}
```

## Migration Guides

- For any MAJOR release, include a dedicated `MIGRATION_GUIDE_X_Y.md` in `docs/` with code examples and config changes.
- For impactful MINOR changes, add an “Upgrade Notes” subsection under the release in the CHANGELOG.

---
This policy is living documentation and may evolve; any material change will be called out in the CHANGELOG.
