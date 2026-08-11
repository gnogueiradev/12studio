// Pipeline adaptado do projeto qrcode: testes por suite com budgets de tempo,
// lint, build com tag :previous para rollback e health-gate com rollback
// automatico.
//
// APP_DIR e so o diretorio de ESTADO do servidor (.env + storage/), criado
// pelo docker/bootstrap-env.sh — nao e um checkout do repo como no qrcode. O
// codigo e os assets chegam sempre pela imagem; nada em APP_DIR e servido.

pipeline {
    agent any

    environment {
        IMAGE_NAME = '12studio'
        COMPOSE_FILE = 'docker-compose.yml'
        APP_DIR = '/home/cinhos/projects/12studio/app/12studio'
    }

    stages {
        stage('Test Unit') {
            steps {
                timeout(time: 6, unit: 'MINUTES') {
                    sh '''
                        docker build --target build -t ${IMAGE_NAME}-test .
                        docker run --rm \
                            -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
                            -e SESSION_DRIVER=array -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
                            -e MAIL_MAILER=array -e BCRYPT_ROUNDS=4 \
                            ${IMAGE_NAME}-test sh -c '
                                composer install --no-interaction &&
                                echo "APP_KEY=" > .env && php artisan key:generate &&
                                php artisan test --testsuite=Unit
                            '
                    '''
                }
            }
        }

        stage('Test Feature') {
            steps {
                timeout(time: 12, unit: 'MINUTES') {
                    sh '''
                        docker run --rm \
                            -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
                            -e SESSION_DRIVER=array -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
                            -e MAIL_MAILER=array -e BCRYPT_ROUNDS=4 \
                            ${IMAGE_NAME}-test sh -c '
                                composer install --no-interaction &&
                                echo "APP_KEY=" > .env && php artisan key:generate &&
                                php artisan test --testsuite=Feature
                            '
                    '''
                }
            }
        }

        stage('Lint') {
            steps {
                timeout(time: 6, unit: 'MINUTES') {
                    sh '''
                        docker run --rm ${IMAGE_NAME}-test sh -c '
                            composer install --no-interaction &&
                            vendor/bin/pint --parallel --test &&
                            vendor/bin/phpstan analyse --no-progress
                        '
                    '''
                }
            }
        }

        stage('Build') {
            steps {
                sh '''
                    # O .env de producao vive em APP_DIR, fora do workspace, e
                    # e a primeira coisa de que o deploy precisa. Se ainda nao
                    # existir, nasce do .env.example — ver docker/bootstrap-env.sh.
                    #
                    # Corre dentro da imagem de teste (ja construida, tem PHP e
                    # openssl) com APP_DIR montado: o agente do Jenkins e um
                    # container, e o compose resolve os mounts no HOST. Escrever
                    # o caminho a partir daqui punha o ficheiro do lado errado.
                    docker run --rm -v ${APP_DIR}:/appdir ${IMAGE_NAME}-test \
                        sh /app/docker/bootstrap-env.sh /app/.env.example /appdir/.env

                    # Tag de rollback: a imagem atual passa a :previous ANTES
                    # do build — um deploy mau reverte com um re-tag.
                    docker tag ${IMAGE_NAME}:latest ${IMAGE_NAME}:previous || true

                    docker compose build

                    # Smoke da config do nginx ANTES do swap. O entrypoint da
                    # imagem corre o provisioning (go-replace nos templates)
                    # seja qual for o comando, e so depois faz exec — por isso
                    # este `nginx -t` valida exatamente a config com que o
                    # container vai arrancar. Sem esta linha, um erro de config
                    # so aparece no health check, com o container antigo ja
                    # trocado e a fila de deploy a reverter (aconteceu: uma
                    # diretiva duplicada matava o nginx em loop de reinicio).
                    docker run --rm ${IMAGE_NAME}:latest nginx -t
                '''
            }
        }

        stage('Deploy') {
            steps {
                sh '''
                    # 1. Swap do container. Os assets NAO se constroem aqui:
                    #    vem dentro da imagem (Dockerfile, npm run build:ssr),
                    #    por isso codigo e assets trocam ao mesmo tempo e um
                    #    rollback da imagem repoe os dois em conjunto. Construi-
                    #    los para um public/ montado punha assets novos a servir
                    #    por codigo antigo — e o vite esvazia o build/ antes de
                    #    escrever, deixando o container em producao sem assets
                    #    durante o build.
                    docker compose up -d --remove-orphans

                    # 2. Dependencias de producao + BACKUP DA BD ANTES do
                    #    migrate: o rollback repoe a imagem, nao as migracoes —
                    #    com SQLite, o backup pre-migracao e o ponto de restauro.
                    docker compose exec -T app sh -c '
                        composer install --no-dev --optimize-autoloader --no-interaction &&
                        php artisan db:backup &&
                        php artisan migrate --force &&
                        php artisan db:seed --force &&
                        php artisan cache:clear &&
                        php artisan storage:link &&
                        php artisan optimize &&
                        php artisan queue:restart
                    '

                    # 3. Ownership dos mounts partilhados.
                    docker compose exec -T app chown -R application:application /app/bootstrap /app/storage
                '''
            }
        }

        stage('Health Check') {
            steps {
                timeout(time: 3, unit: 'MINUTES') {
                    sh '''
                        # O /up e batido DE DENTRO do container. O agente do
                        # Jenkins e ele proprio um container e o servico app nao
                        # publica portas (vive atras do Nginx Proxy Manager), por
                        # isso um `curl http://localhost/up` daqui batia no
                        # proprio Jenkins — o gate respondia por uma maquina que
                        # nada tem a ver com o deploy. E o mesmo /up que a
                        # imagem ja usa no seu HEALTHCHECK (ver Dockerfile).
                        ok=0
                        for i in $(seq 1 24); do
                            if docker compose exec -T app curl -fsS -o /dev/null http://localhost/up; then
                                ok=1
                                break
                            fi
                            sleep 5
                        done

                        if [ "$ok" -eq 1 ]; then
                            echo "Health check OK — /up responde dentro do container."
                            exit 0
                        fi

                        echo "Health check falhou. Estado e logs do container:"
                        docker compose ps app || true
                        docker compose logs --tail 50 app || true

                        # A tag :previous so passa a existir no segundo deploy.
                        # Sem esta guarda o `docker tag` falha, o shell morre no
                        # -e e o rollback fica a meio: imagem por trocar e
                        # container velho no ar, sem ninguem dizer porque.
                        if docker image inspect ${IMAGE_NAME}:previous > /dev/null 2>&1; then
                            echo "Rollback para ${IMAGE_NAME}:previous."
                            docker tag ${IMAGE_NAME}:previous ${IMAGE_NAME}:latest
                            docker compose up -d --remove-orphans
                        else
                            echo "Nao existe ${IMAGE_NAME}:previous (primeiro deploy) — nao ha para onde reverter."
                            echo "O container fica como esta, para poderes investigar."
                        fi

                        exit 1
                    '''
                }
            }
        }
    }

    post {
        always {
            sh 'docker image prune -f'
        }
    }
}
