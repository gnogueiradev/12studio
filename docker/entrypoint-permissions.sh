#!/usr/bin/env bash
# Corre no arranque do container (webdevops entrypoint.d): garante que os
# diretorios escritos em runtime pertencem ao user "application" — os bind
# mounts do host (storage/, public/) chegam com dono errado apos deploys.
set -e

# `database` e obrigatorio: o bind mount do host chega vazio e o PDO do SQLite
# so cria o ficheiro .sqlite se o diretorio ja existir. Sem isto o primeiro
# deploy morre logo no `db:backup`, antes sequer de chegar ao migrate.
mkdir -p /app/storage/backups /app/storage/database /app/storage/logs \
    /app/storage/app/public /app/storage/framework/{cache,sessions,views}

chown -R application:application /app/storage /app/bootstrap/cache /app/public
