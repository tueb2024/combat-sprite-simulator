#!/bin/sh
# Links ssh.js + ssh.wasm from the objects of `make ssh` (run in the OpenSSH build folder).
exec emcc -O2 -o ssh.js ssh.o readconf.o clientloop.o sshtty.o sshconnect.o sshconnect2.o mux.o ssh-sk-client.o stubs.o \
  -L. -Lopenbsd-compat/ -L/opt/openssl/lib -lssh -lopenbsd-compat -lcrypto -sUSE_ZLIB=1 -sASYNCIFY -sALLOW_MEMORY_GROWTH \
  -sINITIAL_MEMORY=33554432 -sEXIT_RUNTIME=1 -sMODULARIZE=1 -sEXPORT_NAME=createSsh -sENVIRONMENT=worker,web,node \
  --js-library poll.js "-sASYNCIFY_IMPORTS=['__syscall_poll']" -sFORCE_FILESYSTEM=1 -sEXPORTED_RUNTIME_METHODS=FS,callMain,ENV "$@"
