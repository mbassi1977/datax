import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me } from './api';
import { can, Modal } from './ResourcePage';
import { formatDateTime } from './fmt';
import { resultLabels, statusClass, statusLabels } from './audit';
import { AuditRun } from './AuditRun';
import { AuditReview } from './AuditReview';

type Row = {
  id: string; title: string; status: string; organization: string | null; branch: string | null; auditor: string | null; auditor_id: string;
  scheduled_for: string | null; due_on: string | null; score: number | null; result: string | null; scoring_enabled: boolean; findings_count: number; template_name: string;
};
type Page = { data: Row[]; current_page: number; last_page: number; total: number };

export function AuditsPage({ me, id, mine = false }: { me: Me; id?: string; mine?: boolean }) {
  return id ? <Detail me={me} id={id} base={mine ? 'my-audits' : 'field-audits'} /> : <List me={me} mine={mine} />;
}

function List({ me, mine }: { me: Me; mine: boolean }) {
  const [page, setPage] = useState<Page | null>(null);
  const [n, setN] = useState(1);
  const [status, setStatus] = useState(mine ? 'scheduled,in_progress,returned' : '');
  const [q, setQ] = useState('');
  const [error, setError] = useState('');
  const [assigning, setAssigning] = useState(false);
  const base = mine ? 'my-audits' : 'field-audits';

  const load = useCallback(async () => {
    setError('');
    const qs = new URLSearchParams({ page: String(n), ...(status && { status }), ...(q && { q }), ...(mine && { mine: '1' }) });
    try { setPage(await api<Page>('GET', `audits?${qs}`)); } catch (e) { setError((e as Error).message); }
  }, [n, status, q, mine]);
  useEffect(() => { load(); }, [load]);

  const statuses = mine
    ? [['scheduled,in_progress,returned', 'Por hacer'], ['submitted', 'Enviadas'], ['approved', 'Aprobadas'], ['', 'Todas']]
    : [['', 'Todas'], ['scheduled', 'Programadas'], ['in_progress', 'En curso'], ['submitted', 'Por revisar'], ['returned', 'Devueltas'], ['approved', 'Aprobadas'], ['cancelled', 'Canceladas']];

  return (
    <section>
      <header className="bar">
        <h1>{mine ? 'Mis auditorías' : 'Auditorías'}</h1>
        <form className="search" onSubmit={(e) => { e.preventDefault(); setN(1); load(); }}><input placeholder="Buscar…" value={q} onChange={(e) => setQ(e.target.value)} /></form>
        {!mine && can(me, 'auditorias', 'crear') && <button className="primary" onClick={() => setAssigning(true)}>+ Asignar auditoría</button>}
      </header>
      <div className="chips" role="tablist">
        {statuses.map(([v, l]) => <button key={v} role="tab" aria-selected={status === v} className={status === v ? 'on' : ''} onClick={() => { setStatus(v); setN(1); }}>{l}</button>)}
      </div>
      {error && <p className="error">{error}</p>}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Auditoría</th><th>Lugar</th>{!mine && <th>Auditor</th>}<th>Programada</th><th>Estado</th><th>Hallazgos / resultado</th></tr></thead>
          <tbody>
            {page?.data.map((a) => (
              <tr key={a.id} className="clickable" onClick={() => (location.hash = `#/${base}/${a.id}`)}>
                <td><a href={`#/${base}/${a.id}`} onClick={(e) => e.stopPropagation()}><strong>{a.title}</strong></a><br /><small className="muted">{a.template_name}</small></td>
                <td>{a.organization}{a.branch && <><br /><small className="muted">{a.branch}</small></>}</td>
                {!mine && <td>{a.auditor}</td>}
                <td>{a.scheduled_for ?? '—'}{a.due_on && <><br /><small className="muted">límite {a.due_on}</small></>}</td>
                <td><span className={`badge ${statusClass(a.status)}`}>{statusLabels[a.status]}</span></td>
                <td>{['submitted', 'approved', 'returned'].includes(a.status) ? <>{a.findings_count > 0 ? <span className="badge failed">{a.findings_count} hallazgo{a.findings_count === 1 ? '' : 's'}</span> : <span className="badge completed">Sin hallazgos</span>}{a.scoring_enabled && a.result && <> <span className={`badge ${a.result === 'pass' ? 'completed' : 'failed'}`}>{resultLabels[a.result]}</span> <small>{a.score}%</small></>}</> : '—'}</td>
              </tr>
            ))}
            {page && page.data.length === 0 && <tr><td colSpan={6} className="muted">{mine ? 'No tiene auditorías en esta vista.' : 'Sin auditorías para ese filtro.'}</td></tr>}
            {!page && !error && <tr><td colSpan={6} className="muted">Cargando…</td></tr>}
          </tbody>
        </table>
      </div>
      {page && page.last_page > 1 && (
        <div className="pager"><button disabled={n <= 1} onClick={() => setN(n - 1)}>←</button><span>Página {page.current_page} de {page.last_page} · {page.total} auditorías</span><button disabled={n >= page.last_page} onClick={() => setN(n + 1)}>→</button></div>
      )}
      {assigning && <AssignModal onClose={() => setAssigning(false)} onSaved={(id) => { setAssigning(false); location.hash = `#/field-audits/${id}`; }} />}
    </section>
  );
}

function AssignModal({ onClose, onSaved }: { onClose: () => void; onSaved: (id: string) => void }) {
  const [templates, setTemplates] = useState<{ id: string; name: string }[]>([]);
  const [orgs, setOrgs] = useState<{ id: string; legal_name: string }[]>([]);
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [auditors, setAuditors] = useState<{ id: string; email: string }[]>([]);
  const [f, setF] = useState({ template_id: '', organization_id: '', branch_id: '', auditor_id: '', scheduled_for: new Date().toISOString().slice(0, 10), due_on: '', title: '' });
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api<{ id: string; name: string; status: string }[]>('GET', 'audit-templates?status=published').then((t) => setTemplates(t)).catch(() => {});
    api<{ data: { id: string; legal_name: string }[] }>('GET', 'organizations').then((r) => setOrgs(r.data)).catch(() => {});
  }, []);
  useEffect(() => {
    setBranches([]); setAuditors([]); setF((s) => ({ ...s, branch_id: '', auditor_id: '' }));
    if (!f.organization_id) return;
    api<{ data: { id: string; name: string }[] }>('GET', `branches?organization_id=${f.organization_id}`).then((r) => setBranches(r.data)).catch(() => {});
    api<{ id: string; email: string }[]>('GET', `audits/auditors?organization_id=${f.organization_id}`).then(setAuditors).catch(() => {});
  }, [f.organization_id]);

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setError('');
    try {
      const a = await api<{ id: string }>('POST', 'audits', { template_id: f.template_id, organization_id: f.organization_id, auditor_id: f.auditor_id, scheduled_for: f.scheduled_for,
        ...(f.branch_id && { branch_id: f.branch_id }), ...(f.due_on && { due_on: f.due_on }), ...(f.title && { title: f.title }) });
      onSaved(a.id);
    } catch (e2) { const ae = e2 as ApiError; setError(Object.values(ae.errors ?? {}).flat().join(' ') || ae.message); } finally { setBusy(false); }
  };

  return (
    <Modal title="Asignar auditoría" onClose={onClose}>
      <form onSubmit={submit} className="form">
        <label className="wide"><span>Plantilla *</span>
          <select required value={f.template_id} onChange={(e) => setF({ ...f, template_id: e.target.value })}><option value="">— elegir —</option>{templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
          {templates.length === 0 && <small className="muted">No hay plantillas publicadas. Publique una en «Plantillas».</small>}
        </label>
        <label><span>Empresa *</span><select required value={f.organization_id} onChange={(e) => setF({ ...f, organization_id: e.target.value })}><option value="">— elegir —</option>{orgs.map((o) => <option key={o.id} value={o.id}>{o.legal_name}</option>)}</select></label>
        <label><span>Local / sucursal</span><select value={f.branch_id} disabled={!f.organization_id} onChange={(e) => setF({ ...f, branch_id: e.target.value })}><option value="">— toda la empresa —</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></label>
        <label className="wide"><span>Auditor *</span>
          <select required value={f.auditor_id} disabled={!f.organization_id} onChange={(e) => setF({ ...f, auditor_id: e.target.value })}><option value="">— elegir —</option>{auditors.map((u) => <option key={u.id} value={u.id}>{u.email}</option>)}</select>
          {f.organization_id && auditors.length === 0 && <small className="muted">Nadie puede ejecutar auditorías en esta empresa. Asigne el perfil «Auditor en terreno» a un usuario, con alcance sobre ella.</small>}
        </label>
        <label><span>Fecha programada</span><input type="date" value={f.scheduled_for} onChange={(e) => setF({ ...f, scheduled_for: e.target.value })} /></label>
        <label><span>Fecha límite (opcional)</span><input type="date" value={f.due_on} min={f.scheduled_for} onChange={(e) => setF({ ...f, due_on: e.target.value })} /></label>
        <label className="wide"><span>Título (opcional)</span><input value={f.title} maxLength={200} onChange={(e) => setF({ ...f, title: e.target.value })} placeholder="Se genera con la plantilla y el lugar" /></label>
        {error && <p className="error wide">{error}</p>}
        <div className="wide buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="primary" disabled={busy}>Asignar</button></div>
      </form>
    </Modal>
  );
}

function Detail({ me, id, base }: { me: Me; id: string; base: string }) {
  const [audit, setAudit] = useState<any>(null);
  const [error, setError] = useState('');
  const load = useCallback(async () => { try { setAudit(await api('GET', `audits/${id}`)); setError(''); } catch (e) { setError((e as Error).message); } }, [id]);
  useEffect(() => { load(); }, [load]);

  if (error) return <section><p className="error">{error}</p><a href={`#/${base}`}>← Volver</a></section>;
  if (!audit) return <section><p className="muted">Cargando…</p></section>;

  const runnable = audit.auditor_id === me.id && can(me, 'auditorias', 'ejecutar') && ['scheduled', 'in_progress', 'returned'].includes(audit.status);
  return runnable ? <AuditRun me={me} audit={audit} reload={load} back={`#/${base}`} /> : <AuditReview me={me} audit={audit} reload={load} back={`#/${base}`} />;
}
