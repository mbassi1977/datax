# Arquitectura de Módulos Nativos Base para HRX

> Análisis recibido de especialistas externos (2026-08-10). Complementa el
> "Documento maestro.docx" — no lo reemplaza. Se guarda aquí como referencia
> de arquitectura de largo plazo, no como orden de construcción.

Si se piensa en HRX como una plataforma empresarial modular, conviene separar
claramente los **módulos nativos base** (todo el sistema los necesita,
independientemente de qué módulos funcionales se activen) de los
**módulos funcionales** (RRHH, auditorías, encuestas, finanzas, inclusión,
ChileValora, asistencia, etc.).

## 1. Identidad, Usuarios y Accesos

Usuarios, roles, permisos granulares, perfiles, recuperación de contraseña,
MFA opcional, sesiones activas, delegaciones y suplencias. Acceso por
empresa, proyecto, centro de costo, área o unidad organizacional.

## 2. Organizaciones y Estructura

Evita que cada módulo funcional cree su propia estructura organizacional:
holding, empresas, sucursales, centros de costo, áreas, gerencias,
proyectos, equipos, cargos, ubicaciones.

## 3. Personas

Separar Personas de Usuarios: una persona puede existir en HRX sin tener
acceso al sistema. Datos principales: RUT/identificador único, nombre,
contacto, empresa, cargo, área, supervisor, estado. Tipos extendibles:
trabajadores, candidatos, evaluadores, clientes, proveedores, participantes,
prestadores, contactos externos.

## 4. Catálogos Maestros

Consistencia en toda la plataforma: regiones, comunas, nacionalidades, tipos
de documento, estados, categorías, tipos de contrato, cargos, bancos,
monedas, motivos, tipos de solicitud. Idealmente con un **constructor de
catálogos** para que el administrador cree catálogos nuevos sin programación.

## 5. Documentos y Archivos

Repositorio transversal, no uno por módulo. Archivos, carpetas, versiones,
metadatos, etiquetas, vencimientos, asociaciones, permisos, historial.
Asociable a personas, empresas, proyectos, tickets, evaluaciones,
solicitudes, cursos, procesos, etc. Almacenamiento sugerido: MinIO / S3 /
compatible con object storage.

## 6. Workflow / Motor de Procesos

Completamente nativo. Ejemplo de flujo: `Creado → En revisión → Aprobado →
Rechazado → Cerrado`. Configurable: estados, transiciones, responsables,
aprobaciones, rechazos, SLA, escalamientos, condiciones, acciones
automáticas, reglas de negocio. Reutilizable por vacaciones, fondos por
rendir, certificaciones, denuncias, tickets, solicitudes, evaluaciones,
procesos administrativos.

## 7. Tareas y Seguimiento

Capa transversal: tarea, responsable, fecha límite, prioridad, estado,
comentarios, evidencias, checklist, tareas recurrentes. Una tarea debería
poder originarse desde cualquier módulo.

## 8. Notificaciones

Servicio centralizado único. Canales: notificaciones internas, email, push,
WhatsApp, recordatorios, alertas, escalamientos. Plantillas configurables,
variables dinámicas, reglas de envío, preferencias de usuario.

## 9. Centro de Comunicaciones

Distinto del sistema de notificaciones: mensajes, comunicados, anuncios,
destinatarios masivos, grupos, confirmación de lectura, adjuntos,
segmentación de destinatarios.

## 10. Calendario y Agenda

Nativo porque numerosos módulos lo usarán: eventos, citas, hitos,
vencimientos, reuniones, disponibilidad, agendas compartidas. Integraciones
posibles: Google Calendar, Microsoft Outlook, Teams, Google Meet.

## 11. Comentarios, Notas y Actividad

Pequeño pero poderoso: cualquier entidad debería poder incorporar
comentarios + adjuntos + menciones + historial (persona, evaluación,
ticket, factura, denuncia, proyecto, solicitud).

## 12. Motor de Formularios

Incorporarlo desde el principio. Tipos de campo: texto, número, fecha,
lista, selección única/múltiple, archivo, firma, foto, ubicación GPS,
campos condicionales. Capacidades: validaciones, dependencias, campos
obligatorios, reglas condicionales, secciones, páginas, evidencias. Permite
crear sin desarrollo adicional: encuestas, inspecciones, auditorías, fichas,
solicitudes, formularios administrativos.

## 13. Auditoría y Trazabilidad

No confundir con un módulo funcional de auditoría en terreno — este es el
log transversal del sistema. Debe registrar quién, qué acción, fecha/hora,
desde dónde, valor anterior, valor nuevo, IP, dispositivo, inicios de
sesión, exportaciones, eliminaciones, cambios de permisos, cambios de
configuración. Indispensable en un sistema empresarial.

## 14. Configuración

Módulo de administración real: parámetros globales, parámetros por empresa,
colores, logos, idioma, zona horaria, formatos, reglas, campos
personalizados, funcionalidades activas, límites, preferencias del sistema.

## 15. Integraciones

Capa central en vez de conexiones programadas dentro de cada módulo: API,
webhooks, Google, Microsoft 365, SENCE, ChileValora, bancos, sistemas
externos, plataformas de capacitación, mensajería, importaciones Excel/CSV.

## 16. Importación y Exportación

Servicio nativo: Excel, CSV, PDF, importación masiva, validación previa,
errores por fila, plantillas descargables, exportaciones programadas,
historial de importaciones.

## 17. Reportería y BI Base

Capa transversal antes de dashboards particulares por módulo: filtros, KPI,
tablas, gráficos, exportación, vistas guardadas, segmentación por empresa/
proyecto/fecha/área/centro de costo, permisos sobre información.

## 18. Asistente IA

Diseñarlo como servicio nativo desde el principio, aunque parta con pocas
funciones: buscar información, explicar datos, generar resúmenes, crear
tareas, consultar documentos, resumir comunicaciones, preparar reportes,
consultar indicadores, ayudar a navegar la plataforma. Cada módulo podría
registrar después sus propias capacidades ante el asistente.

---

## Arquitectura recomendada (3 niveles)

**Nivel 1 — Núcleo HRX**: Identidad + Organizaciones + Personas + Catálogos
+ Seguridad + Configuración.

**Nivel 2 — Servicios Nativos**: Documentos + Workflow + Formularios +
Tareas + Notificaciones + Calendario + Comunicaciones + Auditoría +
Integraciones + BI + IA.

**Nivel 3 — Módulos de Negocio**: RRHH, Asistencia, Encuestas, Auditorías
PDV, ChileValora, Inclusión, Denuncias, Finanzas, Reclutamiento,
Agendamiento Médico, Marketing, Tickets, otros.

**Ventaja**: los módulos funcionales quedan livianos — un módulo de
Vacaciones no necesita desarrollar usuarios, trabajadores, documentos,
aprobaciones, correos, calendario, historial, notificaciones ni tareas
desde cero; solo implementa la lógica específica de vacaciones y consume
los servicios nativos.

**Concepto de Entidad Universal**: cada entidad (persona, trabajador,
proyecto, punto de venta, evaluación, ticket, factura, curso, solicitud,
denuncia, proceso) tendría un identificador interno común, permitiendo
asociar transversalmente documentos, comentarios, tareas, formularios,
eventos, notificaciones, historial y auditoría.

**MVP recomendado del núcleo**: usuarios y permisos, organizaciones,
personas, catálogos, documentos, workflow, formularios, notificaciones,
auditoría, configuración — con estos diez componentes ya es posible
construir gran parte de los módulos futuros sin rehacer la arquitectura
base.

---

## Estado real vs. este mapa (2026-08-10)

Ya construido, y calza con este esquema sin haber sido planeado así:

- **Personas separadas de Usuarios** (`master.people` vs `security.users`).
- **Catálogos Maestros** (`core.catalogs`, tenant_id nulo = global; ya usado
  para región/comuna/nacionalidad/cargo/industria; cargo admite entradas
  propias por organización).
- **Documentos transversales** (`core.attachments`, polimórfico
  `attachable_type`/`attachable_id`, asociable a organización/persona/
  usuario/perfil).
- **Auditoría** (`audit.audit_events`, trait `Auditable`, quién/qué/cuándo/
  valor anterior-nuevo).
- **Importación** (`integrations.import_templates`/`import_jobs`,
  `ImportTarget`/`ImportTargetRegistry` — motor genérico, hoy conectado
  solo a Cargos).
- **Configuración** parcial (perfiles/permisos por tenant; parámetros por
  organización/límites/features deliberadamente pendientes hasta que el
  módulo de Diagnóstico les dé sentido real).

Sin construir todavía (Nivel 2): Workflow, Motor de Formularios,
Notificaciones, Tareas, Calendario, Centro de Comunicaciones, BI, Asistente
IA.

**Criterio acordado**: no construir estos servicios de forma especulativa.
Se construyen la primera vez que un módulo de negocio real (empezando por
el Diagnóstico de HRX Inclusión) los necesite de verdad — así el diseño se
valida contra un caso real en vez de adivinar requisitos en el vacío. Este
documento es el mapa de hacia dónde converge la arquitectura, no una lista
de tareas para ejecutar ahora.
