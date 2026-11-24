import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me } from './api';
import { can, Modal } from './ResourcePage';
import { statusClass, statusLabels } from './audit';

type Survey = {
  id: string; name: string; description: string | null; status: 'active' | 'closed'; organization: string; organization_id: string; template: string | null;
  starts_on: string | null; ends_on: string | null; progress: { total: number; done: number; percent: number; by_status: Record<string, number> };
  audits?: { id: string; title: string; status: string; branch: string | null; auditor: string | null; score: number | null; result: string | null; scheduled_for: string | null }[];
};
type Page = { data: Survey[] };
const err = (e: unknown) => { const ae = e as ApiError; return Object.values(ae.errors ?? {}).flat().join(' ') || ae.message; };

function Bar({ p }: { p: Survey['progress'] }) {
  return <div title={`${p.done} de ${p.total}`} style={{ display: 'flex', alignItems: 'center', gap: 8, minWidth: 140 }}>
    <div style={{ flex: 1, height: 8, borderRadius: 4, background: 'var(--border)', overflow: 'hidden' }}><div style={{ width: `${p.percent}%`, height: '100%', background: 'var(--primary)' }} /></div>
    <small>{p.done}/{p.total}</small></div>;
}

export function SurveysPage({ me, id }: { me: Me; id?: string }) {
  return id ? <Detail me={me} id={id} /> : <List me={me} />;
}

function List({ me }: { me: Me }) {
  const [page, setPage] = useState<Page | null>(null);
  const [status, setStatus] = useState('active');
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState('');
  const load = useCallback(async () => { try { setPage(await api<Page>('GET', `surveys${status ? `?status=${status}` : ''}`)); setError(''); } catch (e) { setError((e as Error).message); } }, [status]);
  useEffect(() => { load(); }, [load]);
  return (
    <section>
      <header className="bar"><h1>Levantamientos en terreno</h1>
        {can(me, 'levantamientos', 'crear') && <button className="primary" onClick={() => setCreating(true)}>+ Nuevo levantamiento</button>}</header>
      <div className="chips">{[['active', 'Activos'], ['closed', 'Cerrados'], ['', 'Todos']].map(([v, l]) => <button key={v} className={status === v ? 'on' : ''} onClick={() => setStatus(v)}>{l}</button>)}</div>
      {error && <p className="error">{error}</p>}
      <div className="card table-wrap"><table>
        <thead><tr><th>Levantamiento</th><th>Empresa</th><th>Plantilla</th><th>Período</th><th>Avance</th><th>Estado</th></tr></thead>
        <tbody>{page?.data.map((s) => (
          <tr key={s.id} className="clickable" onClick={() => (location.hash = `#/surveys/${s.id}`)}>
            <td><strong>{s.name}</strong></td><td>{s.organization}</td><td>{s.template ?? '—'}</td><td>{s.starts_on ?? '—'} → {s.ends_on ?? '—'}</td>
            <td><Bar p={s.progress} /></td><td><span className={`badge ${s.status === 'active' ? 'ok' : ''}`}>{s.status === 'active' ? 'Activo' : 'Cerrado'}</span></td></tr>))}
          {page && page.data.length === 0 && <tr><td colSpan={6} className="muted">Aún no hay levantamientos. Cree uno, asígnele locales y colectores, y la app los descargará para trabajar sin conexión.</td></tr>}
        </tbody></table></div>
      {creating && <CreateModal onClose={() => setCreating(false)} onSaved={(id) => { setCreating(false); location.hash = `#/surveys/${id}`; }} />}
    </section>
  );
}

function CreateModal({ onClose, onSaved }: { onClose: () => void; onSaved: (id: string) => void }) {
  const [templates, setTemplates] = useState<{ id: string; name: string }[]>([]);
  const [orgs, setOrgs] = useState<{ id: string; legal_name: string }[]>([]);
  const [f, setF] = useState({ name: '', template_id: '', organization_id: '', description: '', starts_on: new Date().toISOString().slice(0, 10), ends_on: '' });
  const [error, setError] = useState('');
  useEffect(() => {
    api<{ id: string; name: string }[]>('GET', 'audit-templates?status=published').then(setTemplates).catch(() => {});
    api<{ data: { id: string; legal_name: string }[] }>('GET', 'organizations').then((r) => setOrgs(r.data)).catch(() => {});
  }, []);
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError('');
    try { const s = await api<{ id: string }>('POST', 'surveys', { ...f, ends_on: f.ends_on || null, description: f.description || null }); onSaved(s.id); } catch (x) { setError(err(x)); }
  };
  return (
    <Modal title="Nuevo levantamiento" onClose={onClose}>
      <form onSubmit={submit} className="form">
        <label className="wide"><span>Nombre *</span><input required maxLength={200} value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="Levantamiento de precios noviembre" /></label>
        <label><span>Plantilla *</span><select required value={f.template_id} onChange={(e) => setF({ ...f, template_id: e.target.value })}><option value="">— elegir —</option>{templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></label>
        <label><span>Empresa *</span><select required value={f.organization_id} onChange={(e) => setF({ ...f, organization_id: e.target.value })}><option value="">— elegir —</option>{orgs.map((o) => <option key={o.id} value={o.id}>{o.legal_name}</option>)}</select></label>
        <label><span>Desde</span><input type="date" value={f.starts_on} onChange={(e) => setF({ ...f, starts_on: e.target.value })} /></label>
        <label><span>Hasta</span><input type="date" min={f.starts_on} value={f.ends_on} onChange={(e) => setF({ ...f, ends_on: e.target.value })} /></label>
        <label className="wide"><span>Descripción</span><textarea rows={2} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></label>
        {error && <p className="error wide">{error}</p>}
        <div className="wide buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="primary">Crear</button></div>
      </form>
    </Modal>
  );
}

function Detail({ me, id }: { me: Me; id: string }) {
  const [s, setS] = useState<Survey | null>(null);
  const [error, setError] = useState('');
  const [assigning, setAssigning] = useState(false);
  const load = useCallback(async () => { try { setS(await api<Survey>('GET', `surveys/${id}`)); setError(''); } catch (e) { setError((e as Error).message); } }, [id]);
  useEffect(() => { load(); }, [load]);
  const close = async () => { if (!confirm('Se cierra el levantamiento: lo que no se haya iniciado se cancela. ¿Continuar?')) return; try { await api('POST', `surveys/${id}/close`); load(); } catch (e) { setError(err(e)); } };
  if (!s) return <section>{error ? <p className="error">{error}</p> : <p className="muted">Cargando…</p>}</section>;
  return (
    <section>
      <header className="bar"><h1><a href="#/surveys">←</a> {s.name}</h1>
        {s.status === 'active' && can(me, 'levantamientos', 'crear') && <button className="primary" onClick={() => setAssigning(true)}>+ Asignar locales</button>}
        {s.status === 'active' && can(me, 'levantamientos', 'editar') && <button onClick={close}>Cerrar levantamiento</button>}</header>
      <p className="muted">{s.organization} · plantilla «{s.template ?? '—'}» · {s.starts_on ?? '—'} → {s.ends_on ?? '—'}</p>
      {s.description && <p>{s.description}</p>}
      <Bar p={s.progress} />
      {error && <p className="error">{error}</p>}
      <div className="card table-wrap" style={{ marginTop: 12 }}><table>
        <thead><tr><th>Local</th><th>Colector</th><th>Estado</th><th>Resultado</th></tr></thead>
        <tbody>{s.audits?.map((a) => (
          <tr key={a.id} className="clickable" onClick={() => (location.hash = `#/field-audits/${a.id}`)}>
            <td>{a.branch ?? a.title}</td><td>{a.auditor}</td><td><span className={`badge ${statusClass(a.status)}`}>{statusLabels[a.status]}</span></td><td>{a.score != null ? `${a.score}%` : '—'}</td></tr>))}
          {s.audits?.length === 0 && <tr><td colSpan={4} className="muted">Sin locales asignados todavía.</td></tr>}
        </tbody></table></div>
      {assigning && <AssignModal s={s} onClose={() => setAssigning(false)} onDone={() => { setAssigning(false); load(); }} />}
    </section>
  );
}

function AssignModal({ s, onClose, onDone }: { s: Survey; onClose: () => void; onDone: () => void }) {
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [auditors, setAuditors] = useState<{ id: string; email: string }[]>([]);
  const [pick, setPick] = useState<Record<string, string>>({});       // branch_id → auditor_id (vacío = sin elegir)
  const [bulk, setBulk] = useState('');
  const [error, setError] = useState('');
  const taken = new Set((s.audits ?? []).filter((a) => a.status !== 'cancelled').map((a) => a.branch));
  useEffect(() => {
    api<{ data: { id: string; name: string }[] }>('GET', `branches?organization_id=${s.organization_id}&per_page=500`).then((r) => setBranches(r.data)).catch(() => {});
    api<{ id: string; email: string }[]>('GET', `audits/auditors?organization_id=${s.organization_id}`).then(setAuditors).catch(() => {});
  }, [s.organization_id]);
  const free = branches.filter((b) => !taken.has(b.name));
  const chosen = Object.entries(pick).filter(([, a]) => a);
  const submit = async () => {
    setError('');
    try {
      const r = await api<{ skipped: { reason: string }[] }>('POST', `surveys/${s.id}/assign`, { assignments: chosen.map(([branch_id, auditor_id]) => ({ branch_id, auditor_id })) });
      if (r.skipped.length) setError(`${r.skipped.length} no se asignaron: ${r.skipped[0].reason}`); else onDone();
    } catch (x) { setError(err(x)); }
  };
  return (
    <Modal title="Asignar locales a colectores" onClose={onClose}>
      <div className="form">
        <label className="wide"><span>Mismo colector para todos los locales sin asignar</span>
          <select value={bulk} onChange={(e) => { setBulk(e.target.value); setPick(Object.fromEntries(free.map((b) => [b.id, e.target.value]))); }}>
            <option value="">— elegir —</option>{auditors.map((u) => <option key={u.id} value={u.id}>{u.email}</option>)}</select></label>
        <div className="wide" style={{ maxHeight: 320, overflow: 'auto' }}>
          {free.map((b) => (
            <div key={b.id} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '4px 0' }}>
              <span style={{ flex: 1 }}>{b.name}</span>
              <select value={pick[b.id] ?? ''} onChange={(e) => setPick({ ...pick, [b.id]: e.target.value })}><option value="">— sin asignar —</option>{auditors.map((u) => <option key={u.id} value={u.id}>{u.email}</option>)}</select>
            </div>))}
          {free.length === 0 && <p className="muted">No quedan locales por asignar.</p>}
          {auditors.length === 0 && <p className="muted">Nadie puede ejecutar levantamientos en esta empresa: asigne el perfil «Auditor en terreno» a un usuario.</p>}
        </div>
        {error && <p className="error wide">{error}</p>}
        <div className="wide buttons"><button onClick={onClose}>Cancelar</button><button className="primary" disabled={!chosen.length} onClick={submit}>Asignar {chosen.length || ''}</button></div>
      </div>
    </Modal>
  );
}
