---
name: verify
description: Verify a change in OkayCMS-Fork before a commit or PR — run PHPStan, rector, php -l and PHPUnit on a clean tree the way CI does (the dev stack also mounts okay-lab modules and pollutes those checks), check the app log, run the smoke script after environment changes, and drive the change in a browser on both themes. Use before every commit, before opening a PR, and before saying "done" or "works".
---

# verify — OkayCMS-Fork

Goal: prove the change works and state how it was proven.

## 0. Two traps of the dev stack

- **It is not a clean tree.** `okaycmsdev-php85-1` mounts `~/Projects/OkayCMS-Fork` *and* the okay-lab modules into `Okay/Modules/Sviat/*`. PHPStan, rector and the full PHPUnit run inside it report the modules' findings (hundreds of PHPStan errors, `vibe_shop` anchor failures), not the fork's.
- **It sees only the main checkout.** From a worktree, `docker compose exec` checks the main checkout.

So the gate checks run in a **throwaway container over the tree being changed** (main checkout or worktree), with `vendor/` borrowed from the main checkout (same `composer.lock` only):

```sh
R=(docker run --rm --entrypoint php -u "$(id -u)" -v "$PWD":/var/www/html \
   -v "$HOME/Projects/OkayCMS-Fork/vendor":/var/www/html/vendor:ro -w /var/www/html okaycmsdev-php85)
"${R[@]}" vendor/bin/phpstan analyse --no-progress
"${R[@]}" vendor/bin/rector process --dry-run --no-progress-bar
"${R[@]}" vendor/bin/phpunit                 # ~30 s; or a dir: vendor/bin/phpunit tests/Core
"${R[@]}" -l <each changed .php file>
```

The tool shell is zsh: keep the command in an array, a string in `$R` is not word-split. No database there: DB-backed tests are skipped — run those in the stack (`cd dev && docker compose exec -T php85 php vendor/bin/phpunit tests/<dir>`), main checkout only.

Fix the code, not the gate: no new `phpstan-baseline.neon` entries or ignores without the user's word.

## 1. What changed

```sh
git diff --name-only $(git merge-base HEAD origin/dev)   # plus git status --short
```

## 2. By diff

| Changed | Run / check |
|---|---|
| `Okay/Core/**`, `backend/**` | the matching `tests/*` dir, then the full suite; a core change is usually **paired** with `devSviat/broken` — the same diff lands there |
| Entity, filter, any SQL | SQL errors are swallowed and the page still answers 200: after exercising the path, `tail -n 50 Okay/log/app-$(date +%F).log` |
| `design/okay_shop/**`, `design/vibe_shop/**`, `modifications`, `*.tpl` | both themes in the browser; `tests/TplMod` |
| `dev/**`, `docker/**`, nginx or PHP config | `dev/bin/smoke.sh` (about 3 minutes, main checkout, stack up) |
| `composer.json`/`composer.lock` | `composer validate --strict`, `composer audit --locked` |
| `1DB_changes/**` | apply on the dev database (`./ok database:deploy` in the stack) and open the affected admin page |

## 3. Browser — for any change a shopper or manager can see

1. Stack up: `cd dev && docker compose up -d`.
2. Playwright MCP on `https://okaycmsfork.test`; admin at `/admin`, login `admin` / `1234`.
3. Walk the changed path on **both** themes (`okay_shop`, `vibe_shop`) plus edges: empty state, mobile width.
4. Browser console clean; no new lines in `Okay/log/app-*.log`.
5. A 500 with `unable to write file …/compiled/…` in the log means a root-owned dir left by `docker compose exec` without `-u www-data`: `docker compose exec -T -u root php85 chown -R www-data:www-data /var/www/html/compiled /var/www/html/backend/design/compiled /var/www/html/cache`. Run your own exec commands as `-u www-data` where they render templates.

## 4. Before a PR into `dev`

Full PHPUnit, PHPStan, rector, `php -l` on the clean tree; smoke for environment changes; browser pass for visible changes. Anything not run — name it and leave it to CI.

## Rules

- Three failed attempts to fix the same check — stop and show the output.
- A flaky test is not skipped, retried or slept around — find the cause.
- Do not change a test to match a bug.
- In PR text always qualify issue numbers: `devSviat/OkayCMS-Fork#123` (a bare `#123` resolves to upstream).

## Report

End with one line: what ran and the result, what did **not** run and why.

```
verify: phpstan ✓ · rector ✓ · phpunit 5000 ✓ (clean tree) · app log clean ✓ · browser: okay_shop + vibe_shop cart ✓ · smoke — not needed (no env change)
```
