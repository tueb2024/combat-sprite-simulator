FROM python:3.12-slim-bookworm
RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /work
COPY tools/references/build_index.py /work/build_index.py
COPY tools/references/search_index.py /work/search_index.py
ENV PYTHONDONTWRITEBYTECODE=1
ENTRYPOINT ["python", "/work/build_index.py"]
