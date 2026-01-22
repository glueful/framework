# Priority 2: Developer Experience Improvements Implementation Plans

> Detailed implementation blueprints for improving developer productivity, scaffolding, and development workflow in Glueful Framework.

## Overview

This folder contains comprehensive implementation plans for Priority 2 features identified in [FRAMEWORK_IMPROVEMENTS.md](../../FRAMEWORK_IMPROVEMENTS.md). These features focus on enhancing developer experience through better tooling, scaffolding, and development workflows.

## Implementation Plans

| # | Feature | Document | Estimated Effort | Dependencies |
|---|---------|----------|------------------|--------------|
| 1 | Scaffold Commands (Enhanced) | [01-scaffold-commands.md](./01-scaffold-commands.md) | 2-3 weeks | ORM, Validation, Resources |
| 2 | Database Factories & Seeders | [02-database-factories-seeders.md](./02-database-factories-seeders.md) | 2-3 weeks | ORM, Console, Faker (dev) |
| 3 | Interactive CLI Wizards | [03-interactive-cli-wizards.md](./03-interactive-cli-wizards.md) | 1-2 weeks | Scaffold Commands |
| 4 | Real-Time Development Server | [04-realtime-dev-server.md](./04-realtime-dev-server.md) | 2-3 weeks | ServeCommand |

> **Note:** Database Factories require `fakerphp/faker` as a `require-dev` dependency. Seeders work without Faker and are production-ready. See [02-database-factories-seeders.md](./02-database-factories-seeders.md#architecture-decision) for details.

## Current State

Several scaffold commands are already implemented:

**In `src/Console/Commands/Scaffold/`:**

| Command | Status | Description |
|---------|--------|-------------|
| `scaffold:model` | ✅ Complete | Generate ORM model classes with migrations |
| `scaffold:controller` | ✅ Complete | Generate API controller classes |
| `scaffold:request` | ✅ Complete | Generate FormRequest classes |
| `scaffold:resource` | ✅ Complete | Generate API Resource classes |

**In `src/Console/Commands/Event/`:**

| Command | Status | Description |
|---------|--------|-------------|
| `event:create` | ✅ Complete | Generate event classes (equiv. to `scaffold:event`) |
| `event:listener` | ✅ Complete | Generate listener classes (equiv. to `scaffold:listener`) |

**Planned:**

| Command | Status | Description |
|---------|--------|-------------|
| `scaffold:middleware` | 📋 Planned | Generate middleware classes |
| `scaffold:job` | 📋 Planned | Generate queue job classes |
| `scaffold:rule` | 📋 Planned | Generate validation rule classes |
| `scaffold:test` | 📋 Planned | Generate test classes |
| `scaffold:factory` | 📋 Planned | Generate model factory classes |
| `scaffold:seeder` | 📋 Planned | Generate database seeder classes |

## Implementation Order

The recommended implementation order based on dependencies:

```
┌─────────────────────────────────────────────────────────────┐
│                                                             │
│  Phase 1: Extended Scaffolding                              │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Scaffold Commands Enhancement                        │   │
│  │ (middleware, event, listener, job, rule, test)      │   │
│  └─────────────────────────────────────────────────────┘   │
│                           │                                 │
│                           ▼                                 │
│  Phase 2: Testing Infrastructure                            │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Database Factories & Seeders                         │   │
│  │ (factory, seeder classes + db:seed command)         │   │
│  └─────────────────────────────────────────────────────┘   │
│                           │                                 │
│                           ▼                                 │
│  Phase 3: Enhanced DX                                       │
│  ┌─────────────────┐    ┌─────────────────────────────┐   │
│  │ Interactive CLI │    │ Real-Time Dev Server        │   │
│  │ Wizards         │    │ (watch mode, logging)       │   │
│  └─────────────────┘    └─────────────────────────────┘   │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

## Design Principles

All implementations should follow these principles:

### 1. Build on Existing Infrastructure
- Extend existing `BaseCommand` for all scaffold commands
- Use existing `StorageManager` for file operations
- Leverage `PathGuard` for security
- Build on Symfony Console components

### 2. Consistency with Existing Commands
- Follow patterns established in `scaffold:model`, `scaffold:controller`
- Use PHP 8 attributes (`#[AsCommand]`)
- Consistent option naming (`--force`, `--path`, etc.)
- Same output formatting and error handling

### 3. Stub-Based Generation
- Use template stubs for all generated files
- Support customizable stubs via `stubs/` directory
- Variable substitution with `{{ClassName}}`, `{{namespace}}` patterns
- Clean, well-documented generated code

### 4. Developer Experience Focus
- Clear, helpful command descriptions
- Progress feedback during generation
- Suggestions for next steps
- IDE-friendly generated code

## File Structure After Implementation

```
src/
├── Console/
│   └── Commands/
│       └── Scaffold/
│           ├── ControllerCommand.php      # ✅ IMPLEMENTED
│           ├── ModelCommand.php           # ✅ IMPLEMENTED
│           ├── RequestCommand.php         # ✅ IMPLEMENTED
│           ├── ResourceCommand.php        # ✅ IMPLEMENTED
│           ├── MiddlewareCommand.php      # 📋 PLANNED
│           ├── EventCommand.php           # 📋 PLANNED
│           ├── ListenerCommand.php        # 📋 PLANNED
│           ├── JobCommand.php             # 📋 PLANNED
│           ├── RuleCommand.php            # 📋 PLANNED
│           ├── TestCommand.php            # 📋 PLANNED
│           ├── FactoryCommand.php         # 📋 PLANNED
│           └── SeederCommand.php          # 📋 PLANNED
│
├── Database/
│   ├── Factory/                           # 📋 PLANNED
│   │   ├── Factory.php
│   │   ├── Concerns/
│   │   │   ├── HasStates.php
│   │   │   └── Relationships.php
│   │   └── FakerBridge.php
│   │
│   └── Seeders/                           # 📋 PLANNED
│       ├── Seeder.php
│       └── DatabaseSeeder.php
│
├── Support/
│   └── InteractiveInput/                  # 📋 PLANNED
│       ├── Prompter.php
│       ├── Questions/
│       │   ├── TextQuestion.php
│       │   ├── ConfirmQuestion.php
│       │   ├── ChoiceQuestion.php
│       │   └── MultiChoiceQuestion.php
│       └── Formatters/
│           ├── TableFormatter.php
│           └── ProgressFormatter.php
│
└── ...existing...
```

## Testing Strategy

Each feature requires:

1. **Unit Tests** - Test individual components in isolation
2. **Integration Tests** - Test command execution with file system
3. **Stub Tests** - Verify generated code compiles and works
4. **Snapshot Tests** - Compare generated output against fixtures

## Status

| Feature | Status | PR | Release Target |
|---------|--------|-----|----------------|
| Scaffold Commands (Enhanced) | 📋 Planned | - | v1.13.0 |
| Database Factories & Seeders | 📋 Planned | - | v1.13.0 |
| Interactive CLI Wizards | 📋 Planned | - | v1.14.0 |
| Real-Time Dev Server | 📋 Planned | - | v1.14.0 |

Legend: 📋 Planned | 🚧 In Progress | ✅ Complete | 🔄 Review

---

## Related Documentation

- [Priority 1 Implementation Plans](../README.md) - Completed foundational features
- [ORM Documentation](../../ORM.md) - Active Record implementation
- [Resources Documentation](../../RESOURCES.md) - API Resource transformers
- [FRAMEWORK_IMPROVEMENTS.md](../../FRAMEWORK_IMPROVEMENTS.md) - Full roadmap
