#!/usr/bin/env bash
# The identity of the Tauri runtime library (tauri/): its sources and lock file. The same in bin/jumphp
# tauri:download / ensure_tauri_lib and in the GitHub workflow that publishes the libraries (release tauri-<key>).
# Run from the project's root; `| sha256sum | cut -c1-16` makes the key.
set -euo pipefail
find tauri/Cargo.toml tauri/Cargo.lock tauri/build.rs tauri/tauri.conf.json tauri/src tauri/icons -type f -print0 \
  | LC_ALL=C sort -z | xargs -0 sha256sum
