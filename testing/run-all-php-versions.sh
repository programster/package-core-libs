#!/bin/sh
# Runs lint and the tests (testing/main.php) on every supported version of PHP, each in its own Docker container.
# This catches code that only works on newer versions of PHP than the minimum this package supports.
#
# Usage:
#   testing/run-all-php-versions.sh                # test every supported version
#   testing/run-all-php-versions.sh 8.3 8.5        # test only these versions
#   testing/run-all-php-versions.sh --rebuild      # rebuild the Docker images first (e.g. to get the latest patch release)
#   testing/run-all-php-versions.sh --tmp=tmpfs    # only test with /tmp held in memory (or --tmp=disk for only on disk)
#
# By default, each version is tested twice: with /tmp on disk, and with /tmp on a tmpfs (held in memory), as on
# Debian 13, as the library chooses where to write temporary files differently for each.
# Requires Docker, and "composer install" to have been run, as the tests use the vendor/ directory.
# Exits with 0 if every version passed, or 1 if any failed.

SUPPORTED_VERSIONS="8.3 8.4 8.5"

scriptDir=$(cd "$(dirname "$0")" && pwd)
repoDir=$(dirname "$scriptDir")
rebuild=0
versions=""
tmpTypes="disk tmpfs"

for arg in "$@"
do
    case "$arg" in
        --rebuild)
            rebuild=1
            ;;
        --tmp=disk|--tmp=tmpfs)
            tmpTypes="${arg#--tmp=}"
            ;;
        --tmp=both)
            tmpTypes="disk tmpfs"
            ;;
        -*)
            echo "Unknown option ${arg}. Use --rebuild, or --tmp=disk, --tmp=tmpfs or --tmp=both."
            exit 1
            ;;
        *)
            versions="$versions $arg"
            ;;
    esac
done

if [ -z "$versions" ]
then
    versions=$SUPPORTED_VERSIONS
fi

if [ ! -f "$repoDir/vendor/autoload.php" ]
then
    echo "vendor/autoload.php is missing. Please run \"composer install\" first."
    exit 1
fi

failedVersions=""

for version in $versions
do
    image="corelibs-php${version}-test"
    echo
    echo "################ PHP ${version}"

    if [ "$rebuild" -eq 1 ] || ! docker image inspect "$image" > /dev/null 2>&1
    then
        echo "building ${image}..."

        if ! docker build -q --pull --build-arg PHP_VERSION="$version" -t "$image" "$scriptDir/php-versions" > /dev/null
        then
            echo "FAILED to build the image for PHP ${version}"
            failedVersions="$failedVersions $version"
            continue
        fi
    fi

    for tmpType in $tmpTypes
    do
        echo
        echo "######## PHP ${version}, with /tmp on ${tmpType}"

        # A tmpfs /tmp is mounted as on Debian 13. Without it, /tmp is on the container's own (disk) filesystem.
        if [ "$tmpType" = "tmpfs" ]
        then
            tmpMount="--tmpfs /tmp:rw,exec,nosuid,nodev,mode=1777"
        else
            tmpMount=""
        fi

        # The 1 MB tmpfs is a small filesystem for testing what happens when there isn't enough free space.
        # CORELIBS_TEST_TMP_TYPE lets the tests check /tmp really is what this run is meant to be testing.
        # $tmpMount is left unquoted on purpose, so that it is split into its option and value (or is nothing).
        if ! docker run --rm -v "$repoDir":/src:ro \
            $tmpMount \
            --tmpfs /mnt/corelibs-small:size=1m,mode=1777 \
            -e CORELIBS_TEST_SMALL_FILESYSTEM=/mnt/corelibs-small \
            -e CORELIBS_TEST_TMP_TYPE="$tmpType" \
            "$image" sh /src/testing/php-versions/run-in-container.sh
        then
            failedVersions="$failedVersions ${version}(/tmp-on-${tmpType})"
        fi
    done
done

echo
if [ -z "$failedVersions" ]
then
    echo "All passed:" $versions
    exit 0
else
    echo "FAILED on PHP:" $failedVersions
    exit 1
fi
