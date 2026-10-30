import { useCallback, useEffect, useState } from 'react';
import { api, download } from './api';
import { Modal } from './ResourcePage';
import { formatDateTime, getLocale } from './fmt';

type Row = { id: string; created_at: string; user_id: string | null; user_email: string | null; action: string; entity_type: string | null; entity_id: string | null; ip_address: string | null };
type Detail = Row & { old_values: Record<string, unknown> | null; new_values: Record<string, unknown> | null; user_agent: string | null };
type Page = { data: Row[]; current_page: number; last_page: number; total: number };
type Meta = { actions: string[]; entity_types: string[]; users: { id: string; email: string }[] };

const actionLabels: Record<string, string> = {
  created: 'Creó', updated: 'Modificó', deleted: 'Eliminó', login: 'Inicio de sesión', login_failed: 'Intento de acceso fallido', logout: 'Cierre de sesión',
  password_changed: 'Cambio de contraseña', profile_assigned: 'Asignó perfil', profile_revoked: 'Quitó perfil', profile_created: 'Creó perfil',
  profile_updated: 'Modificó perfil', profile_deleted: 'Eliminó perfil', import_completed: 'Importación', avatar_changed: 'Cambió avatar', audit_exported: 'Exportó auditoría',
};
const entityLabels: Record<string, string> = {
  Organization: 'Organización', Branch: 'Sucursal', Area: 'Área', CostCenter: 'Centro de costo', Person: 'Persona', Project: 'Proyecto', User: 'Usuario',
  CatalogItem: 'Entrada de catálogo', AvatarCollection: 'Colección de avatares', Holding: 'Holding',
};
const action = (a: string) => actionLabels[a] ?? a;
const entity = (t: string | null) => (t ? entityLabels[t.split('\\').pop()!] ?? t.split('\\').pop()! : '—');
const when = (s: string) => formatDateTime(s);

export function AuditPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [f, setF] = useState({ q: '', action: '', entity_type: '', user_id: '', from: '', to: '' });
  const [applied, setApplied] = useState(f);
  const [n, setN] = useState(1);
  const [error, setError] = useState('');
  const [detail, setDetail] = useState<Detail | null>(null);

  const qs = (extra: Record<string, string | number> = {}) =>
    new URLSearchParams(Object.entries({ ...applied, ...extra }).filter(([, v]) => v !== '' && v != null).map(([k, v]) => [k, String(v)])).toString();

  const load = useCallback(async () => {
    setError('');
    try { setPage(await api<Page>('GET', `audit-events?${qs({ page: n })}`)); } catch (e) { setError((e as Error).message); }
  }, [applied, n]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { load(); }, [load]);
  useEffect(() => { api<Meta>('GET', 'audit-events/meta').then(setMeta).catch(() => {}); }, []);

  const apply = (e: React.FormEvent) => { e.preventDefault(); setN(1); setApplied(f); };
  const reset = () => { const empty = { q: '', action: '', entity_type: '', user_id: '', from: '', to: '' }; setF(empty); setApplied(empty); setN(1); };
  const open = async (r: Row) => { try { setDetail(await api<Detail>('GET', `audit-events/${r.id}`)); } catch (e) { setError((e as Error).message); } };
  const exportCsv = async () => { try { await download(`audit-events/export?${qs()}`, 'auditoria.csv'); } catch (e) { setError((e as Error).message); } };

  return (
    <section>
      <header className="bar"><h1>Auditoría</h1><button onClick={exportCsv} disabled={!page || page.total === 0}>Exportar CSV</button></header>
      <p className="muted">Registro de quién hizo qué, cuándo y desde dónde. Las fechas se muestran en la zona horaria {getLocale().timezone}. Cada exportación también queda registrada.</p>
      <form className="card pad filters" onSubmit={apply}>
        <label><span>Buscar</span><input value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} placeholder="Usuario, acción, entidad o ID" /></label>
        <label><span>Acción</span>
          <select value={f.action} onChange={(e) => setF({ ...f, action: e.target.value })}><option value="">Todas</option>{meta?.actions.map((a) => <option key={a} value={a}>{action(a)}</option>)}</select>
        </label>
        <label><span>Entidad</span>
          <select value={f.entity_type} onChange={(e) => setF({ ...f, entity_type: e.target.value })}><option value="">Todas</option>{meta?.entity_types.map((t) => <option key={t} value={t}>{entity(t)}</option>)}</select>
        </label>
        <label><span>Usuario</span>
          <select value={f.user_id} onChange={(e) => setF({ ...f, user_id: e.target.value })}><option value="">Todos</option>{meta?.users.map((u) => <option key={u.id} value={u.id}>{u.email}</option>)}</select>
        </label>
        <label><span>Desde</span><input type="date" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} /></label>
        <label><span>Hasta</span><input type="date" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} /></label>
        <div className="buttons"><button type="button" onClick={reset}>Limpiar</button><button className="primary">Filtrar</button></div>
      </form>
      {error && <p className="error">{error}</p>}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Entidad</th><th>IP</th><th /></tr></thead>
          <tbody>
            {page?.data.map((r) => (
              <tr key={r.id}>
                <td>{when(r.created_at)}</td><td>{r.user_email ?? <span className="muted">—</span>}</td>
                <td><span className={`badge ${r.action === 'deleted' || r.action === 'login_failed' ? 'failed' : r.action === 'created' ? 'completed' : ''}`}>{action(r.action)}</span></td>
                <td>{entity(r.entity_type)}</td><td>{r.ip_address ?? '—'}</td>
                <td className="actions"><button onClick={() => open(r)}>Detalle</button></td>
              </tr>
            ))}
            {page && page.data.length === 0 && <tr><td colSpan={6} className="muted">Sin eventos para ese filtro.</td></tr>}
            {!page && !error && <tr><td colSpan={6} className="muted">Cargando…</td></tr>}
          </tbody>
        </table>
      </div>
      {page && page.last_page > 1 && (
        <div className="pager">
          <button disabled={n <= 1} onClick={() => setN(n - 1)}>←</button>
          <span>Página {page.current_page} de {page.last_page} · {page.total} eventos</span>
          <button disabled={n >= page.last_page} onClick={() => setN(n + 1)}>→</button>
        </div>
      )}
      {detail && <DetailModal d={detail} onClose={() => setDetail(null)} />}
    </section>
  );
}

const show = (v: unknown) => (v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v));

function DetailModal({ d, onClose }: { d: Detail; onClose: () => void }) {
  const keys = [...new Set([...Object.keys(d.old_values ?? {}), ...Object.keys(d.new_values ?? {})])].sort();
  const changed = (k: string) => show(d.old_values?.[k]) !== show(d.new_values?.[k]);
  return (
    <Modal title={`${action(d.action)} · ${entity(d.entity_type)}`} onClose={onClose}>
      <dl className="facts">
        <dt>Fecha</dt><dd>{when(d.created_at)}</dd>
        <dt>Usuario</dt><dd>{d.user_email ?? '—'}</dd>
        <dt>IP</dt><dd>{d.ip_address ?? '—'}</dd>
        <dt>Navegador</dt><dd className="wrap">{d.user_agent ?? '—'}</dd>
        {d.entity_id && <><dt>ID de la entidad</dt><dd><code>{d.entity_id}</code></dd></>}
      </dl>
      {keys.length > 0 ? (
        <div className="table-wrap">
          <table>
            <thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead>
            <tbody>{keys.map((k) => (
              <tr key={k} className={changed(k) ? 'diff' : ''}><td><code>{k}</code></td><td className="wrap">{show(d.old_values?.[k])}</td><td className="wrap">{show(d.new_values?.[k])}</td></tr>
            ))}</tbody>
          </table>
        </div>
      ) : <p className="muted">Este evento no tiene valores asociados.</p>}
    </Modal>
  );
}
