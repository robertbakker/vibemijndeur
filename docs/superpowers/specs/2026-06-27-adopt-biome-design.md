# Adopt Biome (replace Prettier + ESLint)

**Date:** 2026-06-27
**Branch:** `chore/adopt-biome` (off local `main` @ `a4f3ddc`; no git remote configured)

## Goal

Replace the JS/TS/Vue Prettier + ESLint toolchain with [Biome](https://biomejs.dev)
for faster format + lint. PHP tooling (Pint) is out of scope and untouched.

## Decisions

- **Full Biome**, single tool. Drop both Prettier and ESLint.
- **Vue:** enable Biome's experimental SFC support (`html.experimentalFullSupportEnabled`)
  so Biome formats the 20 `.vue` files. Accepted risk: experimental formatter may
  produce churn or bugs.
- **Lint scope:** Biome `recommended` only, minimal overrides.
- **Tailwind:** keep class sorting via the nursery `useSortedClasses` rule
  (functions: `clsx`, `cn`, `cva`). Accepted risk: nursery rule, partial template support.
- **Type imports:** keep `useImportType` (one override) — directly replaces the old
  `@typescript-eslint/consistent-type-imports: error`.

## Changes

### Dependencies (`package.json`)

Add:
- `@biomejs/biome` (latest 2.x), devDependency.

Remove (devDependencies):
- `@eslint/js`, `eslint`, `eslint-config-prettier`, `eslint-import-resolver-typescript`,
  `eslint-plugin-import`, `eslint-plugin-vue`, `typescript-eslint`,
  `@vue/eslint-config-typescript`, `@stylistic/eslint-plugin`,
  `prettier`, `prettier-plugin-tailwindcss`

Keep: `typescript`, `vue-tsc` (used by `types:check`).

### Deleted files

- `eslint.config.js`
- `.prettierrc`
- `.prettierignore`

### New file `biome.json`

```json
{
  "$schema": "https://biomejs.dev/schemas/2.0.0/schema.json",
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

> Exact key names/shape (e.g. `html.experimentalFullSupportEnabled`, nursery rule
> options, assist `organizeImports`) to be validated against the installed Biome
> version's schema during implementation; adjust to match.

### Scripts (`package.json`) — names kept identical

Keeping the four script names means `composer.json` and `.github/workflows/lint.yml`
need **no** changes.

| Script         | Old                              | New                       |
| -------------- | -------------------------------- | ------------------------- |
| `format`       | `prettier --write resources/`    | `biome format --write .`  |
| `format:check` | `prettier --check resources/`    | `biome format .`          |
| `lint`         | `eslint . --fix`                 | `biome check --write .`   |
| `lint:check`   | `eslint .`                       | `biome check .`           |
| `types:check`  | `vue-tsc --noEmit`               | *(unchanged)*             |

(`biome check` = lint + format + assist in one pass.)

## Out of scope / unchanged

- `composer.json` (Pint), `.github/workflows/*` (script names stable), `.editorconfig`
  (Biome honors it), `tests.yml`.

## Accepted losses vs. current setup

- `padding-line-between-statements` (blank lines around control statements) — no Biome equivalent.
- YAML formatting / `.yml` 2-space override — Biome has no YAML formatter (editorconfig still applies to other tools).
- ESLint `import/order` exact alphabetized-group style → replaced by Biome `organizeImports` (different ordering).

## Verification

1. `npm install` succeeds; lockfile updated.
2. `npx biome check .` runs clean or with only expected diffs.
3. `npm run lint` (write) applied; review the `.vue` diff for unacceptable churn.
4. `npm run types:check` still passes.
5. Confirm no dangling `eslint`/`prettier` references remain in repo
   (`grep -ri "eslint\|prettier" --include=*.json --include=*.yml .`).
6. `git grep` for removed config filenames returns nothing.
