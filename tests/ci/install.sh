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
git clone https://bitbucket.org/osmanov/pecl-event.git
pushd pecl-event
phpize
./configure
make
make install
popd
echo "extension=event.so" >> "$(php -r 'echo php_ini_loaded_file();')"
