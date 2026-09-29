#!/usr/bin/env bash
# Corre no arranque do container (webdevops entrypoint.d): garante que os
# diretorios escritos em runtime pertencem ao user "application" — os bind
# mounts do host (storage/, public/) chegam com dono errado apos deploys.
set -e

# `database` e obrigatorio: no primeiro deploy o bind mount do host chega
# vazio e quem cria o .sqlite em falta e o `migrate --force`, com um touch()
# — que rebenta se o diretorio pai nao existir. O Laravel nunca deixa o PDO
# criar o ficheiro sozinho: o SQLiteConnector verifica-o e lanca excecao.
mkdir -p /app/storage/backups /app/storage/database /app/storage/logs \
    /app/storage/app/public /app/storage/framework/{cache,sessions,views}

chown -R application:application /app/storage /app/bootstrap/cache /app/public

# Chaves do Passport: o league/oauth2-server so aceita 400/440/600/640/660 e
# verifica a PUBLICA a cada pedido ao /mcp. Com 644 o /mcp respondia 500 a
# todos os tokens (o Laravel faz excecao do E_USER_NOTICE). Corrigido aqui
# para que cada deploy o garanta, sem depender de alguem correr mcp:install.
if [ -f /app/storage/oauth-private.key ]; then
    chmod 600 /app/storage/oauth-private.key
fi
if [ -f /app/storage/oauth-public.key ]; then
    chmod 640 /app/storage/oauth-public.key
fi
