# Deploy A VPS - Aula Virtual

Esta carpeta contiene el despliegue productivo de `aula-virtual` y `aula-virtual-api-servicios` con Docker Compose, PHP-FPM, Nginx, Redis, workers y scheduler. El repositorio canonico esta en GitHub, la rama productiva es `master`, el working directory del VPS es `/opt/aula-virtual` y el proyecto Compose es `aula-virtual-prod`.

El Compose productivo oficial es `docker-compose.prod.yml`. Si un entorno alternativo usa Portainer con Nginx Proxy Manager, consulta `PORTAINER.md`; esa variante no sustituye el procedimiento canonico.

## Arquitectura

- `portal`: Laravel PHP-FPM.
- `portal-nginx`: Nginx interno del portal.
- `api`: Lumen PHP-FPM.
- `api-nginx`: Nginx interno del API.
- `redis`: cache, sesiones y colas.
- `portal-worker` y `api-worker`: workers de colas.
- `portal-scheduler` y `api-scheduler`: scheduler cada minuto.
- `reverse-proxy`: Caddy opcional, disponible unicamente mediante el profile `caddy`.

El API no se expone publicamente. El portal lo consume por la red interna Docker usando `http://api-nginx`.

El stack productivo actualmente verificado no ejecuta ningun contenedor Caddy. `portal-nginx` se publica en `127.0.0.1:8010` para que un proxy externo gestione HTTPS. Caddy solo forma parte del Compose cuando un operador habilita explicitamente el profile `caddy` en otro escenario.

## Archivos Importantes

- `docker-compose.prod.yml`: compose productivo.
- `.env.deploy.example`: dominio publico.
- `.env.portal.example`: variables del portal.
- `.env.api.example`: variables del API.
- `secrets/portal/` y `secrets/api/`: credenciales externas a Git, montadas en solo lectura y separadas por aplicacion.
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

6. Copiar los secretos. Portal y API pueden usar cuentas distintas; no reutilices el mismo archivo sin confirmarlo:

```bash
mkdir -p secrets/portal secrets/api
cp /ruta/segura/credencial-portal.json secrets/portal/google-service-account.json
cp /ruta/segura/credencial-api.json secrets/api/google-service-account.json
sudo chown 33:33 secrets/portal/google-service-account.json secrets/api/google-service-account.json
sudo chmod 600 secrets/portal/google-service-account.json secrets/api/google-service-account.json
stat -c '%n uid=%u gid=%g mode=%a' secrets/portal/google-service-account.json secrets/api/google-service-account.json
```

Las imagenes PHP actuales ejecutan los workers PHP-FPM como `www-data`, UID/GID `33:33`. Como el bind mount conserva el propietario numerico del host, `chmod 600` solo permite la lectura si cada archivo pertenece a `33:33`; que el entrypoint arranque como root no cambia el usuario que atiende las solicitudes. Si se actualiza la imagen base, confirma de nuevo el UID/GID antes de aplicar `chown`. Tras levantar los servicios, verifica sin imprimir contenido:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec -T --user 33:33 portal test -r /run/secrets/aula-portal/google-service-account.json
docker compose -f docker-compose.prod.yml --env-file .env.deploy exec -T --user 33:33 api test -r /run/secrets/aula-api/google-service-account.json
```

Configura `GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH=/run/secrets/aula-portal/google-service-account.json` en `.env.portal` y `GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH=/run/secrets/aula-api/google-service-account.json` en `.env.api`. Las rutas absolutas se conservan; fuera de Docker tambien se admiten rutas relativas a la raiz de cada aplicacion. El build y el arranque no autentican contra Google, pero una operacion de Drive devuelve un error controlado si el archivo falta o es invalido.

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
git status --short --untracked-files=no

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERROR: existen cambios tracked locales; abortando actualizacion." >&2
  exit 1
fi

git fetch origin master
git pull --ff-only origin master
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
- `secrets/portal/google-service-account.json` y `secrets/api/google-service-account.json` no estan en Git ni en las imagenes.
- Backups de base de datos, envs y secretos configurados.

## Rotar Credenciales De Google Drive

1. Crea credenciales sustitutas fuera del repositorio y revisa sus permisos minimos en Google Cloud.
2. Instala cada archivo en su directorio de secretos y conserva temporalmente la credencial anterior fuera de Git para rollback.
3. Reconstruye los contenedores, regenera `config:cache` y reinicia los procesos que usan la configuracion.
4. Valida OAuth, subida resumible, consulta y eliminacion controlada desde portal y API.
5. Revoca las claves anteriores en Google Cloud solo despues de validar el reemplazo.
6. Reinicia los servicios o elimina exclusivamente las entradas de cache OAuth de Drive para no conservar tokens emitidos con la clave anterior.

La revocacion, la revision de logs de Google y cualquier limpieza de historial Git son acciones externas y separadas del despliegue de codigo.

## Rollback

Antes de migrar, respaldar base de datos y envs. Si el despliegue falla:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.deploy down
git checkout TAG_O_COMMIT_ANTERIOR
docker compose -f docker-compose.prod.yml --env-file .env.deploy build
docker compose -f docker-compose.prod.yml --env-file .env.deploy up -d
```

Si una migracion fallo despues de modificar datos, restaurar el backup de base antes de levantar la version anterior.

## Pipeline GitLab Conservado, Actualmente Inactivo

GitHub es el repositorio canonico y el despliegue productivo actual no depende de GitLab. El `.gitlab-ci.yml` se conserva preparado para una habilitacion futura explicita, con defaults `PROD_BRANCH=master` y `COMPOSE_FILE=docker-compose.prod.yml`.

La sola presencia del archivo no habilita el pipeline actual: requeriria configurar deliberadamente un mirror o repositorio GitLab, variables protegidas, credenciales SSH y un runner con tag `deploy-aula-prod`. Los jobs productivos tambien estan limitados por reglas a la rama configurada. No configures esos componentes como parte del procedimiento manual actual.

El job `migrate_production` permanece manual y, si el pipeline se habilita en el futuro, debe ejecutarse solo despues de respaldar `u937232440_sd_core`. Nunca debe ejecutar migraciones sobre WordPress `u937232440_WPVF9`.

### CI Antiguos

Los pipelines internos de `aula-virtual/.gitlab-ci.yml` y `aula-virtual-api-servicios/.gitlab-ci.yml` quedaron como obsoletos. No los uses para produccion; apuntaban a rutas y contenedores antiguos.
