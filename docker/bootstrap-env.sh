#!/bin/sh
# Cria o .env de producao a partir do .env.example na PRIMEIRA vez que o
# projeto e deployado numa maquina, para o deploy nao ficar bloqueado a
# espera de alguem entrar por SSH.
#
# IDEMPOTENTE de proposito: se o .env ja existir, nao lhe toca. A APP_KEY e a
# password do admin sao geradas UMA vez e tem de sobreviver a todos os deploys
# seguintes — trocar a APP_KEY invalida as sessoes e torna ilegivel tudo o que
# esteja encriptado na BD.
#
# Corre DENTRO de um container com o diretorio de estado do host montado (ver
# Jenkinsfile). O agente do Jenkins e ele proprio um container: escrever o
# caminho do host diretamente criaria o ficheiro no filesystem errado, e o
# compose — que resolve os mounts no host — acabaria a montar um diretorio
# vazio por cima de /app/.env.
#
# Uso: bootstrap-env.sh <caminho do .env.example> <caminho do .env a criar>
set -e

EXAMPLE="${1:?falta o caminho do .env.example}"
TARGET="${2:?falta o caminho do .env a criar}"
DIR=$(dirname "$TARGET")

# O bind mount do compose aponta para este diretorio; se nao existir, o Docker
# cria-o a si proprio como root em cima do que a imagem traz.
#
# So o storage/: criar aqui um public/ era o que alimentava o mount que tapava
# o document root (o Docker aceitava-o vazio e o site respondia 404 a tudo).
mkdir -p "$DIR/storage"

if [ -f "$TARGET" ]; then
    echo "[env] $TARGET ja existe — nao foi tocado."
    exit 0
fi

echo "[env] $TARGET nao existe. A criar a partir de $EXAMPLE."

APP_KEY="base64:$(openssl rand -base64 32)"
GATE_SECRET=$(openssl rand -hex 16)
ADMIN_PASSWORD=$(openssl rand -base64 18)

sed \
    -e "s|^APP_ENV=.*|APP_ENV=production|" \
    -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
    -e "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" \
    -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
    -e "s|^SEED_ADMIN_PASSWORD=.*|SEED_ADMIN_PASSWORD=${ADMIN_PASSWORD}|" \
    -e "s|^LOGIN_GATE_SECRET=.*|LOGIN_GATE_SECRET=${GATE_SECRET}|" \
    -e "s|^SESSION_DRIVER=.*|SESSION_DRIVER=redis|" \
    -e "s|^QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" \
    -e "s|^CACHE_STORE=.*|CACHE_STORE=redis|" \
    -e "s|^REDIS_HOST=.*|REDIS_HOST=redis|" \
    "$EXAMPLE" > "$TARGET"

# No .env.example o DB_DATABASE esta comentado (em dev a BD e o ficheiro por
# omissao). Em producao tem de viver no bind mount storage/, que persiste
# entre deploys e onde caem os backups do db:backup.
printf '\nDB_DATABASE=/app/storage/database/database.sqlite\n' >> "$TARGET"

# Legivel pelo user "application" dentro do container — o mount e :ro e este
# ficheiro fica num diretorio privado do servidor.
chmod 644 "$TARGET"

echo "[env] $TARGET criado."
echo "[env] ATENCAO — dois valores que so tu podes decidir:"
echo "[env]   APP_URL ficou com o valor do exemplo. Poe o dominio real, senao"
echo "[env]   os links dos emails e dos redirects saem errados."
echo "[env]   SEED_ADMIN_PASSWORD foi gerada ao acaso e NAO e mostrada aqui"
echo "[env]   (os logs do Jenkins ficam guardados). Le-a em $TARGET."
