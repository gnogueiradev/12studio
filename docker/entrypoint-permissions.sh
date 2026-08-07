#!/usr/bin/env bash
# Corre no arranque do container (webdevops entrypoint.d): garante que os
# diretorios escritos em runtime pertencem ao user "application" — os bind
# mounts do host (storage/, public/) chegam com dono errado apos deploys.
set -e

mkdir -p /app/storage/backups /app/storage/logs /app/storage/framework/{cache,sessions,views}

chown -R application:application /app/storage /app/bootstrap/cache /app/public
