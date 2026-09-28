<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Demo en VPS con Docker Compose

Rama: `deploy/demo`. Ejecutar desde `utrack-fly` con Docker Engine y Compose v2.
El motor ASIO de `music-engine` se ejecuta en Windows y se conecta al VPS.

```bash
cp .env.docker.example .env
docker compose build app
docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show
```

El demo usa SQLite, sin usuario ni contrasena de base de datos.
Guardar la clave generada en `APP_KEY` y conservarla
entre despliegues. Configurar `APP_URL`, `ENGINE_MEDIA_URL`, las credenciales
`REVERB_APP_*` y `ENGINE_WEBSOCKET_URL`; su ultimo segmento debe coincidir
con `REVERB_APP_KEY`. Las credenciales de ejemplo deben reemplazarse.
`REVERB_HOST=reverb` es la direccion interna para publicar eventos.

El puerto HTTP solo escucha en `127.0.0.1:8088` del VPS. Si ya existe un
`.env` de despliegue, actualizar `APP_PORT=8088` tambien en ese archivo.
Configurar el proxy
HTTPS del host para el dominio hacia ese puerto, con soporte WebSocket en
`/app/`, timeout de 3600 segundos y limite de subida de 110 MB. El proxy debe
sobrescribir `Host` con el dominio publico y `X-Forwarded-Proto` con `https`.
No exponer directamente el puerto de la app: Nginx confia en ese encabezado
del proxy local para generar las URLs HTTPS y validar las firmas del audio.
Redis y Reverb no publican puertos en el host.

```bash
docker compose config --quiet
docker compose up -d --build
docker compose ps
docker compose logs --tail=100 app queue reverb
docker compose exec app php artisan make:filament-user
```

Solo `app` ejecuta las migraciones al iniciar; las colas y Reverb esperan su
healthcheck. No se cargan datos de demostracion ni usuarios automaticamente.
Los archivos y SQLite comparten el volumen persistente `app-storage`.
La base se crea en `storage/app/private/database.sqlite`, fuera del directorio
publico. Redis conserva su propio volumen. Respaldar la base de datos, los archivos y
`.env` antes de actualizar; `docker compose down -v` elimina los volumenes.
Si se migra desde el Compose anterior, respaldar tambien
`app-public-storage` y copiar su contenido al directorio `public` de
`app-storage`, ya que el volumen separado anterior deja de montarse.

Para actualizar, traer los cambios de la rama y repetir
`docker compose up -d --build`. Verificar el panel, la subida de audio y la
conexion del motor tras cada despliegue. La construccion necesita acceso a
los registros de imagenes, Composer, npm y al proveedor de fuentes de Vite.

### Cambiar el demo de MySQL a SQLite

Actualizar los archivos del despliegue y reemplazar el bloque `DB_*` del
`.env` existente con el bloque SQLite de `.env.docker.example`. Eliminar
`DB_URL` si existe. Conservar `APP_KEY` y el resto de los secretos.
La base SQLite empieza vacia: este cambio no importa datos de MySQL.

```bash
docker stop utracksound-mysql-1
docker compose config --quiet
docker compose up -d --build
docker compose ps
docker compose logs --tail=100 app
```

El contenedor anterior puede aparecer como huerfano; su volumen permanece
intacto. No ejecutar `down -v` ni eliminar volumenes. Crear el usuario del
panel con `docker compose exec app php artisan make:filament-user`.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
