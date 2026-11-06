#!/bin/sh
# Runs inside a test container (see run-all-php-versions.sh). The repository is mounted read-only at /src, so it is
# copied to /app first, so that the tests can't change it.
# Exits with 0 if everything passed, or 1 if anything failed.

cp -r /src /app
failed=0
phpVersion=$(php -r 'echo PHP_VERSION;')

echo "=== lint (PHP ${phpVersion})"
numLintFailures=0

for file in $(find /app/src /app/testing -name '*.php')
do
    if ! output=$(php -l "$file" 2>&1)
    then
        echo "$output"
        numLintFailures=$((numLintFailures + 1))
    fi
done

echo "lint failures: ${numLintFailures}"
[ "$numLintFailures" -eq 0 ] || failed=1

echo "=== tests"
php -d zend.assertions=1 -d assert.exception=1 /app/testing/main.php || failed=1

echo "=== password from piped input"
password=$(echo "piped-secret" | php -r 'require "/app/vendor/autoload.php"; echo Programster\CoreLibs\CliLib::getPasswordFromUserInput();')

if [ "$password" = "piped-secret" ]
then
    echo "passed"
else
    echo "FAILED: got [${password}]"
    failed=1
fi

exit $failed
