# RunnerDeck PHP tests

```
tests/
  Unit/            one folder per src/ area, same names (Unit/Pool tests src/Pool)
    Alerting/ Backup/ Cli/ Configuration/ GitHub/ Host/ Http/ Lifecycle/
    Logging/ Pool/ Presentation/ Security/ System/ Telemetry/
    Http/Action/   the HTTP action classes (src/Http/Action)
    Scripts/       shell scripts under bin/ (no PHP class behind them)
  Support/         shared helpers, never run as tests
```

Browser tests are separate: `tests/e2e` and `tests/e2e-live` at the repository root.

## Where a new test goes

- Testing `src/<Area>/Foo.php`? Put `FooTest.php` in `tests/Unit/<Area>/`. The namespace is the
  folder path: `Tests\Unit\<Area>`.
- Testing something with no class (a script, an environment requirement)? Pick the closest area,
  or `Scripts/`.
- A new `src/` area gets a new `tests/Unit/<Area>/` folder; nothing else to register, because
  `phpunit.xml` runs everything under `tests/Unit` and Composer autoloads `Tests\` from `tests/`.
- Need integration or other kinds of tests later? Add a sibling of `Unit/` (for example
  `tests/Integration/`) and a second `<testsuite>` in `phpunit.xml`.

## Shared helpers (`Support/`)

| Helper | Use it for |
|---|---|
| `CliTestCase` | Base class for command line tests: each test gets its own pool and settings folder. |
| `HttpActionHarness` | Trait for HTTP actions: isolated pool, a fake `gh`, `slot()`, `ghRunners()` and `call()` to run an action and read its response. |
| `HttpResponse` | The exception the respond seam throws so a test can read a response. |
| `CiProgressExtension` | Prints `[n/N]` progress in CI. Loaded from `phpunit.xml`. |

## Conventions

- Tests never touch the real pool, settings or `gh`: set `RUNNERDECK_*` environment variables to a temp
  folder and fake `Shell`, as the helpers do, and undo it in `tearDown()`.
- A test that starts a PHP session (Auth, Csrf, the API router) uses `#[RunInSeparateProcess]`.
- Paths to the repository (`VERSION`, `public/`) are built with `dirname(__DIR__, N)`; count the folders
  up from the test file when you move one.
- Line length is 120 (`composer cs` warns above that).

## Running

```bash
cd app
vendor/bin/phpunit                                   # everything
vendor/bin/phpunit tests/Unit/Pool                   # one area
vendor/bin/phpunit --filter RunnerActions            # by name
php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text   # coverage (needs pcov)
```
