import { FormEvent, useState } from 'react';
import { api, ApiError, Me } from './api';
import { can, Modal } from './ResourcePage';
import { formatDateTime } from './fmt';
import { Question, resultLabels, severityLabels, statusClass, statusLabels, Structure, typeLabels } from './audit';

type Audit = {
  id: string; title: string; status: string; template_name: string; template_version: number; pass_threshold: number; organization: string | null; branch: string | null; project: string | null;
  auditor: string | null; auditor_id: string; reviewer: string | null; scheduled_for: string | null; due_on: string | null; started_at: string | null; submitted_at: string | null; reviewed_at: string | null;
  review_notes: string | null; score: number | null; result: string | null; critical_failures: number; started_lat: number | null; started_lng: number | null; submitted_lat: number | null; submitted_lng: number | null;
  structure: Structure; answers: Record<string, { value?: unknown; comment?: string | null }>;
  evidences: { id: string; question_id: string | null; kind: string; url: string; latitude: number | null; longitude: number | null; taken_at: string | null }[];
  findings: { id: string; question_text: string | null; description: string; severity: keyof typeof severityLabels; status: string; source: string }[];
  evaluation: { sections: { id: string; title: string; score: number | null; answered: number; total: number }[]; failed: string[]; score: number | null };
};

const show = (q: Question, v: unknown): string => {
  if (v === null || v === undefined || v === '') return '—';
  switch (q.type) {
    case 'yes_no': return ({ yes: 'Sí', no: 'No', na: 'N/A' } as Record<string, string>)[String(v)] ?? String(v);
    case 'scale': return `${v} (de ${q.scale!.min} a ${q.scale!.max})`;
    case 'choice': return q.options!.find((o) => o.id === v)?.label ?? String(v);
    case 'multi_choice': return (Array.isArray(v) ? v : []).map((id) => q.options!.find((o) => o.id === id)?.label ?? id).join(', ');
    case 'number': return `${v}${q.number?.unit ? ` ${q.number.unit}` : ''}`;
    case 'gps': { const g = v as { lat: number; lng: number }; return `${g.lat.toFixed(5)}, ${g.lng.toFixed(5)}`; }
    default: return String(v);
  }
};
const map = (lat: number, lng: number) => `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lng}#map=18/${lat}/${lng}`;

export function AuditReview({ me, audit: a, reload, back }: { me: Me; audit: Audit; reload: () => void; back: string }) {
  const [error, setError] = useState('');
  const [zoom, setZoom] = useState<string | null>(null);
  const [returning, setReturning] = useState(false);
  const [busy, setBusy] = useState(false);
  const failed = new Set(a.evaluation.failed);
  const canReview = a.status === 'submitted' && can(me, 'auditorias', 'aprobar') && a.auditor_id !== me.id;
  const canCancel = ['scheduled', 'in_progress', 'returned'].includes(a.status) && can(me, 'auditorias', 'eliminar');

  const act = async (path: string, body?: unknown) => { setBusy(true); setError(''); try { await api('POST', `audits/${a.id}/${path}`, body); reload(); } catch (e) { setError((e as Error).message); } finally { setBusy(false); } };
  const evOf = (qid: string) => a.evidences.filter((e) => e.question_id === qid);

  return (
    <section>
      <a href={back} className="back-link">← Volver</a>
      <header className="bar">
        <h1>{a.title}</h1>
        <span className={`badge ${statusClass(a.status)}`}>{statusLabels[a.status]}</span>
        {canReview && <><button className="danger" disabled={busy} onClick={() => setReturning(true)}>Devolver</button><button className="primary" disabled={busy} onClick={() => act('approve')}>Aprobar</button></>}
        {canCancel && <button className="danger" disabled={busy} onClick={() => confirm('¿Cancelar esta auditoría?') && act('cancel')}>Cancelar auditoría</button>}
      </header>
      {error && <p className="error">{error}</p>}
      {a.review_notes && <div className="notice"><strong>Observaciones del revisor:</strong> {a.review_notes}</div>}

      <div className="card pad facts-grid">
        <div><span className="muted">Plantilla</span><br />{a.template_name} · v{a.template_version}</div>
        <div><span className="muted">Lugar</span><br />{[a.organization, a.branch, a.project].filter(Boolean).join(' · ')}</div>
        <div><span className="muted">Auditor</span><br />{a.auditor}</div>
        <div><span className="muted">Programada</span><br />{a.scheduled_for ?? '—'}{a.due_on && ` · límite ${a.due_on}`}</div>
        <div><span className="muted">Iniciada</span><br />{formatDateTime(a.started_at)}{a.started_lat != null && <> · <a href={map(a.started_lat, a.started_lng!)} target="_blank" rel="noreferrer">ubicación</a></>}</div>
        <div><span className="muted">Enviada</span><br />{formatDateTime(a.submitted_at)}{a.submitted_lat != null && <> · <a href={map(a.submitted_lat, a.submitted_lng!)} target="_blank" rel="noreferrer">ubicación</a></>}</div>
        {a.reviewer && <div><span className="muted">Revisada por</span><br />{a.reviewer} · {formatDateTime(a.reviewed_at)}</div>}
      </div>

      {a.result && (
        <div className="card pad scoreboard">
          <div className={`big-score ${a.result}`}><strong>{a.score}%</strong><span>{resultLabels[a.result]}</span><small className="muted">mínimo {a.pass_threshold}%</small>{a.critical_failures > 0 && <small className="error">{a.critical_failures} falla(s) crítica(s)</small>}</div>
          <div className="secbars">
            {a.evaluation.sections.map((s) => (
              <div key={s.id}><div className="lbl"><span>{s.title}</span><span>{s.score === null ? 'sin puntaje' : `${s.score}%`}</span></div><div className="bar-track"><div className={`bar-fill ${(s.score ?? 100) < 50 ? 'bad' : (s.score ?? 100) < a.pass_threshold ? 'warn' : ''}`} style={{ width: `${s.score ?? 0}%` }} /></div></div>
            ))}
          </div>
        </div>
      )}

      {a.structure.sections.map((s) => (
        <div key={s.id} className="card pad runsec">
          <h2>{s.title}</h2>
          <table className="answers">
            <tbody>
              {s.questions.map((q) => {
                const ans = a.answers[q.id];
                const ev = evOf(q.id);
                const hidden = q.show_if && ans === undefined && ev.length === 0;
                if (hidden) return null;
                return (
                  <tr key={q.id} className={failed.has(q.id) ? 'failed-row' : ''}>
                    <td className="q"><strong>{q.text}</strong>{q.critical && <span className="badge failed">Crítica</span>}<br /><small className="muted">{typeLabels[q.type]}</small></td>
                    <td className="a">
                      {q.type === 'photo' || q.type === 'signature' ? <span className="muted">{ev.length ? `${ev.length} ${q.type === 'photo' ? 'foto(s)' : 'firma'}` : 'Sin evidencia'}</span> : <strong>{show(q, ans?.value)}</strong>}
                      {ans?.comment && <div className="muted">💬 {ans.comment}</div>}
                      {failed.has(q.id) && <span className="badge failed">No cumple</span>}
                      {ev.length > 0 && <div className="thumbs">{ev.map((e) => <figure key={e.id}><img src={e.url} alt="Evidencia" onClick={() => setZoom(e.url)} /></figure>)}</div>}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      ))}

      {a.findings.length > 0 && (
        <div className="card pad">
          <h2>Hallazgos ({a.findings.length})</h2>
          <table>
            <thead><tr><th>Severidad</th><th>Hallazgo</th><th>Estado</th></tr></thead>
            <tbody>{a.findings.map((f) => <tr key={f.id}><td><span className={`badge ${f.severity === 'critical' || f.severity === 'high' ? 'failed' : 'medium'}`}>{severityLabels[f.severity]}</span></td><td className="wrap">{f.description}</td><td>{statusLabels[f.status]}</td></tr>)}</tbody>
          </table>
          <p className="muted">El seguimiento (responsable, plan de acción y plazo) se gestiona en <a href="#/field-findings">Hallazgos</a>.</p>
        </div>
      )}

      {zoom && <Modal title="Evidencia" onClose={() => setZoom(null)}><img src={zoom} alt="" style={{ width: '100%', borderRadius: 8, background: '#fff' }} /></Modal>}
      {returning && <ReturnModal onClose={() => setReturning(false)} onSend={(notes) => { setReturning(false); act('return', { notes }); }} />}
    </section>
  );
}

function ReturnModal({ onClose, onSend }: { onClose: () => void; onSend: (notes: string) => void }) {
  const [notes, setNotes] = useState('');
  const submit = (e: FormEvent) => { e.preventDefault(); onSend(notes); };
  return (
    <Modal title="Devolver al auditor" onClose={onClose}>
      <form onSubmit={submit} className="form one">
        <label><span>Qué debe corregir *</span><textarea rows={4} required value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={1000} autoFocus /></label>
        <div className="buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="danger">Devolver</button></div>
      </form>
    </Modal>
  );
}

export type { ApiError };
