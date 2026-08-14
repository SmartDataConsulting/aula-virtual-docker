# Deploy A VPS - Aula Virtual

Esta carpeta contiene el despliegue productivo de `aula-virtual` y `aula-virtual-api-servicios` con Docker Compose, PHP-FPM, Nginx, Redis, workers y scheduler.

Si tu VPS usa Portainer con Nginx Proxy Manager, usa `docker-compose.portainer.yml` y la guia `PORTAINER.md`.

## Arquitectura

- `portal`: Laravel PHP-FPM.
- `portal-nginx`: Nginx interno del portal.
- `api`: Lumen PHP-FPM.
- `api-nginx`: Nginx interno del API.
- `redis`: cache, sesiones y colas.
- `portal-worker` y `api-worker`: workers de colas.
- `portal-scheduler` y `api-scheduler`: scheduler cada minuto.
- `reverse-proxy`: Caddy publico con HTTPS.

El API no se expone publicamente. El portal lo consume por la red interna Docker usando `http://api-nginx`.

## Archivos Importantes

- `docker-compose.prod.yml`: compose productivo.
- `.env.deploy.example`: dominio publico.
- `.env.portal.example`: variables del portal.
- `.env.api.example`: variables del API.
- `secrets/`: secretos montados en solo lectura, por ejemplo `google-service-account.json`.
- `aula-virtual/Dockerfile.prod`: imagen PHP-FPM del portal.
- `aula-virtual/Dockerfile.nginx.prod`: imagen Nginx del portal con assets Vite.
- `aula-virtual-api-servicios/docker/php/Dockerfile.prod`: imagen PHP-FPM del API.
- `aula-virtual-api-servicios/docker/nginx/Dockerfile.prod`: imagen Nginx del API.

## Preparar VPS

1. Instalar Docker y Docker Compose.
2. Crear carpeta:

```bash
sudo mkdir -p /opt/aula-virtual
sudo chown -R $USER:$USER /opt/aula-virtual
cd /opt/aula-virtual
```

Si el VPS usa Portainer, revisa tambien `PORTAINER.md`. El compose productivo funciona igual, pero Portainer puede necesitar rutas absolutas para `.env.portal`, `.env.api` y `secrets/`.

3. Subir o clonar este contenido en `/opt/aula-virtual`.
4. Crear variables reales:

```bash
cp .env.deploy.example .env.deploy
cp .env.portal.example .env.portal
cp .env.api.example .env.api
```

5. Editar los tres archivos y cambiar placeholders:

- `AULA_DOMAIN`
- `APP_URL`
- `APP_KEY`
- `INTERNAL_SERVICE_TOKEN`
- `DB_CURSOS_*`
- `DB_SGA_*`
- `WP_AUTH_BASE_URL`
- Google Drive
- Zoom
- SGA/Certificados

Genera `APP_KEY` antes de editar el env. Ejemplo:

```bash
docker run --rm php:8.2-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Usa una key para `.env.portal` y otra para `.env.api` si el API la requiere.

6. Copiar secretos:

```bash
mkdir -p secrets
cp /ruta/segura/google-service-account.json secrets/google-service-account.json
chmod 600 secrets/google-service-account.json
```

## Construir

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy build
```

## Levantar

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy up -d
```

## Optimizar Laravel/Lumen

Ejecuta despues de levantar y cada vez que cambies variables o rutas:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec portal php artisan config:cache
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec portal php artisan route:cache
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec portal php artisan view:cache

docker compose -f docker-compose.prod.yml --env-file .env.deploy exec api php artisan config:cache
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec api php artisan route:cache
```

## Bases De Datos Y Migraciones

En produccion Aula Virtual separa credenciales y datos academicos:

- Alumnos: WordPress JWT contra la base `u937232440_WPVF9`.
- Cursos, sesiones, certificados, asistencia, evaluaciones y encuestas: API contra `u937232440_sd_core`.
- Admin/docente: API contra `sd_core.usuario`.

Las migraciones del API solo deben ejecutarse sobre `mysql_cursos`, es decir `u937232440_sd_core`. No ejecutes migraciones de Aula sobre `u937232440_WPVF9`.

Antes de migrar:

```bash
mysqldump -h HOST -P PUERTO -u USUARIO -p u937232440_sd_core > backup_sd_core_$(date +%F_%H%M).sql
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec api php artisan migrate:status
```

Si hay migraciones pendientes del API:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec api php artisan migrate --force
```

## Verificar

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy ps
docker compose -f docker-compose.prod.yml --env-file .env.deploy logs --tail=100 portal
docker compose -f docker-compose.prod.yml --env-file .env.deploy logs --tail=100 api
curl -I https://TU_DOMINIO/login
```

Validaciones funcionales:

- Login alumno, docente y admin.
- `/mis-cursos`
- `/backoffice/courses`
- `/backoffice/evaluations`
- `/backoffice/attendance`
- `/backoffice/surveys`
- `/backoffice/qualifications`
- `/backoffice/certificates`
- Subida de material/video.
- Vista previa y descarga.
- Certificados.

## Operacion

Reiniciar workers:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy restart portal-worker api-worker
```

Ver logs:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy logs -f --tail=100
```

Actualizar version:

```bash
git pull
docker compose -f docker-compose.prod.yml --env-file .env.deploy build
docker compose -f docker-compose.prod.yml --env-file .env.deploy up -d
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec portal php artisan config:cache
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec portal php artisan view:cache
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec api php artisan config:cache
```

## Checklist Antes De Produccion

- `APP_DEBUG=false`.
- `LOG_LEVEL=warning`.
- `INTERNAL_SERVICE_TOKEN` fuerte e igual en portal y API.
- Redis activo.
- Workers y scheduler activos.
- API no expuesta publicamente.
- SSL activo.
- OPcache con `validate_timestamps=0`.
- `client_max_body_size` soporta videos grandes.
- MySQL usa usuario productivo, no `root`.
- `secrets/google-service-account.json` no esta en Git.
- Backups de base de datos, envs y secretos configurados.

## Rollback

Antes de migrar, respaldar base de datos y envs. Si el despliegue falla:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy down
git checkout TAG_O_COMMIT_ANTERIOR
docker compose -f docker-compose.prod.yml --env-file .env.deploy build
docker compose -f docker-compose.prod.yml --env-file .env.deploy up -d
```

Si una migracion fallo despues de modificar datos, restaurar el backup de base antes de levantar la version anterior.

## Deploy Automatico Con GitLab CI

El repo raiz `aula-virtual-docker` incluye un `.gitlab-ci.yml` para desplegar produccion por SSH cada vez que se actualiza `main`.

### Variables En GitLab

Configura estas variables en GitLab como `Protected` y, cuando aplique, `Masked`:

```text
VPS_HOST=IP_O_HOST_DEL_VPS
VPS_USER=root
VPS_SSH_PRIVATE_KEY=CLAVE_PRIVADA_DEL_DEPLOY
DEPLOY_PATH=/opt/aula-virtual
PROD_BRANCH=main
PRODUCTION_URL=https://aula.tudominio.com
```

El runner debe tener el tag:

```text
deploy-aula-prod
```

### Flujo

El pipeline hace:

1. Construccion de imagenes productivas del portal y API.
2. Pruebas del portal y API en contenedores aislados, sin publicar puertos.
3. Deploy por SSH:

```bash
git fetch origin main
git reset --hard origin/main
docker compose --env-file .env.deploy -f docker-compose.portainer.yml up -d --build --remove-orphans
```

4. Cache de configuracion, rutas y vistas.
5. Healthcheck de `/login` y `http://api-nginx/up`.

### Migraciones

El job `migrate_production` es manual. Antes de ejecutarlo, respalda `u937232440_sd_core`. Este job corre migraciones solo dentro del contenedor `api` y nunca toca la base WordPress `u937232440_WPVF9`.

### CI Antiguos

Los pipelines internos de `aula-virtual/.gitlab-ci.yml` y `aula-virtual-api-servicios/.gitlab-ci.yml` quedaron como obsoletos. No los uses para produccion; apuntaban a rutas y contenedores antiguos.
