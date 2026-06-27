# Adopt Biome Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the JS/TS/Vue Prettier + ESLint toolchain with Biome for faster format + lint.

**Architecture:** Single tool (Biome) handles formatting, linting, and import-organizing for `.ts/.js/.json/.css` and (experimentally) `.vue`. npm script names are kept identical so `composer.json` and CI need no changes. PHP tooling (Pint) is untouched.

**Tech Stack:** Biome 2.x, Vue 3 SFC (experimental Biome support), Tailwind v4.

## Global Constraints

- Branch: `chore/adopt-biome` (already created off local `main` @ `a4f3ddc`; no git remote).
- Do NOT touch PHP tooling (`composer.json` Pint scripts), `.github/workflows/*`, `.editorconfig`, `tests.yml`.
- Keep npm script NAMES exactly: `format`, `format:check`, `lint`, `lint:check`, `types:check`.
- Keep deps `typescript` and `vue-tsc` (used by `types:check`).
- Use `/opt/homebrew/bin/npm` and `/opt/homebrew/bin/node` — shell `npm`/`node` aliases are broken in this env (see memory `build-env-quirks`). If a `rolldown`/native-binding error appears, re-run `npm install` to restore.
- Spec: `docs/superpowers/specs/2026-06-27-adopt-biome-design.md`.

---

### Task 1: Swap dependencies and delete old config files

**Files:**
- Modify: `package.json` (devDependencies)
- Delete: `eslint.config.js`, `.prettierrc`, `.prettierignore`

**Interfaces:**
- Produces: `@biomejs/biome` available as `npx biome`; no eslint/prettier binaries remain.

- [ ] **Step 1: Remove ESLint + Prettier dependencies**

```bash
/opt/homebrew/bin/npm uninstall \
  @eslint/js eslint eslint-config-prettier eslint-import-resolver-typescript \
  eslint-plugin-import eslint-plugin-vue typescript-eslint \
  @vue/eslint-config-typescript @stylistic/eslint-plugin \
  prettier prettier-plugin-tailwindcss
```

- [ ] **Step 2: Add Biome**

```bash
/opt/homebrew/bin/npm install --save-dev --save-exact @biomejs/biome@latest
```

- [ ] **Step 3: Delete old config files**

```bash
git rm eslint.config.js .prettierrc .prettierignore
```

- [ ] **Step 4: Verify Biome installed and config files gone**

Run: `npx biome --version && ls eslint.config.js .prettierrc .prettierignore 2>&1`
Expected: prints a Biome version (e.g. `2.x.x`); `ls` reports all three files "No such file or directory".

- [ ] **Step 5: Verify no eslint/prettier deps remain**

Run: `grep -iE 'eslint|prettier' package.json || echo CLEAN`
Expected: `CLEAN`.

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json
git commit -m "chore: swap eslint/prettier deps for biome"
```

---

### Task 2: Add `biome.json` configuration

**Files:**
- Create: `biome.json`

**Interfaces:**
- Consumes: `@biomejs/biome` from Task 1.
- Produces: a config that `npx biome check .` reads without schema errors.

- [ ] **Step 1: Determine installed Biome major.minor for the schema URL**

Run: `npx biome --version`
Note the version (e.g. `2.1.2`) — use it in the `$schema` URL below (`https://biomejs.dev/schemas/<version>/schema.json`).

- [ ] **Step 2: Write `biome.json`**

```json
{
  "$schema": "https://biomejs.dev/schemas/2.1.2/schema.json",
  "vcs": { "enabled": true, "clientKind": "git", "useIgnoreFile": true },
  "files": {
    "includes": [
      "**",
      "!vendor",
      "!node_modules",
      "!public",
      "!bootstrap/ssr",
      "!vite.config.ts",
      "!resources/js/actions/**",
      "!resources/js/routes/**",
      "!resources/js/wayfinder/**",
      "!resources/js/components/ui/**",
      "!resources/views/mail/**"
    ]
  },
  "formatter": {
    "enabled": true,
    "useEditorconfig": true,
    "indentStyle": "space",
    "indentWidth": 4,
    "lineWidth": 80
  },
  "javascript": {
    "formatter": {
      "quoteStyle": "single",
      "semicolons": "always"
    }
  },
  "html": {
    "formatter": { "enabled": true },
    "experimentalFullSupportEnabled": true
  },
  "linter": {
    "enabled": true,
    "rules": {
      "recommended": true,
      "style": { "useImportType": "error" },
      "nursery": {
        "useSortedClasses": {
          "level": "error",
          "options": { "functions": ["clsx", "cn", "cva"] }
        }
      }
    }
  },
  "assist": {
    "enabled": true,
    "actions": { "source": { "organizeImports": "on" } }
  }
}
```

- [ ] **Step 3: Validate the config parses (no schema/unknown-key errors)**

Run: `npx biome check . 2>&1 | head -30`
Expected: Biome runs and reports formatting/lint diagnostics on real files. It must NOT error with "unknown key", "invalid configuration", or schema-parse failure. If a key is rejected (experimental Vue flag, nursery options shape, or assist `organizeImports`), correct it to match the installed version's schema — consult `npx biome check --help` / the version's docs — then re-run until clean of config errors.

- [ ] **Step 4: Confirm Biome sees `.vue` files**

Run: `npx biome check resources/js 2>&1 | grep -i '\.vue' | head -5`
Expected: at least one `.vue` path appears in diagnostics (proves experimental SFC support is active). If none and `.ts` files do appear, the experimental flag is not taking effect — revisit Step 2's `html` block against the installed schema.

- [ ] **Step 5: Commit**

```bash
git add biome.json
git commit -m "chore: add biome configuration"
```

---

### Task 3: Rewrite npm scripts

**Files:**
- Modify: `package.json` (`scripts`)

**Interfaces:**
- Consumes: `biome.json` from Task 2.
- Produces: scripts `format`, `format:check`, `lint`, `lint:check` backed by Biome; `types:check` unchanged. Names unchanged so `composer.json` + `.github/workflows/lint.yml` keep working.

- [ ] **Step 1: Replace the four script bodies**

In `package.json` `scripts`, set:

```json
        "format": "biome format --write .",
        "format:check": "biome format .",
        "lint": "biome check --write .",
        "lint:check": "biome check .",
```

Leave `build`, `build:ssr`, `dev`, and `types:check` exactly as they are.

- [ ] **Step 2: Verify each script resolves to Biome**

Run: `/opt/homebrew/bin/npm run lint:check 2>&1 | head -20`
Expected: Biome runs (lint + format diagnostics), not "eslint: command not found".

- [ ] **Step 3: Verify the read-only format check runs**

Run: `/opt/homebrew/bin/npm run format:check 2>&1 | tail -5`
Expected: Biome reports files needing formatting (non-zero exit is fine here — files not yet formatted).

- [ ] **Step 4: Commit**

```bash
git add package.json
git commit -m "chore: point npm lint/format scripts at biome"
```

---

### Task 4: Apply Biome to the codebase and review churn

**Files:**
- Modify: all formatted/linted source under `resources/`, plus any other in-scope `.ts/.js/.json/.css/.vue`.

**Interfaces:**
- Consumes: scripts from Task 3.
- Produces: a repo where `npm run lint:check` passes.

- [ ] **Step 1: Apply format + lint + import-organize**

Run: `/opt/homebrew/bin/npm run lint`
Expected: Biome rewrites files (`--write`). Note any rule violations it can NOT auto-fix.

- [ ] **Step 2: Review the `.vue` diff specifically for unacceptable churn**

Run: `git diff --stat -- '*.vue'` then `git diff -- 'resources/js/**/*.vue' | head -120`
Expected: changes are reasonable (quotes, indentation, class sorting). Watch for the experimental SFC formatter mangling `<template>` markup. If a file is badly mangled, decide per spec's accepted-risk: either accept, or add a targeted `!path` to `biome.json` `files.includes` and re-run Step 1.

- [ ] **Step 3: Resolve any non-auto-fixable lint errors**

Run: `/opt/homebrew/bin/npm run lint:check 2>&1 | tail -30`
Expected: exits clean (0 diagnostics). For each remaining error, fix the source code (preferred) or, only if the rule is genuinely unwanted, downgrade that single rule in `biome.json` and note it. Re-run until clean.

- [ ] **Step 4: Verify formatting is stable (idempotent)**

Run: `/opt/homebrew/bin/npm run format:check`
Expected: exit 0, "no files to fix" / no diffs reported.

- [ ] **Step 5: Confirm types still pass**

Run: `/opt/homebrew/bin/npm run types:check`
Expected: `vue-tsc` exits 0, no new type errors introduced by reformatting/import changes.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "style: apply biome formatting and lint fixes"
```

---

### Task 5: Final verification — no dangling references

**Files:**
- Read-only checks across the repo.

**Interfaces:**
- Consumes: everything above.

- [ ] **Step 1: No eslint/prettier references in config/CI files**

Run: `grep -riE 'eslint|prettier' --include='*.json' --include='*.yml' --include='*.yaml' --include='*.js' --include='*.ts' . | grep -v node_modules | grep -v package-lock.json || echo CLEAN`
Expected: `CLEAN`. (Any hit must be a deliberate leftover; otherwise remove it.)

- [ ] **Step 2: Removed config files are truly gone from the tree**

Run: `git ls-files | grep -E 'eslint.config.js|.prettierrc|.prettierignore' || echo CLEAN`
Expected: `CLEAN`.

- [ ] **Step 3: CI script names still resolve**

Run: `grep -E '"(format|lint)"' package.json`
Expected: both present and pointing at `biome` — confirms `.github/workflows/lint.yml`'s `npm run format` / `npm run lint` will work unchanged.

- [ ] **Step 4: Full check is green**

Run: `/opt/homebrew/bin/npm run lint:check && /opt/homebrew/bin/npm run types:check && echo ALL_GREEN`
Expected: `ALL_GREEN`.

- [ ] **Step 5: Commit any final cleanup (if Step 1 found leftovers)**

```bash
git add -A
git commit -m "chore: remove remaining eslint/prettier references"
```

---

## Self-Review notes

- **Spec coverage:** deps swap (T1), `biome.json` incl. experimental Vue + tailwind sort + useImportType (T2), script rename keeping names (T3), apply + churn review + accepted-loss handling (T4), dangling-ref + CI sanity verification (T5). All spec sections mapped.
- **Accepted losses** (`padding-line-between-statements`, YAML formatting, exact import-order style) require no task — they are simply not configured; nothing to remove.
- **Schema risk:** T2 Steps 3–4 explicitly validate experimental/nursery/assist keys against the installed version and self-correct, since exact key shapes depend on the Biome release.
