import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me } from './api';
import { can, Modal } from './ResourcePage';

type Profile = { id: string; code: string; name: string; is_system: boolean; users_count: number; permissions: string[] };

const resourceLabels: Record<string, string> = {
  organizaciones: 'Organizaciones', sucursales: 'Sucursales', areas: 'Áreas', centros_costo: 'Centros de costo', personas: 'Personas',
  proyectos: 'Proyectos', usuarios: 'Usuarios', perfiles: 'Perfiles', catalogos: 'Catálogos', auditoria: 'Auditoría',
  importaciones: 'Importaciones', avatares: 'Galerías de avatares', holdings: 'Holdings', configuracion: 'Configuración',
};
const actions: [string, string][] = [['ver', 'Ver'], ['crear', 'Crear'], ['editar', 'Editar'], ['eliminar', 'Eliminar']];

export function ProfilesPage({ me }: { me: Me }) {
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [catalog, setCatalog] = useState<string[]>([]);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState<Profile | 'new' | null>(null);

  const load = useCallback(() => { api<Profile[]>('GET', 'profiles').then(setProfiles).catch((e) => setError(e.message)); }, []);
  useEffect(() => { load(); api<string[]>('GET', 'permissions').then(setCatalog).catch(() => {}); }, [load]);

  const remove = async (p: Profile) => {
    if (!confirm(`¿Eliminar el perfil «${p.name}»?`)) return;
    try { await api('DELETE', `profiles/${p.id}`); load(); } catch (e) { setError((e as Error).message); }
  };

  return (
    <section>
      <header className="bar">
        <h1>Perfiles</h1>
        {can(me, 'perfiles', 'crear') && <button className="primary" onClick={() => setEditing('new')}>+ Nuevo perfil</button>}
      </header>
      <p className="muted">Un perfil agrupa permisos («qué puede hacer»). El alcance («dónde») se define al asignarlo a cada usuario. Los perfiles del sistema no se pueden modificar; cree uno propio para adaptarlos.</p>
      {error && <p className="error">{error}</p>}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Perfil</th><th>Tipo</th><th>Permisos</th><th>Usuarios</th><th /></tr></thead>
          <tbody>
            {profiles.map((p) => (
              <tr key={p.id}>
                <td><strong>{p.name}</strong><br /><small className="muted">{p.code}</small></td>
                <td><span className={`badge ${p.is_system ? '' : 'completed'}`}>{p.is_system ? 'Sistema' : 'Propio'}</span></td>
                <td>{p.permissions.length}</td><td>{p.users_count}</td>
                <td className="actions">
                  <button onClick={() => setEditing(p)}>{p.is_system || !can(me, 'perfiles', 'editar') ? 'Ver permisos' : 'Editar'}</button>
                  {!p.is_system && can(me, 'perfiles', 'eliminar') && <button className="danger" onClick={() => remove(p)}>Eliminar</button>}
                </td>
              </tr>
            ))}
            {profiles.length === 0 && <tr><td colSpan={5} className="muted">Sin perfiles.</td></tr>}
          </tbody>
        </table>
      </div>
      {editing && <ProfileForm me={me} profile={editing === 'new' ? null : editing} catalog={catalog} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
    </section>
  );
}

function ProfileForm({ me, profile, catalog, onClose, onSaved }: { me: Me; profile: Profile | null; catalog: string[]; onClose: () => void; onSaved: () => void }) {
  const readOnly = profile ? profile.is_system || !can(me, 'perfiles', 'editar') : false;
  const [name, setName] = useState(profile?.name ?? '');
  const [perms, setPerms] = useState<Set<string>>(new Set(profile?.permissions ?? []));
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const resources = [...new Set(catalog.map((c) => c.split('.')[0]))].sort((a, b) => (resourceLabels[a] ?? a).localeCompare(resourceLabels[b] ?? b, 'es'));
  const toggle = (code: string) => setPerms((s) => { const n = new Set(s); n.has(code) ? n.delete(code) : n.add(code); return n; });
  const owned = (code: string) => me.is_superadmin || me.permissions.includes(code); // solo se pueden otorgar los que uno posee

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setError('');
    try {
      const body = { name, permissions: [...perms] };
      await (profile ? api('PUT', `profiles/${profile.id}`, body) : api('POST', 'profiles', body));
      onSaved();
    } catch (e2) {
      const ae = e2 as ApiError;
      setError(Object.values(ae.errors).flat().join(' ') || ae.message);
    } finally { setBusy(false); }
  };

  return (
    <Modal title={profile ? (readOnly ? `Permisos · ${profile.name}` : `Editar · ${profile.name}`) : 'Nuevo perfil'} onClose={onClose}>
      <form onSubmit={submit} className="form one">
        {!readOnly && <label><span>Nombre del perfil *</span><input value={name} onChange={(e) => setName(e.target.value)} required maxLength={100} /></label>}
        <div className="table-wrap">
          <table className="matrix">
            <thead><tr><th>Módulo</th>{actions.map(([, l]) => <th key={l}>{l}</th>)}</tr></thead>
            <tbody>
              {resources.map((r) => (
                <tr key={r}>
                  <td>{resourceLabels[r] ?? r}</td>
                  {actions.map(([a]) => {
                    const code = `${r}.${a}`;
                    return (
                      <td key={a} className="center-cell">
                        {catalog.includes(code) && (
                          <input type="checkbox" aria-label={`${resourceLabels[r] ?? r}: ${a}`} checked={perms.has(code)} disabled={readOnly || (!perms.has(code) && !owned(code))}
                            title={!readOnly && !owned(code) ? 'Usted no posee este permiso' : undefined} onChange={() => toggle(code)} />
                        )}
                      </td>
                    );
                  })}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {!readOnly && <small className="muted">Solo puede incluir permisos que usted mismo posee.</small>}
        {error && <p className="error">{error}</p>}
        <div className="buttons">
          <button type="button" onClick={onClose}>{readOnly ? 'Cerrar' : 'Cancelar'}</button>
          {!readOnly && <button className="primary" disabled={busy || perms.size === 0}>{busy ? 'Guardando…' : 'Guardar'}</button>}
        </div>
      </form>
    </Modal>
  );
}
