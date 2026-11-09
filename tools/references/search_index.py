#!/usr/bin/env python3
"""Search only the private local PDF text index with page references."""
import argparse
from pathlib import Path
import os
import re


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("query", help="Case-insensitive text, e.g. 'Hurricane' or 'Junkers'")
    parser.add_argument("--limit", type=int, default=15, help="Maximum snippets; default 15")
    args = parser.parse_args()
    root = Path(os.environ.get("CSS_INDEX_DIR", str(Path.home() / ".local/share/combat-sprite-simulator/reference-index")))
    if not root.is_dir():
        parser.error(f"Index directory not found: {root}")
    hits = 0
    term = args.query.casefold()
    for txt in sorted(root.glob("*.txt")):
        content = txt.read_text(encoding="utf-8", errors="replace")
        for page_number, page in enumerate(content.split("\f"), start=1):
            i = page.casefold().find(term)
            if i == -1:
                continue
            context = re.sub(r"\s+", " ", page[max(0, i - 90):i + len(args.query) + 140]).strip()
            print(f"{txt.stem}, PDF page {page_number}: {context}")
            hits += 1
            if hits >= args.limit:
                return
    if hits == 0:
        print("No text matches. Check the catalog: some volumes may be image-only scans.")


if __name__ == "__main__":
    main()
