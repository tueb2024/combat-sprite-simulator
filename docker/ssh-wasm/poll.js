// poll() that waits (for the JS event loop: WebSocket messages, the stdin of the pipe) instead of answering at once:
// OpenSSH's loops sleep in poll, and a browser lets nothing else run while wasm does not give the thread back. Asyncify.
mergeInto(LibraryManager.library, {
  __syscall_poll__deps: ['$FS', '$Asyncify'],
  __syscall_poll: function (fds, nfds, timeout) {
    return Asyncify.handleAsync(async () => {
      const start = Date.now();
      for (;;) {
        let count = 0;
        for (let i = 0; i < nfds; i++) {
          const pollfd = fds + i * 8;
          const fd = HEAP32[pollfd >> 2];
          const events = HEAP16[(pollfd + 4) >> 1];
          let mask = 32;                                  // POLLNVAL
          const stream = fd < 0 ? null : FS.getStream(fd);
          if (stream) {
            mask = 5;                                     // POLLIN | POLLOUT
            if (stream.stream_ops && stream.stream_ops.poll) mask = stream.stream_ops.poll(stream, -1);
          }
          mask &= events | 8 | 16 | 32;
          if (fd < 0) mask = 0;
          if (mask) count++;
          HEAP16[(pollfd + 6) >> 1] = mask;
        }
        if (count || timeout === 0 || (timeout > 0 && Date.now() - start >= timeout)) return count;
        await new Promise((r) => setTimeout(r, 2));
      }
    });
  },
});
