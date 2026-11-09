#!/bin/sh
# In the OpenSSH source folder: the ssh client objects (the passwd lookups are ours: there is no /etc/passwd in a browser), then link.sh.
set -e
grep -q jh_getpwuid Makefile || sed -i 's/^CFLAGS=\(.*\)$/CFLAGS=\1 -Dgetpwuid=jh_getpwuid -Dgetpwnam=jh_getpwnam/' Makefile
emcc -O2 -c stubs.c -o stubs.o
rm -f *.o openbsd-compat/*.o libssh.a openbsd-compat/libopenbsd-compat.a
emmake make -j32 libssh.a openbsd-compat/libopenbsd-compat.a >/dev/null 2>&1
emmake make -j32 ssh >/dev/null 2>&1 || true
emcc -O2 -c stubs.c -o stubs.o
sh link.sh
