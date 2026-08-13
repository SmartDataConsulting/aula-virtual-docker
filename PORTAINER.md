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

## Conexion A MySQL Existente

Tu VPS ya publica `wp-mysql` en el host. Si no unes redes Docker, usa en `/opt/aula-virtual/.env.api`:

```env
DB_CURSOS_HOST=host.docker.internal
DB_CURSOS_PORT=3307
DB_CURSOS_DATABASE=smartdata
```

El compose agrega `host.docker.internal:host-gateway` para que funcione en Linux.

Si luego decides unir el stack a la red donde vive `wp-mysql`, cambia a:

```env
DB_CURSOS_HOST=wp-mysql
DB_CURSOS_PORT=3306
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
php artisan migrate --force
```

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
