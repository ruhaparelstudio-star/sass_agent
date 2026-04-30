.PHONY: frontend-install frontend-build frontend-dev

frontend-install:
	docker compose exec -T wa-gateway sh -lc "cd /app && npm install"

frontend-build:
	docker compose exec -T wa-gateway sh -lc "cd /app && npm run build"

frontend-dev:
	docker compose exec -T wa-gateway sh -lc "cd /app && npm run dev -- --host"
