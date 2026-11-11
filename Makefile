.PHONY: up test down
up:
	docker compose up --build -d --wait
test:
	docker compose exec -T app php backend/tests/smoke.php
down:
	docker compose down
