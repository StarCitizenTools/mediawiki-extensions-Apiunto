# AGENTS.md

## Overview

Apiunto is a MediaWiki extension (requires MW 1.43+, PHP 8.1+) that exposes a Scribunto (Lua) library for fetching data from configured REST APIs. PHP handles request execution, response caching via `WANObjectCache`, and cache purging; Lua templates call `mw.ext.Apiunto.fetch( source, endpoint )` to consume the data. Scribunto is a hard dependency.

Key pieces:

- `src/ApiuntoLuaLibrary.php` — registers Lua callbacks (entry point from Scribunto)
- `src/Repositories/` — HTTP fetch + cache layer
- `src/Services/CachePurger.php` — invalidates cached responses
- `src/Hooks/` — `ArticlePurge`, `InfoAction`, and `ScribuntoExternalLibraries` hook handlers
- `maintenance/purgeCache.php` — CLI script for bulk cache purging
- API sources are configured via `$wgApiuntoSources` in `LocalSettings.php`

## Verification

Run only what's relevant to the files you changed.

| Files changed | Command |
| --- | --- |
| `*.php` | `composer preflight` (lint, style, and Phan) |
| `i18n/` | `composer test` (PHP lints cover JSON parse errors indirectly; otherwise check manually) |

Auto-fix command: `composer fix` (PHP).

**Preflight**: Run `composer preflight` in the [dev environment](#dev-environment) to execute all PHP lints, style checks, and Phan static analysis.

**Always run the relevant checks before committing.** Read the full output — PHPCS warnings must be fixed, not just errors. The command exits 0 even with warnings, so do not treat exit code alone as a pass.

### Dev environment

PHP checks, PHPUnit and browser tests need a running MediaWiki that serves this checkout. Use the first that applies:

1. An environment the user has named.
2. A disposable one you start yourself with [mwenv](https://github.com/ProfessionalWiki/mwenv), which needs no MediaWiki installation.
3. A MediaWiki installation that contains this checkout, such as MediaWiki-Docker, when the task needs its content or extensions.

Ask the user only if none of these can run, or the task needs something a fresh wiki lacks. Don't destroy an environment you didn't create: its database may hold the user's test content.

Whichever you use, it needs what CI's PHP jobs get (`.github/workflows/ci.yml`): load each extension in `extra-extensions`, and require each package in `extra-composer-packages` into MediaWiki with Composer, without loading it.

On arm64 hosts, Scribunto needs the LuaSandbox PHP extension, because the standalone Lua binary it ships is x86-64 only.

### Phan

`.phan/config.php` adds Scribunto, a hard dependency, to type resolution when it is in MediaWiki's `extensions/` directory.

## Coding conventions

### PHP

- New files should start with `declare( strict_types=1 );`
- Use native PHP types (properties, parameters, return values); use PHPDoc only for collection types like `string[]`
- Always use MediaWiki-namespaced imports (`use MediaWiki\Title\Title;`), never legacy shims (`use Title;`)
- Scribunto imports use the `MediaWiki\Extension\Scribunto\Engines\LuaCommon\*` namespace

### Lua

- The shipped library is `src/mw.ext.Apiunto.lua`
- `.editorconfig` defines the project's Lua style (EmmyLuaCodeStyle); `.luarc.json` configures the language server

### extension.json

`extension.json` is the source of truth for how the extension is wired — hooks, config variables, service wiring, and autoload namespaces are all declared here.

- Config variables are declared under `config` in `extension.json` (prefixed `wgApiunto`)
- Service wiring lives in `src/ServiceWiring.php`; services are resolved through `MediaWikiServices`
- New hook handlers must be registered under `HookHandlers` and bound under `Hooks`

### Commits

- Use [Conventional Commits](https://www.conventionalcommits.org/) (e.g. `fix:`, `feat:`, `refactor:`)
- Use `ci:` or `chore:` for non-user-facing changes (tooling, config, dependencies)

### i18n

- Any user-facing string needs a message key in `i18n/en.json`
- Every key in `en.json` must also have a documentation entry in `i18n/qqq.json`
