#!/usr/bin/env bash

shopt -s nullglob
export LC_ALL=C
for i in /tmp/core_*.*; do
    if [ -f "$i" -a "$(file "$i" | grep -o 'core file')" ]; then
        gdb -q $(file "$i" | grep -oE "'[^ ']+" | sed "s/^'//g") "$i" <<EOF
set pagination 0
backtrace full
info registers
x/16i \$pc
thread apply all backtrace
quit
EOF
    fi
done
