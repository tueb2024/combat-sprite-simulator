#!/usr/bin/env python3
"""OCR at most ten explicitly selected PDF pages in the private offline container.

Example: python /work/ocr_page.py munson-airliners-interwar 3 4 5 --query Junkers
The complete OCR text remains under /index (private); stdout has short matches.
"""
import argparse
import os
from pathlib import Path
import re
import subprocess
import tempfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('book_id')
    parser.add_argument('pages', nargs='+', type=int)
    parser.add_argument('--query', default='')
    args = parser.parse_args()
    if not re.fullmatch(r'[a-z0-9-]+', args.book_id):
        parser.error('book_id must be an ASCII catalog ID')
    if not 1 <= len(args.pages) <= 10 or any(page < 1 for page in args.pages):
        parser.error('choose one to ten positive PDF page numbers')
    books = Path(os.environ.get('CSS_BOOKS_DIR', '/books'))
    index = Path(os.environ.get('CSS_INDEX_DIR', '/index'))
    pdf = books / f'{args.book_id}.pdf'
    if not pdf.is_file():
        parser.error(f'unknown book: {args.book_id}')
    index.mkdir(parents=True, exist_ok=True)
    os.chmod(index, 0o700)
    for page in args.pages:
        with tempfile.TemporaryDirectory() as temp:
            image = Path(temp) / 'page'
            subprocess.run(['pdftoppm', '-f', str(page), '-l', str(page),
                            '-scale-to', '1800', '-png', '-singlefile', str(pdf), str(image)],
                           check=True, timeout=90)
            result = subprocess.run(['tesseract', str(image) + '.png', 'stdout',
                                     '--psm', '6'], capture_output=True, text=True,
                                    check=True, timeout=90)
        output = index / f'{args.book_id}.ocr-page-{page:04d}.txt'
        output.write_text(result.stdout, encoding='utf-8')
        os.chmod(output, 0o600)
        print(f'{args.book_id}, PDF page {page}: OCR saved privately ({len(result.stdout)} chars)')
        if args.query:
            matches = [line.strip()[:180] for line in result.stdout.splitlines()
                       if args.query.casefold() in line.casefold()]
            for match in matches[:5]:
                print('  match:', match)


if __name__ == '__main__':
    main()
