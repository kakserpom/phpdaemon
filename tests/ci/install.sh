#!/usr/bin/env bash

# Install ext-eio
git clone https://github.com/rosmanov/pecl-eio.git
pushd pecl-eio
phpize
./configure
make
make install
popd
echo "extension=eio.so" >> "$(php -r 'echo php_ini_loaded_file();')"

# Install ext-event
curl http://pecl.php.net/get/event-2.0.4.tgz | tar -xz
pushd event-2.0.4
phpize
./configure
make
make install
popd
echo "extension=event.so" >> "$(php -r 'echo php_ini_loaded_file();')"
