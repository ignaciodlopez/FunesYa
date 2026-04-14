#!/bin/bash
set -e   # detiene el script si cualquier comando falla

echo "==> Pulling latest changes..."
git pull

echo "==> Building and restarting container..."
docker compose up -d --build app

echo "==> Waiting for container to be healthy..."
sleep 3

echo "==> Running aggregator..."
docker compose exec app php scripts/run_aggregator.php

echo "==> Status:"
docker compose ps
docker compose logs --tail=30 app

echo "==> Deploy complete."