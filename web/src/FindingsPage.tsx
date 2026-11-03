import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me } from './api';
import { can, Modal } from './ResourcePage';
import { severityLabels, statusClass, statusLabels } from './audit';

type Finding = {
  id: string; audit_id: string; audit_title: string; organization: string | null; section_title: string | null; question_text: string | null; description: string;
  severity: keyof typeof severityLabels; status: string; source: string; responsible_person_id: string | null; responsible: string | null; action_plan: string | null; due_on: string | null;
};
type Page = { data: Finding[]; current_page: number; last_page: number; total: number };

export function FindingsPage({ me }: { me: Me }) {
  const [page, setPage] = useState<Page | null>(null);
  const [n, setN] = useState(1);
  const [status, setStatus] = useState('open,in_progress');
  const [severity, setSeverity] = useState('');
  const [error, setError] = useState('');
  const [edit, setEdit] = useState<Finding | null>(null);

  const load = useCallback(async () => {
    setError('');
    const qs = new URLSearchParams({ page: String(n), ...(status && { status }), ...(severity && { severity }) });
    try { setPage(await api<Page>('GET', `findings?${qs}`)); } catch (e) { setError((e as Error).message); }
  }, [n, status, severity]);
  useEffect(() => { load(); }, [load]);

  const today = new Date().toISOString().slice(0, 10);
  return (
    <section>
      <header className="bar"><h1>Hallazgos</h1></header>
      <p className="muted">Lo que no cumplió en las auditorías, con su plan de acción: quién lo resuelve, qué se hará y para cuándo.</p>
      <div className="chips">
        {[['open,in_progress', 'Pendientes'], ['closed', 'Cerrados'], ['', 'Todos']].map(([v, l]) => <button key={v} className={status === v ? 'on' : ''} onClick={() => { setStatus(v); setN(1); }}>{l}</button>)}
        <select value={severity} onChange={(e) => { setSeverity(e.target.value); setN(1); }} aria-label="Severidad" style={{ width: 'auto' }}><option value="">Toda severidad</option>{Object.entries(severityLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
      </div>
      {error && <p className="error">{error}</p>}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Severidad</th><th>Hallazgo</th><th>Auditoría</th><th>Responsable</th><th>Plazo</th><th>Estado</th><th /></tr></thead>
          <tbody>
            {page?.data.map((f) => (
              <tr key={f.id}>
                <td><span className={`badge ${f.severity === 'critical' || f.severity === 'high' ? 'failed' : 'medium'}`}>{severityLabels[f.severity]}</span></td>
                <td className="wrap">{f.description}{f.action_plan && <><br /><small className="muted">Plan: {f.action_plan}</small></>}</td>
                <td><a href={`#/field-audits/${f.audit_id}`}>{f.audit_title}</a><br /><small className="muted">{f.organization}</small></td>
                <td>{f.responsible ?? <span className="muted">Sin asignar</span>}</td>
                <td>{f.due_on ? <span className={f.status !== 'closed' && f.due_on < today ? 'error' : ''}>{f.due_on}{f.status !== 'closed' && f.due_on < today && ' (vencido)'}</span> : '—'}</td>
                <td><span className={`badge ${statusClass(f.status)}`}>{statusLabels[f.status]}</span></td>
                <td className="actions">{can(me, 'hallazgos', 'editar') && <button onClick={() => setEdit(f)}>Gestionar</button>}</td>
              </tr>
            ))}
            {page && page.data.length === 0 && <tr><td colSpan={7} className="muted">No hay hallazgos en esta vista.</td></tr>}
            {!page && !error && <tr><td colSpan={7} className="muted">Cargando…</td></tr>}
          </tbody>
        </table>
      </div>
      {page && page.last_page > 1 && <div className="pager"><button disabled={n <= 1} onClick={() => setN(n - 1)}>←</button><span>Página {page.current_page} de {page.last_page} · {page.total} hallazgos</span><button disabled={n >= page.last_page} onClick={() => setN(n + 1)}>→</button></div>}
      {edit && <Manage f={edit} onClose={() => setEdit(null)} onSaved={() => { setEdit(null); load(); }} />}
    </section>
  );
}

function Manage({ f, onClose, onSaved }: { f: Finding; onClose: () => void; onSaved: () => void }) {
  const [v, setV] = useState({ status: f.status, severity: f.severity, responsible_person_id: f.responsible_person_id ?? '', action_plan: f.action_plan ?? '', due_on: f.due_on ?? '' });
  const [people, setPeople] = useState<{ id: string; first_name: string; last_name: string | null }[]>([]);
  const [error, setError] = useState('');
  useEffect(() => { api<{ data: typeof people }>('GET', 'people').then((r) => setPeople(r.data)).catch(() => {}); }, []);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    try { await api('PUT', `findings/${f.id}`, { ...v, responsible_person_id: v.responsible_person_id || null, action_plan: v.action_plan || null, due_on: v.due_on || null }); onSaved(); }
    catch (e2) { const ae = e2 as ApiError; setError(Object.values(ae.errors ?? {}).flat().join(' ') || ae.message); }
  };
  return (
    <Modal title="Gestionar hallazgo" onClose={onClose}>
      <p>{f.description}</p>
      <form onSubmit={submit} className="form">
        <label><span>Estado</span><select value={v.status} onChange={(e) => setV({ ...v, status: e.target.value })}><option value="open">Abierto</option><option value="in_progress">En curso</option><option value="closed">Cerrado</option></select></label>
        <label><span>Severidad</span><select value={v.severity} onChange={(e) => setV({ ...v, severity: e.target.value as Finding['severity'] })}>{Object.entries(severityLabels).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></label>
        <label><span>Responsable</span><select value={v.responsible_person_id} onChange={(e) => setV({ ...v, responsible_person_id: e.target.value })}><option value="">— sin asignar —</option>{people.map((p) => <option key={p.id} value={p.id}>{[p.first_name, p.last_name].filter(Boolean).join(' ')}</option>)}</select></label>
        <label><span>Plazo</span><input type="date" value={v.due_on} onChange={(e) => setV({ ...v, due_on: e.target.value })} /></label>
        <label className="wide"><span>Plan de acción</span><textarea rows={3} value={v.action_plan} maxLength={2000} onChange={(e) => setV({ ...v, action_plan: e.target.value })} placeholder="¿Qué se hará para corregirlo?" /></label>
        {error && <p className="error wide">{error}</p>}
        <div className="wide buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="primary">Guardar</button></div>
      </form>
    </Modal>
  );
}
