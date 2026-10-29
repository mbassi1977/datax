# HRX — Plataforma de Gestión Organizacional

Referencias: `docs/documento-maestro.docx` (18 capítulos) y `docs/arquitectura-modulos-nativos.md`.

## Stack oficial (cap. 7)
Laravel (API REST `/api/v1`, monolito modular) · PostgreSQL · Redis · MinIO · Docker.
Web: React + TypeScript (después). Móvil: React Native + SQLite offline (después).
Descartado en v1: Kubernetes, microservicios, Kafka, Elasticsearch, MongoDB.

## Reglas del producto
- Una entidad, un registro: Organización, Persona, Servicio, Proyecto, Diagnóstico.
- Personas separadas de Usuarios (una persona puede tener varios usuarios).
- Multiempresa desde el origen: tenant_id + organization_id en toda entidad de negocio.
- UUID como identificador. Campos estándar: id, tenant_id, organization_id, created_at,
  updated_at, created_by, updated_by, deleted_at.
- Acceso: Usuario → Perfil activo → Permiso (`personas.ver`) → Alcance
  (global/holding/organización/sucursal/área/proyecto). Mínimo privilegio.
- Auditoría por defecto (quién, qué, cuándo, valor anterior/nuevo).
- PostgreSQL con esquemas por dominio: core, security, master, audit, ...
- No construir servicios de Nivel 2 (workflow, formularios, etc.) hasta que un módulo real los necesite.

## Orden de construcción
1. Núcleo: tenants, holdings, organizaciones, sucursales, áreas, centros de costo, proyectos.
2. Seguridad: usuarios, perfiles, permisos, alcances, sesiones.
3. Personas, catálogos, auditoría, configuración.
4. Módulos de negocio, empezando por Diagnóstico de HRX Inclusión.
