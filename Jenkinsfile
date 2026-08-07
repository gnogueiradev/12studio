// Pipeline adaptado do projeto qrcode: testes por suite com budgets de tempo,
// lint, build com tag :previous para rollback, deploy por ordenacao
// (assets antes do swap) e health-gate com rollback automatico.
//
// AJUSTAR APP_DIR ao caminho real do checkout de deploy no servidor.

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
                '''
            }
        }

        stage('Deploy') {
            steps {
                sh '''
                    # 1. Assets para o public/ bind-mounted ANTES do swap — o
                    #    container antigo continua a servir durante o build.
                    docker compose run --rm --no-deps app sh -c 'npm ci && npm run build'

                    # 2. Swap do container.
                    docker compose up -d --remove-orphans

                    # 3. Dependencias de producao + BACKUP DA BD ANTES do
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

                    # 4. Ownership dos mounts partilhados.
                    docker compose exec -T app chown -R application:application /app/public /app/bootstrap /app/storage
                '''
            }
        }

        stage('Health Check') {
            steps {
                sh '''
                    ok=0
                    for i in $(seq 1 12); do
                        if curl -fsS http://localhost/up > /dev/null 2>&1; then
                            ok=1
                            break
                        fi
                        sleep 5
                    done

                    if [ "$ok" -ne 1 ]; then
                        echo "Health check falhou — rollback para :previous."
                        docker tag ${IMAGE_NAME}:previous ${IMAGE_NAME}:latest
                        docker compose up -d --remove-orphans
                        exit 1
                    fi
                '''
            }
        }
    }

    post {
        always {
            sh 'docker image prune -f'
        }
    }
}
