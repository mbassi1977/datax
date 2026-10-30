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

## Interfaz web
Código en `web/` (React + TypeScript + Vite). Se compila a `app/public/ui` (versionado, para no exigir Node en el servidor)
y Laravel la sirve en `/`. Para modificarla: `cd web && npm install && npm run build` (o `npm run dev` con la API en :8000).

## API v1
| Método | Ruta | Permiso |
|---|---|---|
| POST | `/api/v1/auth/login` (`tenant`, `email`, `password`) | — |
| POST | `/api/v1/auth/logout` | token |
| GET | `/api/v1/me` | token |
| GET/POST/PUT/DELETE | `/api/v1/organizations[/{id}]` | `organizaciones.ver/crear/editar/eliminar` |
| GET/POST/PUT/DELETE | `/api/v1/branches[/{id}]` | `sucursales.*` |
| GET/POST/PUT/DELETE | `/api/v1/areas[/{id}]` | `areas.*` |
| GET/POST/PUT/DELETE | `/api/v1/cost-centers[/{id}]` | `centros_costo.*` |
| GET/POST/PUT/DELETE | `/api/v1/people[/{id}]` | `personas.*` |
| GET/POST/PUT/DELETE | `/api/v1/projects[/{id}]` | `proyectos.*` |
| GET/POST/PUT/DELETE | `/api/v1/users[/{id}]` | `usuarios.*` |
| GET | `/api/v1/profiles` | `perfiles.ver` |
| GET/POST | `/api/v1/users/{id}/profiles` | `usuarios.ver` / `usuarios.editar` |
| DELETE | `/api/v1/users/{id}/profiles/{asignación}` | `usuarios.editar` |
| POST | `/api/v1/me/password` | token |

### Perfiles, avatares y galerías
- `GET /permissions`, `POST|PUT|DELETE /profiles[/{id}]` (`perfiles.*`): perfiles propios del tenant; los de sistema son de solo lectura; solo se
  pueden incluir permisos que quien edita ya posee; un perfil asignado a usuarios no se elimina.
- Mi avatar (cualquier usuario): `GET|PUT|POST|DELETE /me/avatar` — elegir de las galerías de su empresa (`avatar_id`), subir foto
  (JPG/PNG/WebP ≤ 4 MB; se recorta a 256×256 WebP y se eliminan los metadatos) o quitarlo. `avatar_url` viene en `/me` y en `/users`.
- Galerías (`avatares.*`, escribir exige alcance global): `/avatar-collections` (CRUD, `is_global` u `organization_ids` para asignarlas a
  empresas), `POST /avatar-collections/{id}/avatars` (`files[]`, JPG/PNG/WebP/SVG ≤ 1 MB), `PUT|DELETE /avatars/{id}`.
  Las colecciones del sistema («Clásicos») no se editan. Las imágenes viven en disco privado y se sirven con URL firmada
  (`/api/v1/media/...`) con CSP `sandbox`, de modo que un SVG no puede ejecutar código. Requiere `php-gd`.

### Importador universal (Excel / CSV)
`.xlsx` o `.csv` (`;` `,` o tabulador; UTF-8 o Windows-1252), máx. 5 MB y 5.000 filas. Flujo en la web: *Datos → Importaciones*.
Por API: `GET imports/targets` → `POST imports` (multipart `target`, `file`, `template_id?`; devuelve columnas, vista previa y mapeo sugerido)
→ `POST imports/{id}/run` (`mode: simulate|commit`, `mapping` campo→índice de columna, `update_existing`, `on_error: skip|abort`,
`organization_id?`, `save_template_as?`) → `GET imports[/{id}]`. Destinos: `organizations` y `people`; los registros existentes se
detectan por RUT. Respeta permisos y alcance: cada fila se valida contra lo que el usuario puede crear o editar.
**Nombres:** Personas guarda `first_name` (nombres), `last_name` (apellido paterno) y `second_last_name` (materno). Si el archivo trae
una sola columna de nombre completo, se separa con `NameSplitter` (coma, orden detectado con listas de nombres y apellidos
frecuentes, partículas como «De la»). Cada fila queda con confianza alta (silenciosa), media (se carga y se avisa) o baja
(error hasta corregirla en la tabla de revisión; la corrección viaja en `name_overrides` por número de fila). `name_format`:
`auto | names_first | surnames_first | comma`.
Los archivos subidos se borran al terminar; `php artisan imports:prune` (diario) elimina los abandonados. Requiere `php-zip` para `.xlsx`.

Asignar perfil: `{profile, scope_type, scope_id, valid_from?, valid_until?}`. Solo se puede asignar un perfil cuyos permisos
y alcance ya se poseen (sin escalamiento), y `superadmin` solo lo asigna un superadmin. Suspender un usuario o cambiarle la
clave cierra sus sesiones.

Los listados aceptan `?q=` (búsqueda) y `?organization_id=`. Los registros sin organización (personas) solo son visibles con alcance global.

Autenticación: `Authorization: Bearer <token>`. Perfil activo opcional: header `X-Profile: <código>`.
Los resultados se filtran por tenant y por alcance del perfil (global, holding, organización, sucursal, área, proyecto).
Crear organizaciones exige alcance global. Todo cambio queda en `audit.audit_events`.
