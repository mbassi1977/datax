# datax — HRX

Plataforma de gestión organizacional. Ver `CLAUDE.md` y `docs/`.

## Desarrollo
```bash
docker compose up -d                      # PostgreSQL + Redis
cd app && composer install && cp .env.example .env && php artisan key:generate
# .env: DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=hrx DB_USERNAME=hrx DB_PASSWORD=hrx_dev
php artisan migrate --seed
php artisan db:seed --class=DemoSeeder    # tenant "demo", admin@demo.cl (solo desarrollo)
php artisan serve
php artisan test                          # requiere una base `hrx_test` en el mismo PostgreSQL
```

## API v1
| Método | Ruta | Permiso |
|---|---|---|
| POST | `/api/v1/auth/login` (`tenant`, `email`, `password`) | — |
| POST | `/api/v1/auth/logout` | token |
| GET | `/api/v1/me` | token |
| GET/POST/PUT/DELETE | `/api/v1/organizations[/{id}]` | `organizaciones.ver/crear/editar/eliminar` |

Autenticación: `Authorization: Bearer <token>`. Perfil activo opcional: header `X-Profile: <código>`.
Los resultados se filtran por tenant y por alcance del perfil (global, holding, organización, sucursal, área, proyecto).
Crear organizaciones exige alcance global. Todo cambio queda en `audit.audit_events`.
