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

# O ficheiro e montado inteiro so no fim (ver o mv la em baixo). O trap limpa o
# rascunho se alguma coisa rebentar pelo caminho — sem ele, um .partial ficava
# no diretorio de estado do servidor com segredos la dentro.
PARTIAL="${TARGET}.partial"
trap 'rm -f "$PARTIAL"' EXIT

# O Redis e EXTERNO: a instancia partilhada da rede `Projects` (ver
# docker-compose.yml). Nem o host nem a password se podem adivinhar aqui, e
# falhar agora — alto — e melhor do que escrever um valor errado e so descobrir
# no primeiro pedido em producao. A instancia tem `--requirepass`: sem password
# TODAS as ligacoes morrem em NOAUTH.
REDIS_HOST="${REDIS_HOST:?falta REDIS_HOST — nome do container Redis na rede Projects (docker run -e REDIS_HOST=...)}"
REDIS_PASSWORD="${REDIS_PASSWORD:?falta REDIS_PASSWORD — a instancia partilhada corre com --requirepass (docker run -e REDIS_PASSWORD=...)}"

# A password vai parar ao lado DIREITO de um `s|...|...|` do sed, onde `&`
# significa "o que a expressao apanhou" e `\` escapa. Uma password com `&` — e
# a que esta em uso tem — sairia corrompida para o .env, com um NOAUTH
# impossivel de ler. Escapar `\`, `&` e o delimitador `|` resolve.
REDIS_PASSWORD_SED=$(printf '%s' "$REDIS_PASSWORD" | sed -e 's|[\\&|]|\\&|g')

APP_KEY="base64:$(openssl rand -base64 32)"
GATE_SECRET=$(openssl rand -hex 16)
ADMIN_PASSWORD=$(openssl rand -base64 18)

# So o CACHE_STORE muda para redis. O SESSION_DRIVER e o QUEUE_CONNECTION ficam
# no `database` que vem do .env.example, DE PROPOSITO — nao e esquecimento:
#
# a instancia partilhada corre `--maxmemory-policy allkeys-lru` e SEM
# `--appendonly`. Ou seja, ao encher despeja qualquer chave, e um restart apaga
# tudo. Para cache isso e inofensivo (o cache existe para poder desaparecer).
# Para a fila era perda SILENCIOSA de jobs — o Redis apaga a chave e o Laravel
# nunca sabe que o job existiu; para as sessions era logout de toda a gente a
# cada restart.
#
# As tabelas `jobs`, `sessions` e `cache` ja vem nas migracoes 0001_01_01_*.
# Se um dia a instancia passar a `noeviction` + `appendonly yes`, basta voltar a
# por aqui as duas linhas de SESSION_DRIVER/QUEUE_CONNECTION.
#
# O SESSION_SECURE_COOKIE e o SESSION_ENCRYPT sao forcados a true AQUI, e nao
# deixados ao valor do .env.example, de proposito: em producao o cookie de
# sessao nunca pode sair sem a flag Secure. O bootstrap/app.php ja ensina o
# Laravel a reconhecer o TLS do proxy (trustProxies), o que por si so bastaria
# para o modo automatico acertar — mas esta linha e a que continua a valer se
# essa configuracao se perder um dia numa refactorizacao.
sed \
    -e "s|^APP_ENV=.*|APP_ENV=production|" \
    -e "s|^APP_DEBUG=.*|APP_DEBUG=false|" \
    -e "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" \
    -e "s|^LOG_LEVEL=.*|LOG_LEVEL=warning|" \
    -e "s|^SESSION_ENCRYPT=.*|SESSION_ENCRYPT=true|" \
    -e "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=true|" \
    -e "s|^SEED_ADMIN_PASSWORD=.*|SEED_ADMIN_PASSWORD=${ADMIN_PASSWORD}|" \
    -e "s|^LOGIN_GATE_SECRET=.*|LOGIN_GATE_SECRET=${GATE_SECRET}|" \
    -e "s|^CACHE_STORE=.*|CACHE_STORE=redis|" \
    -e "s|^REDIS_HOST=.*|REDIS_HOST=${REDIS_HOST}|" \
    -e "s|^REDIS_PASSWORD=.*|REDIS_PASSWORD='${REDIS_PASSWORD_SED}'|" \
    "$EXAMPLE" > "$PARTIAL"

# No .env.example o DB_DATABASE esta comentado (em dev a BD e o ficheiro por
# omissao). Em producao tem de viver no bind mount storage/, que persiste
# entre deploys e onde caem os backups do db:backup.
printf '\nDB_DATABASE=/app/storage/database/database.sqlite\n' >> "$PARTIAL"

# Este ficheiro tem a APP_KEY, a password do Redis, a do admin e as credenciais
# de SMTP. Estava em 644 — legivel por QUALQUER utilizador do host.
#
# O uid nao se adivinha: pergunta-se a imagem em que este script esta a correr.
# E a mesma familia de imagem que serve a app em runtime (ambas saem do
# Dockerfile, sobre webdevops/php-nginx), por isso o `application` daqui e o
# `application` de la. Escrever "1000" a mao era assumir um default que a imagem
# pode mudar — e um .env que o container nao consegue ler nao da erro de
# permissoes, da uma app sem APP_KEY que rebenta em cada pedido.
APP_UID=$(id -u application 2>/dev/null || true)
APP_GID=$(id -g application 2>/dev/null || true)
APP_UID="${APP_UID:?o utilizador 'application' nao existe nesta imagem — sem ele o .env ficava ilegivel para o PHP}"
APP_GID="${APP_GID:?o grupo 'application' nao existe nesta imagem — sem ele o .env ficava ilegivel para o PHP}"

# 600 e nao 640: o dono passa a ser o utilizador que corre o PHP, e mais ninguem
# alem do root precisa de ler isto. O mount para o container e :ro.
chown "$APP_UID:$APP_GID" "$PARTIAL"
chmod 600 "$PARTIAL"

# So agora e que o ficheiro passa a chamar-se .env, e a ordem e o ponto todo.
#
# Escrever direto no $TARGET e so depois arrumar as permissoes tinha um modo de
# falha silencioso e PERMANENTE: com `set -e`, uma falha no chown deixava o .env
# no sitio com permissoes largas e o deploy vermelho — e como este script nao
# toca num .env que ja exista, a repeticao dizia "ja existe" e nunca corrigia
# nada. Assim, uma falha a meio nao deixa .env nenhum e a tentativa seguinte
# recomeca limpa. O mv preserva o dono e o modo que acabaram de ser postos.
mv "$PARTIAL" "$TARGET"

echo "[env] $TARGET criado."
echo "[env] ATENCAO — dois valores que so tu podes decidir:"
echo "[env]   APP_URL ficou com o valor do exemplo. Poe o dominio real, senao"
echo "[env]   os links dos emails e dos redirects saem errados."
echo "[env]   SEED_ADMIN_PASSWORD foi gerada ao acaso e NAO e mostrada aqui"
echo "[env]   (os logs do Jenkins ficam guardados). Le-a com:"
echo "[env]     sudo grep SEED_ADMIN_PASSWORD $TARGET"
echo "[env]   O sudo e preciso: o ficheiro fica 600, do utilizador $APP_UID."
echo "[env]   Entra, muda-a, e liga o 2FA na conta de admin."
