# Project Rules

Project-specific conventions that **override** generic skill defaults.
Skill-context rules under `.ai-factory/skill-context/` apply on top.

## Quality gate

**Before declaring any task done, run `XDEBUG_MODE=off composer ai:validate` and
ensure it exits clean.** This script is the contract for "this code is shippable":

```sh
XDEBUG_MODE=off composer ai:validate
# Pint dry-run → Rector dry-run → composer audit → PHPStan max → PHPUnit
```

Each step must pass with zero new errors. Specifically:

1. `composer lint` (Laravel Pint, dry-run via `pint --test`) — code style.
   No drift from `pint.json`. If real, fix with `composer lint:fix`.
2. `composer rector` (`rector process --dry-run`) — refactor suggestions.
   No new findings vs the last clean run. Apply with `composer rector:fix`.
3. `composer audit --no-dev --locked --abandoned=ignore` — supply-chain
   security advisories against the locked composer.lock. Vulnerable
   versions block release; bump or pin via vendor advisories.
4. `composer stan` (PHPStan level max via larastan) — type safety.
   `phpstan.neon.dist` has `excludePaths: []` and `ignoreErrors: []` —
   keep it that way. New error = real bug, fix it; don't pad
   `excludePaths` or sneak in `@phpstan-ignore`.
5. `composer test` (PHPUnit via `php artisan test`) — behavior. The whole suite
   stays green; new tests added with new behavior.

## PHPStan policy

- **Level**: `max` (level 9 + Larastan strict rules).
- **`ignoreErrors: []`** — empty by design. If you find a vendor-PHPDoc
  bug (e.g. `Paginator::__construct` declared `iterable<string, mixed>`
  while its own internals treat the value as `array-key`-keyed), wrap
  the call in a typed local rather than adding an ignore. The pattern
  used in `ConfigurableApiCaster::paginatorCast` is the precedent.
- **No baseline file.** `phpstan-baseline.neon` has been deliberately
  removed — the project starts and ends every session at zero.

## When the rule conflicts with skill defaults

`composer ai:validate` is the project-level minimum. Skill-context rules
under `.ai-factory/skill-context/aif-implement/SKILL.md` (when present)
add project-specific implementation conventions on top of the skill's
own defaults. A skill's optional or relaxed checks do not waive the
`ai:validate` gate.
