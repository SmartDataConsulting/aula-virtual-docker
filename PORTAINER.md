# Alternativa De Deploy Con Portainer Y Nginx Proxy Manager

Esta guia documenta una alternativa para un VPS que ya tiene Portainer y Nginx Proxy Manager ocupando los puertos `80` y `443`. El procedimiento canonico comprobado usa `/opt/aula-virtual/docker-compose.prod.yml`, proyecto `aula-virtual-prod`, rama `master` del repositorio GitHub.

`docker-compose.portainer.yml` no es el Compose productivo oficial y no sustituye el procedimiento canonico.

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
    portal/
      google-service-account.json
    api/
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

Copia las credenciales fuera de Git. Portal y API pueden usar cuentas diferentes:

```bash
mkdir -p /opt/aula-virtual/secrets/portal /opt/aula-virtual/secrets/api
cp /ruta/segura/credencial-portal.json /opt/aula-virtual/secrets/portal/google-service-account.json
cp /ruta/segura/credencial-api.json /opt/aula-virtual/secrets/api/google-service-account.json
sudo chown 33:33 /opt/aula-virtual/secrets/portal/google-service-account.json /opt/aula-virtual/secrets/api/google-service-account.json
sudo chmod 600 /opt/aula-virtual/secrets/portal/google-service-account.json /opt/aula-virtual/secrets/api/google-service-account.json
stat -c '%n uid=%u gid=%g mode=%a' /opt/aula-virtual/secrets/portal/google-service-account.json /opt/aula-virtual/secrets/api/google-service-account.json
```

Las imagenes PHP actuales ejecutan los workers PHP-FPM como `www-data`, UID/GID `33:33`. Los archivos montados con `chmod 600` deben pertenecer numericamente a `33:33`; de otro modo el proceso web no podra leerlos aunque el entrypoint del contenedor arranque como root. Si cambia la imagen base, vuelve a comprobar el UID/GID. Despues de desplegar, abre la consola de cada contenedor PHP en Portainer con usuario `33:33` y ejecuta `test -r` sobre su propia ruta bajo `/run/secrets`; el comando no debe imprimir el archivo.

Si el pipeline GitLab se habilita explicitamente en el futuro, su preflight comprobara en el VPS una credencial independiente para portal y otra para API mediante `AULA_PORTAL_SECRETS_DIR` y `AULA_API_SECRETS_DIR`. Las credenciales no se copian ni se almacenan en GitLab.

## Stack Recomendado Para Esta Alternativa

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

No necesitas definir `PORTAL_ENV_FILE`, `API_ENV_FILE`, `AULA_PORTAL_SECRETS_DIR` ni `AULA_API_SECRETS_DIR` si usas la estructura recomendada; el compose ya tiene esos valores por defecto.

7. Antes de desplegar, confirma que existen:

```bash
/opt/aula-virtual/.env.portal
/opt/aula-virtual/.env.api
/opt/aula-virtual/secrets/portal/google-service-account.json
/opt/aula-virtual/secrets/api/google-service-account.json
```

El compose usa rutas absolutas para `.env.portal`, `.env.api` y ambos directorios de secretos. En `.env.portal` usa `/run/secrets/aula-portal/google-service-account.json`; en `.env.api`, `/run/secrets/aula-api/google-service-account.json`. Los mounts son de solo lectura y cada aplicacion ve unicamente su directorio.

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

- Stack `aula-virtual-prod`.
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
git status --short --untracked-files=no

if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "ERROR: existen cambios tracked locales; abortando actualizacion." >&2
  exit 1
fi

git fetch origin master
git pull --ff-only origin master
```

2. En Portainer:

- Stack `aula-virtual-prod`.
- `Editor`.
- `Update the stack`.
- Activar `Re-pull image and redeploy` si aplica.

Si cambiaste Dockerfile o dependencias, fuerza rebuild desde consola:

```bash
cd /opt/aula-virtual
docker compose -f docker-compose.portainer.yml build
docker compose -f docker-compose.portainer.yml up -d
```

## GitLab No Opera Actualmente Esta Alternativa

GitHub es el repositorio canonico y el GitLab self-hosted no opera actualmente Aula Virtual. El `.gitlab-ci.yml` raiz se conserva inactivo y preparado con `master` y `docker-compose.prod.yml`; no automatiza esta variante Portainer.

Una habilitacion futura exigiria una decision separada, mirror o repositorio GitLab, variables protegidas y un runner autorizado. Los pipelines internos de `aula-virtual` y `aula-virtual-api-servicios` siguen obsoletos. No configures automatizacion productiva desde esta guia.

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

- No subas `.env.portal`, `.env.api`, `.env.deploy` ni ningun archivo dentro de `secrets/portal` o `secrets/api` al repositorio.
- El API debe quedar interno. No publiques `api-nginx` con puerto externo.
- Nginx Proxy Manager es quien maneja SSL. No uses Caddy en este VPS para Aula.
- Si aparece `502 Bad Gateway`, revisa primero logs de `portal`, `portal-nginx` y la configuracion del Proxy Host.
- Si no carga estilos, confirma que `portal-nginx` fue construido con `npm run build` y que existe `/public/build`.
