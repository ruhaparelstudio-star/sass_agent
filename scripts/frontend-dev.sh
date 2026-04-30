#!/usr/bin/env bash
set -euo pipefail

docker compose exec -T wa-gateway sh -lc "cd /app && npm run dev -- --host"
