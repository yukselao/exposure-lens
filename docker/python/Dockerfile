# syntax=docker/dockerfile:1
# Shared runtime for the Python services (migrate, sc, tie_postgres).
# Only dependencies are installed here. Application code is bind-mounted at
# runtime, so nothing from the repository is copied into the image.
FROM python:3.12-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq5 tini \
    && rm -rf /var/lib/apt/lists/*

RUN --mount=type=bind,source=requirements.txt,target=/tmp/requirements.txt \
    pip install --no-cache-dir -r /tmp/requirements.txt

RUN useradd --uid 1000 --create-home app \
    && mkdir -p /var/log/connector \
    && chown app:app /var/log/connector

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1

USER app
WORKDIR /app
ENTRYPOINT ["tini", "--"]
