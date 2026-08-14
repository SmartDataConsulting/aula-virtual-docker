# Deploy Con Portainer Y Nginx Proxy Manager

Esta guia adapta el despliegue de Aula Virtual para un VPS que ya tiene Portainer y Nginx Proxy Manager ocupando los puertos `80` y `443`.

## Recomendacion

Usa Portainer solo para administrar el Stack. Mantén los secretos y `.env` reales fuera del repositorio, directamente en el VPS.

Ruta recomendada en el VPS:

```bash
/opt/aula-virtual/
  aula-virtual/
  aula-virtual-api-servicios/
  docker/
  docker-compose.portainer.yml
  .env.deploy
  .env.portal
  .env.api
  secrets/
    google-service-account.json
```

## Preparar Archivos En El VPS

Entra por SSH al VPS:

```bash
sudo mkdir -p /opt/aula-virtual/secrets
sudo chown -R $USER:$USER /opt/aula-virtual
cd /opt/aula-virtual
```

Copia el proyecto o clona el repositorio dentro de `/opt/aula-virtual`.

Luego crea los env reales:

```bash
cp .env.deploy.example .env.deploy
cp .env.portal.example .env.portal
cp .env.api.example .env.api
```

Edita:

```bash
nano .env.deploy
nano .env.portal
nano .env.api
```

Genera `APP_KEY`:

```bash
docker run --rm php:8.2-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Pon una key en `.env.portal` y otra en `.env.api` si tu API usa `APP_KEY`.

Copia el service account:

```bash
cp /ruta/segura/google-service-account.json /opt/aula-virtual/secrets/google-service-account.json
chmod 600 /opt/aula-virtual/secrets/google-service-account.json
```

## Stack Recomendado

Usa `docker-compose.portainer.yml`. Esta variante no levanta Caddy y publica solo el portal en el puerto interno `8010`, dejando `80/443` para Nginx Proxy Manager.

1. Portainer > `Stacks`.
2. `Add stack`.
3. Nombre: `aula-virtual-prod`.
4. Metodo: `Repository` si Portainer tiene acceso al repo, o `Web editor` si pegaras el YAML.
5. Usa el contenido de `docker-compose.portainer.yml`.
6. En `Environment variables` agrega si quieres cambiar el puerto por defecto:

```text
PORTAL_HTTP_PORT=8010
```

No necesitas definir `PORTAL_ENV_FILE`, `API_ENV_FILE` ni `AULA_SECRETS_DIR` en el VPS si usas la ruta recomendada `/opt/aula-virtual`; el compose ya tiene esos valores por defecto.

7. Antes de desplegar, confirma que existen:

```bash
/opt/aula-virtual/.env.portal
/opt/aula-virtual/.env.api
/opt/aula-virtual/secrets/google-service-account.json
```

El compose ya usa rutas absolutas para `.env.portal`, `.env.api` y `secrets/`, por eso funciona aunque Portainer despliegue desde una ruta temporal.

## Primer Deploy

En Portainer:

1. `Stacks`.
2. `Add stack`.
3. Pega o selecciona el compose.
4. `Deploy the stack`.

Luego valida por SSH:

```bash
docker compose -f /opt/aula-virtual/docker-compose.portainer.yml ps
```

O desde Portainer:

- Stack `aula-virtual`.
- Revisa que todos los servicios esten `running`.
- Revisa logs de `portal`, `api`, `portal-nginx` y `api-nginx`.

## Configurar Nginx Proxy Manager

Crea un Proxy Host:

- `Domain Names`: `aula.tudominio.com`
- `Scheme`: `http`
- `Forward Hostname / IP`: IP del VPS
- `Forward Port`: `8010`
- `Cache Assets`: opcional
- `Block Common Exploits`: activo
- `Websockets Support`: activo

En `SSL`:

- `Request a new SSL Certificate`
- `Force SSL`
- `HTTP/2 Support`

En `Advanced` agrega:

```nginx
client_max_body_size 1024m;
proxy_read_timeout 600s;
proxy_send_timeout 600s;
proxy_connect_timeout 60s;
```

No crees un Proxy Host para `api-nginx`; el API queda interno y lo consume el portal con `http://api-nginx`.

## Bases De Datos De Produccion

Aula Virtual usa dos fuentes en produccion:

- WordPress/JWT para alumnos: base `u937232440_WPVF9`, consumida por `WP_AUTH_BASE_URL`.
- Core SmartData para cursos y operaciones: base `u937232440_sd_core`, consumida por el API mediante `DB_CURSOS_*`.

No ejecutes migraciones de Aula sobre `u937232440_WPVF9`. WordPress mantiene esa base.

Tu VPS ya publica `wp-mysql` en el host. Si no unes redes Docker, usa en `/opt/aula-virtual/.env.api`:

```env
DB_CURSOS_HOST=host.docker.internal
DB_CURSOS_PORT=3307
DB_CURSOS_DATABASE=u937232440_sd_core
DB_CURSOS_USERNAME=aula_core_user
DB_CURSOS_PASSWORD=CAMBIAR_PASSWORD_CORE
```

El compose agrega `host.docker.internal:host-gateway` para que funcione en Linux.

Si luego decides unir el stack a la red donde vive `wp-mysql`, cambia a:

```env
DB_CURSOS_HOST=wp-mysql
DB_CURSOS_PORT=3306
DB_CURSOS_DATABASE=u937232440_sd_core
```

En `/opt/aula-virtual/.env.portal` configura WordPress:

```env
WP_AUTH_BASE_URL=https://TU_WORDPRESS
WP_JWT_TOKEN_PATH=/wp-json/jwt-auth/v1/token
WP_JWT_VALIDATE_PATH=/wp-json/jwt-auth/v1/token/validate
API_SERVICIOS_BASE_URL=http://api-nginx
```

## Comandos Post Deploy

Desde Portainer puedes abrir consola en el contenedor `portal` y ejecutar:

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

En el contenedor `api`:

```bash
php artisan config:cache
php artisan route:cache
php artisan migrate:status
php artisan migrate --force
```

Antes de ejecutar `migrate --force`, crea un backup de `u937232440_sd_core`. No necesitas respaldar ni modificar `u937232440_WPVF9` para las migraciones de Aula.

## Actualizar Version

Si usas Git:

1. Entra por SSH:

```bash
cd /opt/aula-virtual
git pull
```

2. En Portainer:

- Stack `aula-virtual`.
- `Editor`.
- `Update the stack`.
- Activar `Re-pull image and redeploy` si aplica.

Si cambiaste Dockerfile o dependencias, fuerza rebuild desde consola:

```bash
cd /opt/aula-virtual
docker compose -f docker-compose.portainer.yml build
docker compose -f docker-compose.portainer.yml up -d
```

## Actualizacion Automatica Desde GitLab

Para produccion se recomienda que Portainer administre los contenedores, pero que GitLab CI ejecute el deploy por SSH. Asi el pipeline puede validar, desplegar, optimizar y hacer healthchecks.

Configura el repo raiz `aula-virtual-docker` en GitLab y usa el `.gitlab-ci.yml` de la raiz. Los pipelines internos de `aula-virtual` y `aula-virtual-api-servicios` estan obsoletos.

Variables necesarias en GitLab:

```text
VPS_HOST=IP_O_HOST_DEL_VPS
VPS_USER=root
VPS_SSH_PRIVATE_KEY=CLAVE_PRIVADA_DEL_DEPLOY
DEPLOY_PATH=/opt/aula-virtual
PROD_BRANCH=main
PRODUCTION_URL=https://aula.tudominio.com
```

El runner debe tener acceso a Docker y SSH, con tag:

```text
deploy-aula-prod
```

Cuando hagas merge o push a `main`, GitLab ejecutara:

```bash
cd /opt/aula-virtual
git fetch origin main
git reset --hard origin/main
docker compose --env-file .env.deploy -f docker-compose.portainer.yml up -d --build --remove-orphans
```

Despues ejecutara cache de Laravel/Lumen y validara `/login` y `api-nginx/up`.

Las migraciones quedan en el job manual `migrate_production`. Antes de ejecutarlo, respalda `u937232440_sd_core`. No se ejecutan migraciones sobre WordPress.

## Validacion Rapida

Desde el VPS:

```bash
curl -I http://127.0.0.1:8010/login
curl -I https://aula.tudominio.com/login
docker logs --tail=100 aula-virtual-prod-portal-1
docker logs --tail=100 aula-virtual-prod-api-1
```

Desde el navegador:

- Login admin.
- Login docente.
- Login alumno.
- Cursos.
- Evaluaciones.
- Encuestas.
- Calificaciones.
- Certificados.
- Subida de video/material.

## Notas Importantes

- No subas `.env.portal`, `.env.api`, `.env.deploy` ni `secrets/google-service-account.json` al repositorio.
- El API debe quedar interno. No publiques `api-nginx` con puerto externo.
- Nginx Proxy Manager es quien maneja SSL. No uses Caddy en este VPS para Aula.
- Si aparece `502 Bad Gateway`, revisa primero logs de `portal`, `portal-nginx` y la configuracion del Proxy Host.
- Si no carga estilos, confirma que `portal-nginx` fue construido con `npm run build` y que existe `/public/build`.
