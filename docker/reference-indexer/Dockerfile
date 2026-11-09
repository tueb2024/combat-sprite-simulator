FROM python:3.12-slim-bookworm
RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils tesseract-ocr \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /work
COPY build_index.py /work/build_index.py
COPY search_index.py /work/search_index.py
COPY ocr_page.py /work/ocr_page.py
ENV PYTHONDONTWRITEBYTECODE=1
ENTRYPOINT ["python", "/work/build_index.py"]
