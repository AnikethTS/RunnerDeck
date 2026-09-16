# Contributing to RunnerDeck

Thanks for taking the time to contribute. This document covers how to get set
up, what a good PR looks like here, and — since it comes up for every project
now — the project's policy on AI-assisted contributions.

## Getting started

1. Fork the repo and clone your fork.
2. Copy `.env.example` to `.env` and fill it in (see the README's
   [First-time setup](README.md#first-time-setup) — you'll need `gh`
   authenticated and either an org or your own GitHub account to point it
   at; you don't need real runners running to work on most of the codebase).
3. Install dev tooling: `composer install` and `npm install`. `composer.lock`
   and `package-lock.json` are committed, so this installs the exact versions
   CI uses — if you add/update a dev dependency, commit the updated lock file
   alongside it. This pulls in PHPUnit, phpstan, and phpcs, and wires up the
   pre-commit hook (`.githooks/pre-commit`) that runs all three before every
   commit. Nothing about *running* RunnerDeck
   itself needs Composer — this step is purely for contributors.
4. Confirm your setup: `composer run test && composer run stan && composer run cs`.
   All three should pass on a clean checkout.

## Making a change

- Create a branch off `main`: `git checkout -b your-change-name`.
- Keep PRs focused. One logical change per PR is much easier to review than
  a bundle of unrelated fixes — see [Scope](#scope) below.
- Add or update tests under `tests/` for anything you change in `src/`.
  `RunnerPool`, `Config`, `Shell`, `Csrf`, `Auth`, `Totp`, and `CrashState`
  all have existing test files to follow the pattern of.
- If a test in `e2e/dashboard.spec.js` exists because of a specific issue,
  link it with a `// https://github.com/AnikethTS/RunnerDeck/issues/N`
  comment directly above the `test(...)` call — see the existing tests
  there for the pattern. Helps a future reader understand why that
  particular case is being checked.
- Run `composer run test`, `composer run stan`, and `composer run cs`
  locally (or just commit — the pre-commit hook runs them for you) before
  opening a PR. CI runs the same checks plus a boot smoke test on Linux and
  macOS, so failures there will block merge either way.
- Write your commit messages and PR description around *why*, not just
  *what* — the diff already shows what changed.

## Scope

Some parts of this codebase are load-bearing for safety, not just
functionality: `ProcessControl`, `Provisioner`, `Shell`, the CSRF layer,
`Auth`/`Totp` (the optional login gate), and `CrashState` (decides when
auto-restart fires) in particular. Changes there get more scrutiny, and
are a rockier place to land a first PR. If you're new here, look for issues labeled
[`up-for-grabs`](https://github.com/AnikethTS/RunnerDeck/issues?q=is%3Aissue+is%3Aopen+label%3Aup-for-grabs)
— they're picked specifically to be self-contained and not on that critical
path. If an issue isn't labeled and you're not sure whether it's a good
first task, ask on the issue before starting.

Don't add dependencies, frameworks, abstractions, or config options beyond
what the issue actually calls for — this project is deliberately plain PHP
with zero runtime dependencies, and that's a design decision, not an
oversight.

## Pull requests

- Reference the issue you're addressing, if there is one.
- Add a bullet under `## [Unreleased]` in `CHANGELOG.md` describing your
  change, in the same voice as the existing entries. This is part of the
  PR, not something that gets backfilled after merge.
- Fill in the test plan: what you ran, and — where it's feasible — how you
  verified the change against a real (even single-runner) pool, not just
  that CI is green.
- Be responsive to review. If a change sits with unaddressed feedback for a
  while, it may get closed to keep the queue honest; you're welcome to
  reopen when you're ready to continue.

## AI-assisted contributions

Using AI tools (Copilot, ChatGPT, Claude, Cursor, etc.) to help write a
contribution is fine. The bar is the same either way: **you are the author,
and you're accountable for what you submit.** Specifically:

- **Disclose it.** Note in the PR description which tool(s) you used and
  roughly how — e.g. "drafted with Claude Code, I wrote the tests by hand"
  or "used Copilot for autocomplete throughout." This isn't about gatekeeping
  AI use, it's context that helps review.
- **Understand every line before you submit it.** If a reviewer asks why the
  code does something a particular way, "the AI wrote it that way" isn't an
  answer. Read the diff as if you'd typed it yourself, because as far as
  this project is concerned, you did.
- **Actually run it.** CI passing is necessary, not sufficient. Don't submit
  generated code you haven't executed and checked against the behavior
  described in the issue.
- **No bulk or speculative PRs.** Mass-generating PRs against open issues
  without engaging with the specific problem wastes maintainer time and
  will be closed without review, repeatedly if needed.
- **Keep it in scope.** AI tools are especially prone to "helpfully"
  expanding a change — extra abstractions, unrelated refactors, new
  dependencies. See [Scope](#scope) above; out-of-scope changes get sent
  back regardless of how they were written.

## Code of conduct

Be respectful, assume good faith, and keep disagreements about the code, not
the person. Maintainers may close issues or PRs, or ask a contributor to
step back from a thread, if this isn't holding.

## License

By contributing, you agree your contribution is licensed under this
project's [MIT license](LICENSE).
