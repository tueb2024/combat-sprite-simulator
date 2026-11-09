#!/usr/bin/env python3
"""Extract a LOCAL, PRIVATE text search index from historical PDFs.

Never copy PDFs or extracted text to Git, CI artifacts, external services,
or the Windows Blender workstation.
"""
from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys

BOOKS = Path(os.environ.get("CSS_BOOKS_DIR", "/books"))
INDEX = Path(os.environ.get("CSS_INDEX_DIR", "/index"))


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            h.update(block)
    return h.hexdigest()


def pdf_pages(path: Path) -> int | None:
    p = subprocess.run(
        ["pdfinfo", str(path)], capture_output=True, text=True, check=False, timeout=40
    )
    m = re.search(r"^Pages:\s+(\d+)\s*$", p.stdout, re.MULTILINE)
    return int(m.group(1)) if m else None


def main() -> int:
    if not BOOKS.is_dir():
        print(f"Source directory missing: {BOOKS}", file=sys.stderr)
        return 2
    INDEX.mkdir(parents=True, exist_ok=True)
    os.chmod(INDEX, 0o700)
    originals: dict[str, dict] = {}
    original_manifest = BOOKS / "manifest.json"
    if original_manifest.is_file():
        try:
            records = json.loads(original_manifest.read_text(encoding="utf-8-sig"))
            if isinstance(records, dict):
                records = [records]
            originals = {row["remote_filename"]: row for row in records}
        except (ValueError, KeyError, TypeError) as err:
            print(f"Warning: could not parse Lenovo manifest: {err}", file=sys.stderr)
    catalog = []
    for pdf in sorted(BOOKS.glob("*.pdf")):
        output = INDEX / f"{pdf.stem}.txt"
        try:
            proc = subprocess.run(
                ["pdftotext", "-layout", str(pdf), str(output)],
                capture_output=True,
                text=True,
                check=False,
                timeout=240,
            )
            if proc.returncode != 0:
                if output.exists():
                    output.unlink()
                status = "extract_error"
                chars = 0
            else:
                chars = output.stat().st_size
                os.chmod(output, 0o600)
                status = "text" if chars > 1000 else "image_or_low_text"
        except (OSError, subprocess.TimeoutExpired) as err:
            status = f"extract_error: {err}"
            chars = 0
        src = originals.get(pdf.name, {})
        expected_hash = src.get("sha256")
        actual_hash = sha256(pdf)
        record = {
            "id": pdf.stem,
            "filename": pdf.name,
            "original_filename": src.get("source_filename"),
            "bytes": pdf.stat().st_size,
            "sha256": actual_hash,
            "sha256_matches_lenovo": actual_hash == expected_hash if expected_hash else None,
            "pages": pdf_pages(pdf),
            "text_bytes": chars,
            "index_status": status,
        }
        catalog.append(record)
        print(f"{pdf.name}: {record['pages']} pages, {status}, {chars} text bytes")
    manifest = INDEX / "catalog.local.json"
    manifest.write_text(json.dumps(catalog, ensure_ascii=False, indent=2), encoding="utf-8")
    os.chmod(manifest, 0o600)
    print(f"Private index: {INDEX} | indexed {len(catalog)} PDFs")
    print("For scans, use manual/targeted visual inspection; no automatic bulk OCR.")
    return 0 if catalog else 3


if __name__ == "__main__":
    raise SystemExit(main())
