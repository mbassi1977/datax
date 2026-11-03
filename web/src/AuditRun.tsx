import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, ApiError, Me, upload } from './api';
import { Modal } from './ResourcePage';
import { Question, Section, severityLabels, Structure, typeLabels } from './audit';

type Ans = { value?: unknown; comment?: string | null };
type Evidence = { id: string; question_id: string | null; kind: 'photo' | 'signature'; url: string; caption: string | null };
type Missing = { question_id: string; reason: 'required' | 'photo_required' | 'comment_required'; text: string };
type Audit = {
  id: string; title: string; status: string; organization: string | null; branch: string | null; structure: Structure; answers: Record<string, Ans>;
  evidences: Evidence[]; review_notes: string | null; due_on: string | null;
};
type Pending = Record<string, { value?: unknown; comment?: string | null; at: string }>;

const reasonText: Record<Missing['reason'], string> = { required: 'Falta responder esta pregunta.', photo_required: 'Falta la foto de evidencia.', comment_required: 'Falta el comentario obligatorio.' };

const position = (): Promise<{ lat: number; lng: number } | null> => new Promise((res) => {
  if (!navigator.geolocation) return res(null);
  navigator.geolocation.getCurrentPosition((p) => res({ lat: p.coords.latitude, lng: p.coords.longitude }), () => res(null), { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
});

const isEmpty = (v: unknown) => v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0);
const uid = () => (crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`);

/** Un enlace "Reanudar / Iniciar" y el formulario de ejecución con guardado automático. */
export function AuditRun({ audit: initial, reload, back }: { me: Me; audit: Audit; reload: () => void; back: string }) {
  const id = initial.id;
  const storeKey = `hrx-audit-pending-${id}`;
  const [status, setStatus] = useState(initial.status);
  const [answers, setAnswers] = useState<Record<string, Ans>>(initial.answers ?? {});
  const [evidences, setEvidences] = useState<Evidence[]>(initial.evidences ?? []);
  const [save, setSave] = useState<'idle' | 'saving' | 'saved' | 'offline'>('idle');
  const [missing, setMissing] = useState<Missing[]>([]);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [confirmSubmit, setConfirmSubmit] = useState(false);
  const pending = useRef<Pending>(((): Pending => { try { return JSON.parse(localStorage.getItem(storeKey) ?? '{}'); } catch { return {}; } })());
  const timer = useRef<number | undefined>(undefined);
  const flushing = useRef(false);
  const running = status === 'in_progress';

  // Respuestas guardadas en este dispositivo que aún no llegaron al servidor (p. ej. se cortó la señal) se muestran y se reenvían.
  useEffect(() => {
    const p = pending.current;
    if (Object.keys(p).length) setAnswers((a) => ({ ...a, ...Object.fromEntries(Object.entries(p).map(([k, v]) => [k, { ...a[k], ...('value' in v ? { value: v.value } : {}), ...('comment' in v ? { comment: v.comment } : {}) }])) }));
  }, []);

  const persist = () => { try { Object.keys(pending.current).length ? localStorage.setItem(storeKey, JSON.stringify(pending.current)) : localStorage.removeItem(storeKey); } catch { /* sin storage */ } };

  const flush = useCallback(async () => {
    if (flushing.current || !Object.keys(pending.current).length || !running) return;
    flushing.current = true; setSave('saving');
    const batch = { ...pending.current };
    try {
      await api('PUT', `audits/${id}/answers`, { answers: Object.entries(batch).map(([question_id, v]) => ({ question_id, ...('value' in v ? { value: v.value ?? null } : {}), ...('comment' in v ? { comment: v.comment ?? null } : {}), client_updated_at: v.at })) });
      for (const [k, v] of Object.entries(batch)) if (pending.current[k]?.at === v.at) delete pending.current[k];   // lo que cambió mientras tanto sigue pendiente
      persist(); setSave(Object.keys(pending.current).length ? 'saving' : 'saved'); setError('');
    } catch (e) {
      const ae = e as ApiError;
      if (ae.status === 422 || ae.status === 409) { setError(Object.values(ae.errors ?? {}).flat().join(' ') || ae.message); pending.current = {}; persist(); setSave('idle'); }
      else setSave('offline');   // sin conexión: se reintenta solo
    } finally { flushing.current = false; if (Object.keys(pending.current).length && save !== 'offline') schedule(); }
  }, [id, running]); // eslint-disable-line react-hooks/exhaustive-deps

  const schedule = () => { window.clearTimeout(timer.current); timer.current = window.setTimeout(flush, 700); };
  useEffect(() => {
    const online = () => flush();
    window.addEventListener('online', online);
    const iv = window.setInterval(flush, 10000);
    flush();
    return () => { window.removeEventListener('online', online); window.clearInterval(iv); window.clearTimeout(timer.current); };
  }, [flush]);

  const setAnswer = (qid: string, patch: Ans) => {
    setAnswers((a) => ({ ...a, [qid]: { ...a[qid], ...patch } }));
    pending.current[qid] = { ...pending.current[qid], ...('value' in patch ? { value: patch.value } : {}), ...('comment' in patch ? { comment: patch.comment } : {}), at: new Date().toISOString() };
    persist(); setSave('saving'); setMissing((m) => m.filter((x) => x.question_id !== qid)); schedule();
  };

  const visible = useCallback((q: Question) => {
    const c = q.show_if;
    if (!c) return true;
    const v = answers[c.question]?.value;
    return Array.isArray(v) ? v.includes(c.equals) : v === c.equals;
  }, [answers]);

  const evFor = (qid: string, kind?: string) => evidences.filter((e) => e.question_id === qid && (!kind || e.kind === kind));
  const answered = useCallback((q: Question) => {
    if (q.type === 'photo') return evidences.some((e) => e.question_id === q.id && e.kind === 'photo');
    if (q.type === 'signature') return evidences.some((e) => e.question_id === q.id && e.kind === 'signature');
    const v = answers[q.id]?.value;
    return q.type === 'gps' ? typeof v === 'object' && v !== null && 'lat' in (v as object) : !isEmpty(v);
  }, [answers, evidences]);

  const progress = useMemo(() => {
    const qs = initial.structure.sections.flatMap((s) => s.questions).filter(visible);
    return { done: qs.filter(answered).length, total: qs.length };
  }, [initial.structure, visible, answered]);

  const start = async () => {
    setBusy(true); setError('');
    try { const p = await position(); await api('POST', `audits/${id}/start`, p ?? {}); setStatus('in_progress'); reload(); } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  };

  const addEvidence = async (qid: string, file: File, kind: 'photo' | 'signature' = 'photo') => {
    setError('');
    const form = new FormData();
    form.append('file', file); form.append('question_id', qid); form.append('kind', kind); form.append('client_id', uid()); form.append('taken_at', new Date().toISOString());
    const p = await position();
    if (p) { form.append('latitude', String(p.lat)); form.append('longitude', String(p.lng)); }
    try { const r = await upload<Evidence>('audits/' + id + '/evidence', form); setEvidences((e) => [...e, r]); setMissing((m) => m.filter((x) => !(x.question_id === qid && x.reason !== 'comment_required'))); }
    catch (e) { setError((e as ApiError).status === 0 || (e as Error).message === 'Failed to fetch' ? 'Sin conexión: las fotos requieren señal. Inténtelo de nuevo cuando la recupere.' : (e as Error).message); }
  };
  const removeEvidence = async (e: Evidence) => {
    try { await api('DELETE', `audits/${id}/evidence/${e.id}`); setEvidences((x) => x.filter((y) => y.id !== e.id)); } catch (err) { setError((err as Error).message); }
  };

  const submit = async () => {
    setConfirmSubmit(false); setBusy(true); setError('');
    try {
      await flushAll();
      const p = await position();
      await api('POST', `audits/${id}/submit`, p ?? {});
      try { localStorage.removeItem(storeKey); } catch { /* */ }
      reload();
    } catch (e) {
      const ae = e as ApiError;
      const m = ae.data?.missing as Missing[] | undefined;
      if (ae.status === 422 && Array.isArray(m)) { setMissing(m); setError(`Faltan ${m.length} dato(s) obligatorios. Están marcados en rojo.`); document.getElementById(`q-${m[0].question_id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
      else setError(ae.message);
    } finally { setBusy(false); }
  };
  const flushAll = async () => { window.clearTimeout(timer.current); for (let i = 0; i < 5 && Object.keys(pending.current).length; i++) { await flush(); await new Promise((r) => setTimeout(r, 150)); } if (Object.keys(pending.current).length) throw new Error('Hay respuestas sin guardar por falta de conexión. Espere a que se guarden e inténtelo de nuevo.'); };

  const missingFor = (qid: string) => missing.filter((m) => m.question_id === qid);

  if (status === 'scheduled') {
    return (
      <section className="run">
        <a href={back} className="back-link">← Volver</a>
        <div className="card pad hero">
          <h1>{initial.title}</h1>
          <p className="muted">{[initial.organization, initial.branch].filter(Boolean).join(' · ')}{initial.due_on && ` · límite ${initial.due_on}`}</p>
          <p>{progress.total} preguntas en {initial.structure.sections.length} secciones. Sus respuestas se guardan solas mientras avanza.</p>
          {error && <p className="error">{error}</p>}
          <button className="primary big" onClick={start} disabled={busy}>{busy ? 'Iniciando…' : 'Iniciar auditoría'}</button>
          <small className="muted">Se registrará su ubicación al iniciar, si el dispositivo lo permite.</small>
        </div>
      </section>
    );
  }

  return (
    <section className="run">
      <div className="run-top">
        <a href={back} className="back-link">← Mis auditorías</a>
        <div className="saved" aria-live="polite">{save === 'saving' ? 'Guardando…' : save === 'offline' ? '⚠ Sin conexión: se enviará al volver la señal' : save === 'saved' ? '✓ Guardado' : ''}</div>
      </div>
      <h1>{initial.title}</h1>
      <p className="muted">{[initial.organization, initial.branch].filter(Boolean).join(' · ')}</p>
      {initial.review_notes && status !== 'scheduled' && <div className="notice"><strong>Observaciones del revisor:</strong> {initial.review_notes}</div>}
      <div className="progress" role="progressbar" aria-valuenow={progress.done} aria-valuemax={progress.total} aria-label="Avance">
        <div style={{ width: `${progress.total ? (progress.done / progress.total) * 100 : 0}%` }} /><span>{progress.done} de {progress.total}</span>
      </div>
      {error && <p className="error">{error}</p>}

      {initial.structure.sections.map((s: Section) => {
        const qs = s.questions.filter(visible);
        if (!qs.length) return null;
        return (
          <div key={s.id} className="card pad runsec">
            <h2>{s.title}</h2>
            {qs.map((q, i) => (
              <div key={q.id} id={`q-${q.id}`} className={`runq ${missingFor(q.id).length ? 'miss' : ''}`}>
                <div className="qt"><span className="qnum">{i + 1}</span><div><strong>{q.text}</strong>{q.required && <span className="req"> *</span>}{q.critical && <span className="badge failed">Crítica</span>}{q.help && <div className="muted">{q.help}</div>}</div></div>
                <Field q={q} ans={answers[q.id]} onChange={(v) => setAnswer(q.id, { value: v })} />
                {q.type === 'photo' || (q.evidence.photo !== 'none' && q.type !== 'signature') || q.type === 'signature' ? (
                  <Evidence q={q} items={evFor(q.id)} disabled={!running} onAdd={(f, k) => addEvidence(q.id, f, k)} onRemove={removeEvidence} />
                ) : null}
                {q.type !== 'photo' && q.type !== 'signature' && (
                  <textarea className="comment" rows={1} placeholder={q.evidence.comment_on_fail ? 'Comentario (obligatorio si no cumple)' : 'Comentario (opcional)'} value={answers[q.id]?.comment ?? ''} disabled={!running} onChange={(e) => setAnswer(q.id, { comment: e.target.value })} />
                )}
                {missingFor(q.id).map((m) => <p key={m.reason} className="error">{reasonText[m.reason]}</p>)}
              </div>
            ))}
          </div>
        );
      })}

      <div className="stickybar run-actions">
        <button className="primary big" onClick={() => setConfirmSubmit(true)} disabled={busy || !running}>{busy ? 'Enviando…' : 'Enviar auditoría'}</button>
      </div>
      {confirmSubmit && (
        <Modal title="Enviar auditoría" onClose={() => setConfirmSubmit(false)}>
          <p>Va a enviar la auditoría para revisión. Después no podrá modificarla, salvo que el revisor la devuelva.</p>
          <p className="muted">Avance: {progress.done} de {progress.total} preguntas respondidas.</p>
          <div className="buttons"><button onClick={() => setConfirmSubmit(false)}>Seguir editando</button><button className="primary" onClick={submit}>Enviar</button></div>
        </Modal>
      )}
    </section>
  );
}

// ───────────────────────────── controles por tipo ─────────────────────────────

function Field({ q, ans, onChange }: { q: Question; ans?: Ans; onChange: (v: unknown) => void }) {
  const v = ans?.value;
  const [gpsBusy, setGpsBusy] = useState(false);
  switch (q.type) {
    case 'yes_no':
      return <div className="seg">{([['yes', 'Sí'], ['no', 'No'], ['na', 'N/A']] as const).map(([k, l]) => <button key={k} type="button" className={`${v === k ? 'on' : ''} ${k}`} aria-pressed={v === k} onClick={() => onChange(v === k ? null : k)}>{l}</button>)}</div>;
    case 'scale': {
      const nums = Array.from({ length: q.scale!.max - q.scale!.min + 1 }, (_, i) => q.scale!.min + i);
      return <div className="seg">{nums.map((n) => <button key={n} type="button" className={v === n ? 'on' : ''} aria-pressed={v === n} onClick={() => onChange(v === n ? null : n)}>{n}</button>)}</div>;
    }
    case 'choice':
      return <div className="choices">{q.options!.map((o) => <label key={o.id} className="check"><input type="radio" name={q.id} checked={v === o.id} onChange={() => onChange(o.id)} />{o.label}</label>)}</div>;
    case 'multi_choice': {
      const sel = Array.isArray(v) ? (v as string[]) : [];
      return <div className="choices">{q.options!.map((o) => <label key={o.id} className="check"><input type="checkbox" checked={sel.includes(o.id)} onChange={(e) => onChange(e.target.checked ? [...sel, o.id] : sel.filter((x) => x !== o.id))} />{o.label}</label>)}</div>;
    }
    case 'number':
      return <div className="numrow"><input type="number" inputMode="decimal" value={v === null || v === undefined ? '' : String(v)} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} />{q.number?.unit && <span className="muted">{q.number.unit}</span>}</div>;
    case 'text':
      return <textarea rows={2} value={(v as string) ?? ''} maxLength={2000} onChange={(e) => onChange(e.target.value)} />;
    case 'date':
      return <input type="date" value={(v as string) ?? ''} onChange={(e) => onChange(e.target.value)} />;
    case 'gps': {
      const g = v as { lat: number; lng: number } | null | undefined;
      return (
        <div>
          <button type="button" disabled={gpsBusy} onClick={async () => { setGpsBusy(true); const p = await position(); setGpsBusy(false); p ? onChange({ lat: p.lat, lng: p.lng }) : alert('No se pudo obtener la ubicación. Revise el permiso de ubicación del navegador.'); }}>{gpsBusy ? 'Buscando…' : g ? 'Actualizar ubicación' : 'Capturar ubicación'}</button>
          {g && <span className="muted"> {g.lat.toFixed(5)}, {g.lng.toFixed(5)} · <a href={`https://www.openstreetmap.org/?mlat=${g.lat}&mlon=${g.lng}#map=18/${g.lat}/${g.lng}`} target="_blank" rel="noreferrer">ver mapa</a></span>}
        </div>
      );
    }
    default:
      return null;
  }
}

function Evidence({ q, items, disabled, onAdd, onRemove }: { q: Question; items: Evidence[]; disabled: boolean; onAdd: (f: File, kind: 'photo' | 'signature') => void; onRemove: (e: Evidence) => void }) {
  const [sig, setSig] = useState(false);
  const [zoom, setZoom] = useState<Evidence | null>(null);
  const isSig = q.type === 'signature';
  const hint = q.type === 'photo' || q.type === 'signature' ? '' : q.evidence.photo === 'required' ? 'Foto obligatoria' : q.evidence.photo === 'on_fail' ? 'Foto obligatoria si no cumple' : 'Foto opcional';
  return (
    <div className="evid">
      <div className="thumbs">
        {items.map((e) => (
          <figure key={e.id}><img src={e.url} alt={e.kind === 'signature' ? 'Firma' : 'Evidencia'} onClick={() => setZoom(e)} />{!disabled && <button type="button" onClick={() => onRemove(e)} aria-label="Quitar">✕</button>}</figure>
        ))}
      </div>
      {!disabled && (
        isSig
          ? <button type="button" onClick={() => setSig(true)}>{items.length ? 'Firmar de nuevo' : 'Capturar firma'}</button>
          : <label className="btn">📷 {items.length ? 'Agregar otra foto' : 'Tomar o subir foto'}<input type="file" accept="image/*" capture="environment" multiple hidden onChange={(e) => { [...(e.target.files ?? [])].forEach((f) => onAdd(f, 'photo')); e.target.value = ''; }} /></label>
      )}
      {hint && <small className="muted"> {hint}</small>}
      {sig && <SignaturePad onClose={() => setSig(false)} onSave={(f) => { setSig(false); onAdd(f, 'signature'); }} />}
      {zoom && <Modal title={zoom.kind === 'signature' ? 'Firma' : 'Evidencia'} onClose={() => setZoom(null)}><img src={zoom.url} alt="" style={{ width: '100%', borderRadius: 8, background: '#fff' }} /></Modal>}
    </div>
  );
}

function SignaturePad({ onClose, onSave }: { onClose: () => void; onSave: (f: File) => void }) {
  const ref = useRef<HTMLCanvasElement>(null);
  const drawing = useRef(false);
  const [empty, setEmpty] = useState(true);
  useEffect(() => { const c = ref.current!; const g = c.getContext('2d')!; g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.lineWidth = 2.5; g.lineCap = 'round'; g.strokeStyle = '#111'; }, []);
  const pt = (e: React.PointerEvent) => { const r = ref.current!.getBoundingClientRect(); return [(e.clientX - r.left) * (ref.current!.width / r.width), (e.clientY - r.top) * (ref.current!.height / r.height)]; };
  const down = (e: React.PointerEvent) => { drawing.current = true; ref.current!.setPointerCapture(e.pointerId); const g = ref.current!.getContext('2d')!; g.beginPath(); const [x, y] = pt(e); g.moveTo(x, y); };
  const move = (e: React.PointerEvent) => { if (!drawing.current) return; const g = ref.current!.getContext('2d')!; const [x, y] = pt(e); g.lineTo(x, y); g.stroke(); setEmpty(false); };
  const clear = () => { const c = ref.current!; const g = c.getContext('2d')!; g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); setEmpty(true); };
  return (
    <Modal title="Firma" onClose={onClose}>
      <canvas ref={ref} width={600} height={260} className="sigpad" onPointerDown={down} onPointerMove={move} onPointerUp={() => (drawing.current = false)} onPointerCancel={() => (drawing.current = false)} aria-label="Área de firma" />
      <div className="buttons"><button onClick={clear}>Limpiar</button><button onClick={onClose}>Cancelar</button><button className="primary" disabled={empty} onClick={() => ref.current!.toBlob((b) => b && onSave(new File([b], 'firma.png', { type: 'image/png' })), 'image/png')}>Guardar firma</button></div>
    </Modal>
  );
}

export { severityLabels, typeLabels };
