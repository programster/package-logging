# Testing

- [Running them on every supported version of PHP](#running-them-on-every-supported-version-of-php)
- [Running them on your own PHP](#running-them-on-your-own-php)
- [PHPStan](#phpstan)
- [The documentation's examples](#the-documentations-examples)
- [Adding a test](#adding-a-test)

The package is tested with smoke tests (in `testing/`), rather than PHPUnit, so there's no test framework to install
or keep working on every version of PHP. They call every public method of the loggers, and check that every way of
misusing one is rejected. A test fails if it throws, fails an `assert()`, or raises any warning, notice or deprecation.
None of them need the network, or anything outside the package.

## Running them on every supported version of PHP
This lints every file and runs the tests on PHP 8.2, 8.3, 8.4 and 8.5, each in its own Docker container (with no
network access), then runs PHPStan once, on the lowest version. It's what the GitHub workflow runs, so a pass here
means a pass there. The workflow also writes the results as annotations (with `testing/github-annotations.sh`), which
can be read on the workflow's page without logging in to GitHub.

```bash
composer install
testing/run-all-php-versions.sh
```

- To test only some versions, pass them, e.g. `testing/run-all-php-versions.sh 8.2 8.5`.
- To rebuild the Docker images first, e.g. to pick up the latest patch releases, pass `--rebuild`.

## Running them on your own PHP
```bash
composer test
```

That runs `php -d zend.assertions=1 testing/main.php`. `zend.assertions` must be 1, or the `assert()` checks would
silently do nothing, so the tests refuse to run without it. To run only the tests whose name contains some text, set
`LOG_TEST_ONLY`, e.g. `LOG_TEST_ONLY=FailoverLogger composer test`.

The full disk tests use Linux's `/dev/full`, so they're left out where it doesn't exist.

## PHPStan
```bash
vendor/bin/phpstan
```

It runs at the highest level, 10, set in `phpstan.neon.dist`. The only errors it ignores are the ones a test causes on
purpose, by giving a `CachingLoggerFactory` closures that don't declare that they return a logger, to check that
they're rejected when the code runs.

## The documentation's examples
`testing/tests/DocsTest.php` runs the PHP examples in the README and every page in `docs/`, so the documentation can't
drift from what the code does. Each document's examples run in order, as one script, in a separate PHP process, with a
`use` statement for every class in the package added. They run in an empty temporary directory, so the log files they
write (with relative paths, such as `logs.csv`) are deleted afterwards. An example whose first line is
`// Throws SomeException` must throw that exception. See `testing/DocsExampleRunner.php`.

## Adding a test
Each area has a file of tests in `testing/tests/`, e.g. `FailoverLoggerTest.php`, and the test loggers and factories
they use are in `testing/Doubles/`. A test is a named callback:

```php
use Programster\Log\Testing\Doubles\RecordingLogger;
use Programster\Log\Testing\SmokeTest;

$testEmptyContext = function () : void {
    $createLogger = function () : void {
        new AddedContextLogger(new RecordingLogger(), []);
    };

    SmokeTest::expectException(ExceptionContextRequired::class, $createLogger);
};

$smoke->run("AddedContextLogger: rejects empty context", $testEmptyContext);
```

Check that a new test fails against the code before the fix, so it's known to test what it's meant to.
