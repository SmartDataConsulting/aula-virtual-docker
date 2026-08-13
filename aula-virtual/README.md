# Aula Virtual Smart Data

Portal web del Aula Virtual de Smart Data. El proyecto esta construido con Laravel 12, Vite y Tailwind CSS. Consume datos academicos desde `aula-virtual-api-servicios`, que actua como API interna sobre la base `smartdata` y servicios asociados.

## Arquitectura

En desarrollo local con Docker se trabaja desde:

```text
C:\xampp\htdocs\aula-virtual-docker
```

Estructura esperada:

```text
aula-virtual-docker/
  aula-virtual/                  # Portal Laravel
  aula-virtual-api-servicios/    # API interna Lumen
  docker/                        # Configuracion Nginx/PHP compartida
  docker-compose.local.yml       # Orquestacion local
```

Servicios principales:

- `portal`: Laravel PHP-FPM.
- `portal-nginx`: Nginx del portal en `http://127.0.0.1:8000`.
- `api-servicios`: Lumen PHP-FPM.
- `api-nginx`: Nginx del API en `http://127.0.0.1:8011`.
- `redis`: cache, sesiones y colas.
- `portal-worker` / `api-worker`: workers de cola.
- `portal-scheduler` / `api-scheduler`: scheduler.

## Modulos Funcionales

- Autenticacion de alumnos, docentes y administradores.
- Cursos del alumno y aula por sesion.
- Backoffice de cursos para docente y administrador.
- Materiales por sesion con vista previa y descarga.
- Videos de sesion con Google Drive y chat TXT de Zoom.
- Evaluaciones, trabajos practicos, hitos y calificaciones.
- Encuestas integradas con el esquema de `gen-docs`.
- Asistencia por sesion, con integracion preparada para Zoom.
- Certificados y diplomas integrados con datos de SGA.
- Comunidad del curso, comentarios y participantes.

## Requisitos

Para Docker local:

- Docker Desktop activo.
- MySQL local accesible desde contenedores por `host.docker.internal:3306`.
- Base local `smartdata`.
- Repositorios `aula-virtual` y `aula-virtual-api-servicios` dentro de `C:\xampp\htdocs\aula-virtual-docker`.

Para ejecucion sin Docker:

- PHP 8.2.
- Composer.
- Node.js 20+ recomendado.
- MySQL.
- Redis si se usa la configuracion de rendimiento.

## Configuracion Local

### Portal

Archivo:

```text
aula-virtual/.env
```

Variables importantes:

```env
APP_DEBUG=false
LOG_LEVEL=warning
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis

API_SERVICIOS_BASE_URL=http://api-nginx
INTERNAL_SERVICE_TOKEN=change-me
API_SERVICIOS_TIMEOUT=5
API_SERVICIOS_RETRY_TIMES=0

GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH=storage/google/service-account.json
GOOGLE_DRIVE_LMS_FOLDER_ID=
```

Si ejecutas el portal sin Docker, usa:

```env
API_SERVICIOS_BASE_URL=http://localhost:8011
REDIS_HOST=127.0.0.1
```

### API Servicios

Archivo:

```text
aula-virtual-api-servicios/src/.env
```

Variables importantes:

```env
APP_DEBUG=false
LOG_LEVEL=warning
INTERNAL_SERVICE_TOKEN=change-me

DB_CONNECTION=mysql_cursos
DB_CURSOS_HOST=host.docker.internal
DB_CURSOS_PORT=3306
DB_CURSOS_DATABASE=smartdata
DB_CURSOS_USERNAME=root
DB_CURSOS_PASSWORD=

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=redis

SGA_PUBLIC_BASE_URL=http://localhost/sga-web
CERTIFICADO_PUBLIC_BASE_URL=http://localhost:8011/Certificados
```

El valor de `INTERNAL_SERVICE_TOKEN` debe coincidir entre portal y API.

## Levantar Con Docker Local

Desde la carpeta raiz:

```powershell
cd C:\xampp\htdocs\aula-virtual-docker
docker compose -f docker-compose.local.yml up -d --build
```

URLs:

- Portal: `http://127.0.0.1:8000`
- API health: `http://127.0.0.1:8011/up`

Ver logs:

```powershell
docker compose -f docker-compose.local.yml logs -f portal
docker compose -f docker-compose.local.yml logs -f api-servicios
docker compose -f docker-compose.local.yml logs -f portal-nginx
```

Entrar al contenedor del portal:

```powershell
docker compose -f docker-compose.local.yml exec portal sh
```

Entrar al contenedor del API:

```powershell
docker compose -f docker-compose.local.yml exec api-servicios sh
```

Detener servicios:

```powershell
docker compose -f docker-compose.local.yml down
```

> Nota: si estas parado dentro de `aula-virtual`, Docker no encontrara el compose. Usa siempre la raiz `C:\xampp\htdocs\aula-virtual-docker` o especifica `-f ..\docker-compose.local.yml`.

## Instalacion Manual Del Portal

Desde `aula-virtual`:

```powershell
composer install
copy .env.example .env
php artisan key:generate
npm install
npm run build
php artisan serve --port=8000
```

En PowerShell, si `npm` falla por politicas de ejecucion, usa:

```powershell
npm.cmd run build
```

## Comandos Frecuentes

Portal:

```powershell
php artisan view:clear
php artisan config:clear
php artisan route:clear
php artisan cache:clear
npm.cmd run build
php artisan test
```

Optimizacion para entorno tipo produccion:

```powershell
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

API Servicios:

```powershell
php artisan migrate
php artisan test
```

Desde Docker:

```powershell
docker compose -f docker-compose.local.yml exec portal php artisan test
docker compose -f docker-compose.local.yml exec portal npm run build
docker compose -f docker-compose.local.yml exec api-servicios php artisan test
```

## Flujo De Desarrollo

1. Levantar Docker Desktop.
2. Verificar que MySQL local este activo y que exista `smartdata`.
3. Ejecutar `docker compose -f docker-compose.local.yml up -d --build`.
4. Verificar `http://127.0.0.1:8011/up`.
5. Abrir `http://127.0.0.1:8000/login`.
6. Hacer cambios en Blade, CSS o JS.
7. Ejecutar `npm.cmd run build` para regenerar assets.
8. Limpiar vistas si corresponde: `php artisan view:clear`.

## Rendimiento

El entorno local esta preparado para acercarse a produccion:

- PHP-FPM + Nginx.
- Redis para cache, sesiones y colas.
- Cache headers para assets versionados de Vite.
- Gzip en Nginx.
- Logs exitosos reducidos.
- Timeouts cortos hacia API Servicios.

Recomendaciones:

- Mantener `APP_DEBUG=false` al medir rendimiento.
- Usar `LOG_LEVEL=warning`.
- Evitar `php artisan serve` para mediciones reales.
- Probar Lighthouse en incognito y sin extensiones.
- Hacer hard refresh despues de cambios de assets.

## Integraciones

### Google Drive

Se usa para grabaciones, materiales y archivos asociados. Configurar:

```env
GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH=storage/google/service-account.json
GOOGLE_DRIVE_LMS_FOLDER_ID=
```

El service account debe tener permiso sobre la carpeta de Drive.

### Zoom

La integracion de reuniones y asistencia esta preparada mediante flags:

```env
MEETINGS_INTEGRATION_ENABLED=false
ATTENDANCE_ENABLED=false
ATTENDANCE_ZOOM_SYNC_ENABLED=false
ZOOM_ACCOUNT_ID=
ZOOM_SERVER_CLIENT_ID=
ZOOM_SERVER_CLIENT_SECRET=
ZOOM_WEBHOOK_SECRET=
```

### SGA / Diplomas

SGA sigue siendo la fuente de generacion de diplomas. Aula Virtual consulta y sincroniza certificados desde las tablas existentes.

Variables:

```env
SGA_PUBLIC_BASE_URL=http://localhost/sga-web
CERTIFICADO_PUBLIC_BASE_URL=http://localhost:8011/Certificados
```

## Troubleshooting

### `no configuration file provided: not found`

Estas ejecutando `docker compose` desde una carpeta que no contiene el compose.

Solucion:

```powershell
cd C:\xampp\htdocs\aula-virtual-docker
docker compose -f docker-compose.local.yml up -d --build
```

### `502 Bad Gateway`

Nginx no puede conectar con PHP-FPM o el contenedor no termino de iniciar.

Revisar:

```powershell
docker compose -f docker-compose.local.yml ps
docker compose -f docker-compose.local.yml logs -f portal
docker compose -f docker-compose.local.yml logs -f portal-nginx
```

### Login rechaza credenciales correctas

Revisar:

- API activo: `http://127.0.0.1:8011/up`.
- Token interno igual en portal y API.
- Variables de conexion a `smartdata`.
- Logs de `api-servicios`.

### La pagina carga sin estilos

Regenerar assets:

```powershell
cd C:\xampp\htdocs\aula-virtual-docker\aula-virtual
npm.cmd run build
php artisan view:clear
```

Luego hacer hard refresh en el navegador.

### API Servicios error

Revisar el correlation id en logs:

```powershell
docker compose -f docker-compose.local.yml logs -f api-servicios
docker compose -f docker-compose.local.yml logs -f portal
```

### Subida a Google Drive falla

Validar:

- Service account correcto.
- Permiso de editor sobre la carpeta.
- `GOOGLE_DRIVE_LMS_FOLDER_ID`.
- Limites de PHP/Nginx si el chunk supera el maximo permitido.

## Documentacion Adicional

- `docs/architecture.md`
- `docs/authentication.md`
- `docs/security.md`

## Notas De Produccion

Para VPS se recomienda:

- Nginx como proxy publico.
- Contenedores separados para portal, API, Redis, workers y scheduler.
- `APP_DEBUG=false`.
- `LOG_LEVEL=warning`.
- `composer install --no-dev --optimize-autoloader`.
- `php artisan config:cache`, `route:cache` y `view:cache`.
- Backups de base de datos y `storage`.
- Variables sensibles solo por `.env` o secretos del entorno.
