# An image for testing the library on a specific version of PHP. Used by testing/run-all-php-versions.sh.
# Build with e.g.: docker build --build-arg PHP_VERSION=8.3 -t corelibs-php8.3-test testing/php-versions
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# ps and ip are used by Core::getNumProcRunning() and Network::getIps(), but aren't in the official image.
# libzip-dev is needed to build the zip extension.
RUN apt-get update -qq > /dev/null && apt-get install -y -qq procps iproute2 libzip-dev > /dev/null \
    && rm -rf /var/lib/apt/lists/*

# sockets is used by Network::sendTcpRequest(), zip by Filesystem::zipDir() and unzip(), and pcntl by the smoke test
# to run a test server.
RUN docker-php-ext-install sockets zip pcntl > /dev/null
