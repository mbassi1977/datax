// Definición declarativa de las pantallas de gestión.
export type Field = {
  key: string; label: string;
  type?: 'text' | 'email' | 'date' | 'password' | 'textarea' | 'select' | 'ref' | 'tags';
  options?: [string, string][];                 // select
  ref?: { path: string; label: (r: any) => string }; // ref: lista traída de la API
  required?: boolean; createOnly?: boolean; help?: string;
};

export type NavItem = { id: string; title: string; perm: string; group: string };

export type Resource = NavItem & {
  path: string;
  columns: { key: string; label: string; render?: (r: any, refs: Record<string, any[]>) => string }[];
  fields: Field[];
  profilesPanel?: boolean;
};

const org: Field = { key: 'organization_id', label: 'Organización', type: 'ref', required: true, ref: { path: 'organizations', label: (o) => o.trade_name || o.legal_name } };
const orgOptional: Field = { ...org, required: false };
const status: Field = { key: 'status', label: 'Estado', type: 'select', options: [['active', 'Activo'], ['inactive', 'Inactivo']] };
const orgName = (r: any, refs: Record<string, any[]>) => (refs.organizations ?? []).find((o) => o.id === r.organization_id)?.legal_name ?? '—';
const roles = ['trabajador', 'consultor', 'relator', 'evaluador', 'participante', 'cliente', 'medico', 'tutor', 'administrador'];

export const resources: Resource[] = [
  {
    id: 'organizations', title: 'Organizaciones', path: 'organizations', perm: 'organizaciones', group: 'Estructura',
    columns: [{ key: 'legal_name', label: 'Razón social' }, { key: 'rut', label: 'RUT' }, { key: 'type', label: 'Tipo' }, { key: 'status', label: 'Estado' }],
    fields: [
      { key: 'legal_name', label: 'Razón social', required: true }, { key: 'trade_name', label: 'Nombre de fantasía' }, { key: 'rut', label: 'RUT' },
      { key: 'type', label: 'Tipo', type: 'select', options: [['company', 'Empresa'], ['client', 'Cliente'], ['provider', 'Proveedor'], ['otec', 'OTEC'], ['evaluator', 'Centro evaluador'], ['public', 'Institución pública'], ['social', 'Organización social']] },
      { key: 'email', label: 'Correo', type: 'email' }, { key: 'phone', label: 'Teléfono' }, { key: 'region', label: 'Región' }, { key: 'commune', label: 'Comuna' },
      { key: 'address', label: 'Dirección' }, { key: 'industry', label: 'Industria' },
    ],
  },
  {
    id: 'branches', title: 'Sucursales', path: 'branches', perm: 'sucursales', group: 'Estructura',
    columns: [{ key: 'name', label: 'Nombre' }, { key: 'organization_id', label: 'Organización', render: orgName }, { key: 'commune', label: 'Comuna' }],
    fields: [org, { key: 'name', label: 'Nombre', required: true }, { key: 'address', label: 'Dirección' }, { key: 'commune', label: 'Comuna' }, status],
  },
  {
    id: 'areas', title: 'Áreas', path: 'areas', perm: 'areas', group: 'Estructura',
    columns: [{ key: 'name', label: 'Nombre' }, { key: 'organization_id', label: 'Organización', render: orgName }],
    fields: [org, { key: 'name', label: 'Nombre', required: true },
      { key: 'parent_id', label: 'Área superior', type: 'ref', ref: { path: 'areas', label: (a) => a.name } }, status],
  },
  {
    id: 'cost-centers', title: 'Centros de costo', path: 'cost-centers', perm: 'centros_costo', group: 'Estructura',
    columns: [{ key: 'code', label: 'Código' }, { key: 'name', label: 'Nombre' }, { key: 'organization_id', label: 'Organización', render: orgName }],
    fields: [org, { key: 'code', label: 'Código', required: true }, { key: 'name', label: 'Nombre', required: true },
      { key: 'area_id', label: 'Área', type: 'ref', ref: { path: 'areas', label: (a) => a.name } }, status],
  },
  {
    id: 'people', title: 'Personas', path: 'people', perm: 'personas', group: 'Personas y proyectos',
    columns: [
      { key: 'first_name', label: 'Nombre', render: (r) => `${r.first_name} ${r.last_name ?? ''}`.trim() }, { key: 'rut', label: 'RUT' },
      { key: 'job_title', label: 'Cargo' }, { key: 'roles', label: 'Roles', render: (r) => (r.roles ?? []).join(', ') }, { key: 'organization_id', label: 'Organización', render: orgName },
    ],
    fields: [
      { ...orgOptional, help: 'Opcional: una persona puede existir sin empresa (requiere alcance global).' },
      { key: 'first_name', label: 'Nombres', required: true }, { key: 'last_name', label: 'Apellidos' }, { key: 'rut', label: 'RUT' },
      { key: 'email', label: 'Correo', type: 'email' }, { key: 'phone', label: 'Teléfono' }, { key: 'birth_date', label: 'Fecha de nacimiento', type: 'date' },
      { key: 'job_title', label: 'Cargo' }, { key: 'nationality', label: 'Nacionalidad' },
      { key: 'roles', label: 'Roles', type: 'tags', options: roles.map((r) => [r, r]) },
      { key: 'area_id', label: 'Área', type: 'ref', ref: { path: 'areas', label: (a) => a.name } },
      { key: 'supervisor_id', label: 'Supervisor', type: 'ref', ref: { path: 'people', label: (p) => `${p.first_name} ${p.last_name ?? ''}` } },
      status,
    ],
  },
  {
    id: 'projects', title: 'Proyectos', path: 'projects', perm: 'proyectos', group: 'Personas y proyectos',
    columns: [{ key: 'code', label: 'Código' }, { key: 'name', label: 'Nombre' }, { key: 'organization_id', label: 'Organización', render: orgName }, { key: 'status', label: 'Estado' }, { key: 'ends_on', label: 'Término' }],
    fields: [
      org, { key: 'name', label: 'Nombre', required: true }, { key: 'code', label: 'Código' }, { key: 'description', label: 'Descripción', type: 'textarea' },
      { key: 'cost_center_id', label: 'Centro de costo', type: 'ref', ref: { path: 'cost-centers', label: (c) => `${c.code} · ${c.name}` } },
      { key: 'manager_id', label: 'Responsable', type: 'ref', ref: { path: 'people', label: (p) => `${p.first_name} ${p.last_name ?? ''}` } },
      { key: 'starts_on', label: 'Inicio', type: 'date' }, { key: 'ends_on', label: 'Término', type: 'date' },
      { key: 'status', label: 'Estado', type: 'select', options: [['planned', 'Planificado'], ['active', 'Activo'], ['paused', 'Pausado'], ['closed', 'Cerrado']] },
    ],
  },
  {
    id: 'users', title: 'Usuarios', path: 'users', perm: 'usuarios', group: 'Administración', profilesPanel: true,
    columns: [{ key: 'email', label: 'Correo' }, { key: 'organization_id', label: 'Organización', render: orgName }, { key: 'status', label: 'Estado' }, { key: 'last_login_at', label: 'Último acceso', render: (r) => r.last_login_at?.slice(0, 16).replace('T', ' ') ?? '—' }],
    fields: [
      { ...orgOptional, label: 'Organización principal' }, { key: 'email', label: 'Correo', type: 'email', required: true },
      { key: 'password', label: 'Contraseña', type: 'password', help: 'Mínimo 10 caracteres, con letras y números. En edición, déjela vacía para no cambiarla.' },
      { key: 'status', label: 'Estado', type: 'select', options: [['active', 'Activo'], ['suspended', 'Suspendido'], ['blocked', 'Bloqueado']] },
    ],
  },
];

// Orden de los grupos del menú (acordeón).
export const groupOrder = ['Estructura', 'Personas y proyectos', 'Datos', 'Administración'];

// Pantallas con interfaz propia (no son un CRUD genérico).
export const pages: NavItem[] = [{ id: 'imports', title: 'Importaciones', perm: 'importaciones', group: 'Datos' }];
