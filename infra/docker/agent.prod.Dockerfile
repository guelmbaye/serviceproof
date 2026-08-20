# ServiceProof — agent runtime, production image.
#
# Differences from the development image, each for a reason:
#   • --proxy-headers, so the runtime sees the real scheme and client behind
#     the TLS terminator instead of logging every request as http from the
#     proxy's own address;
#   • more than one worker, because a verification spends most of its time
#     waiting on the network and a single worker serialises those waits;
#   • a non-root user, because nothing here needs to be root;
#   • no source bind-mount: the image is the artefact.

FROM python:3.12-slim

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    SP_ENV=production

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# Strip anything a build on a laptop may have left behind.
RUN find . -name '__pycache__' -type d -prune -exec rm -rf {} + 2>/dev/null || true

RUN useradd --create-home --uid 10001 agent && chown -R agent:agent /app
USER agent

EXPOSE 9000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:9000/health', timeout=4).status == 200 else 1)"

# UVICORN_WORKERS is read from the environment so the count can be tuned per
# droplet without rebuilding.
CMD ["sh", "-c", "exec uvicorn app.main:app --host 0.0.0.0 --port 9000 --proxy-headers --forwarded-allow-ips='*' --workers ${UVICORN_WORKERS:-2}"]
