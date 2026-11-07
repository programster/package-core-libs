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

echo "=== tests (as root)"
php -d zend.assertions=1 -d assert.exception=1 /app/testing/main.php || failed=1

# Run them again as a normal user, as root ignores file permissions, so the checks of how the library handles files
# it isn't allowed to change (e.g. deleteDir() on a read-only folder) can only run without root. Each run reports
# the checks it had to skip, which the other run covers.
# Files the normal user doesn't own, or whose group they aren't in, which only root can create. The folders holding
# them belong to the normal user, so the tests can try to move them, and check the move is refused.
fixtures=/tmp/corelibs-fixtures
mkdir -p "$fixtures/other-owner" "$fixtures/other-group"
echo "owned by root" > "$fixtures/other-owner/root-owned.txt"
echo "group 12345" > "$fixtures/other-group/other-group.txt"
chown nobody:nogroup "$fixtures" "$fixtures/other-owner" "$fixtures/other-group" \
    "$fixtures/other-group/other-group.txt"
chgrp 12345 "$fixtures/other-group/other-group.txt"
# A file owned by root that anyone can write to, in a folder the normal user owns, for replacing a file's contents
# without changing its owner.
mkdir -p "$fixtures/writable-other-owner"
echo "owned by root" > "$fixtures/writable-other-owner/root-owned.txt"
chmod 666 "$fixtures/writable-other-owner/root-owned.txt"
chown nobody:nogroup "$fixtures/writable-other-owner"
# A "sticky" folder like /tmp, which anyone can write to, holding a file that only root can delete from it.
mkdir -p "$fixtures/sticky"
echo "owned by root" > "$fixtures/sticky/root-owned.txt"
chmod 1777 "$fixtures/sticky"
export CORELIBS_TEST_FIXTURES="$fixtures"

echo "=== tests (as a normal user)"
setpriv --reuid=nobody --regid=nogroup --clear-groups \
    php -d zend.assertions=1 -d assert.exception=1 /app/testing/main.php || failed=1

# And once more, as a normal user, with the temporary directory reached through a symlink, as on macOS (where /var is
# really /private/var). This catches code that mixes up a path with its real path.
mkdir -p /tmp/real-tmp
chmod 1777 /tmp/real-tmp
ln -sfn /tmp/real-tmp /tmp/linked-tmp

echo "=== tests (as a normal user, with a symlinked temporary directory)"
TMPDIR=/tmp/linked-tmp setpriv --reuid=nobody --regid=nogroup --clear-groups \
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
