# Breaking Change Management Process

This document defines how the Glueful Framework manages breaking changes so users can upgrade predictably. It applies to the framework runtime (router, DI, HTTP, config, security, caching, queues, observability).

## Principles
- Semantic Versioning: MAJOR.MINOR.PATCH
  - MAJOR: contains breaking changes
  - MINOR: backward‑compatible features and deprecations only
  - PATCH: bug/security fixes only
- Predictability over speed: announce, deprecate, ship — in that order.
- Document everything: CHANGELOG, upgrade notes, cookbook updates.

## What is a Breaking Change?
Any change that requires user action to remain functional:
- API surface: method/constructor signatures, visibility, namespace/name changes, class/interface removal.
- Behavior: default configuration changes, auth/permission behavior, error handling/output format changes.
- Dependencies: PHP version increases, required extensions, dependency major upgrades, config format/key changes.

## Classification
- MAJOR (🔴): Significant impact, cross‑cutting or architectural; requires explicit migration.
- MINOR‑scope break (🟡): Limited blast radius with clear migration and bridges (rare; prefer deprecate first).
- MICRO (🟢): Edge cases or rarely used paths; typically removed after a long deprecation window.

Note: We aim to avoid breaks in MINOR. If a small break is unavoidable, treat and communicate it like a MAJOR: deprecate first and remove later.

## Process

### Phase 1 — Proposal
Open a Discussion or RFC issue with:
- Motivation & goals
- Technical design
- Impact assessment (who/what breaks)
- Migration strategy (manual and/or automated)
- Timeline (deprecate → remove)

Minimum community review (guideline):
- MAJOR: 30 days
- Minor‑scope break: 14 days
- Micro: 7 days

### Phase 2 — Plan & Prepare
- Backward‑compatibility strategy (bridges, config aliases, feature flags as needed)
- Instrument deprecation warnings in development
- Update/prepare documentation and examples
- Add tests that pin current behavior and the new behavior (behind flags/bridges)

### Phase 3 — Deprecate
- Mark old APIs with `@deprecated` (include “since vX.Y” and “remove in vZ.0”).
- Log deprecation warnings in development only (noisy in prod is discouraged).
- CHANGELOG: note deprecation with migration guidance.
- Cookbook / docs: add “before/after” examples.
- Deprecation window: remove no sooner than two MINOR releases after first deprecation (e.g., deprecate in 1.2 → remove in ≥1.4).

### Phase 4 — Implement & Release
- Land new implementation behind bridges/flags where necessary.
- Update tests (unit/integration) and ensure CI is green (Lint & Style, PHPUnit 8.2, PHPStan; Security audit optional).
- CHANGELOG: include a “Breaking changes” section for MAJOR.
- Publish Upgrade Notes (include code examples and config diffs).
- Tag and release.

### Phase 5 — Post‑Release Support
- Triage upgrade issues; provide hotfixes where appropriate.
- Track regressions and adopt feedback quickly.
- Remove bridges according to deprecation schedule.

## Author Checklist (PRs that introduce or finalize breaking changes)
- [ ] RFC / Discussion is linked and accepted.
- [ ] Deprecations added with `@deprecated` and remove‑by version noted.
- [ ] Bridges/aliases/config fallbacks implemented where reasonable.
- [ ] Tests cover old warning path and the new behavior.
- [ ] CHANGELOG updated (Breaking changes + Migration notes).
- [ ] Cookbook/docs updated (before/after, config key changes, routes/middleware examples).
- [ ] Release note draft prepared.

## Communication Artifacts

### CHANGELOG (required)
- Summarize breaking changes under the release with concise migration steps.

### Upgrade Notes (recommended)
A short Markdown guide for each MAJOR and noteworthy MINOR:
- What changed and why
- Who is affected
- Step‑by‑step migration (code/config before → after)
- Known pitfalls

### Templates

Breaking change announcement
```markdown
# 🚨 Breaking Change: [Title]

**Introduced in:** v[X.Y.0] (removal of deprecated paths)
**Severity:** MAJOR/MINOR/MICRO

## What Changed
[Clear description; list APIs/config affected]

## Why
[Motivation]

## Migration
[Step‑by‑step instructions; code/config diffs]

## Notes
[Known issues, flags/aliases durations]
```

Upgrade guide
```markdown
# Upgrading from v[X] to v[Y]

## Checklist
- [ ] Read the CHANGELOG for v[Y]
- [ ] Update dependencies and run tests

## Code Changes
[Before → After examples]

## Config Changes
[Old keys → New keys; defaults]

## Validation
[How to verify success]
```

## Versioning & Scheduling
- Major releases: only when truly needed; announce in roadmap; minimum 30‑day RFC window.
- Minor: additive features and deprecations only.
- Patch: bug/security fixes only.

## Tooling & CI
- CI required checks: “PHP CI / Lint & Style”, “PHP CI / PHPUnit (PHP 8.2)”, “PHP CI / PHPStan” (Security optional).
- Static analysis: prefer PHPStan deprecation rules and type‑safety enforcement.
- Composer constraints: widen cautiously; bump PHP only in MAJOR or with strong justification.

## Governance
- Code Owners required for framework‑critical areas (router, DI, HTTP, security).
- Breaking change approval requires maintainer sign‑off and a migration plan.

---

This process is reviewed periodically and adjusted based on community feedback, ecosystem impact, and release experience.
