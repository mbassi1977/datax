import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError, Me } from './api';
import { can, Modal } from './ResourcePage';
import {
  blankQuestion, evidenceLabels, newId, Option, Question, QType, Section, severityLabels, statusClass, statusLabels, Structure, typeLabels, withType,
} from './audit';

type Summary = { id: string; name: string; description: string | null; status: string; version: number; pass_threshold: number; sections_count: number; questions_count: number; updated_at: string };
type Full = Summary & { structure: Structure };

export function TemplatesPage({ me, id }: { me: Me; id?: string }) {
  return id ? <Builder me={me} id={id} /> : <List me={me} />;
}

function List({ me }: { me: Me }) {
  const [items, setItems] = useState<Summary[]>([]);
  const [error, setError] = useState('');
  const [creating, setCreating] = useState(false);
  const load = useCallback(() => { api<Summary[]>('GET', 'audit-templates').then(setItems).catch((e) => setError(e.message)); }, []);
  useEffect(load, [load]);

  const act = async (fn: () => Promise<unknown>) => { setError(''); try { await fn(); load(); } catch (e) { setError((e as Error).message); } };
  const canWrite = can(me, 'plantillas_auditoria', 'editar');

  return (
    <section>
      <header className="bar"><h1>Plantillas de auditoría</h1>{can(me, 'plantillas_auditoria', 'crear') && <button className="primary" onClick={() => setCreating(true)}>+ Nueva plantilla</button>}</header>
      <p className="muted">Una plantilla define las secciones y preguntas de una auditoría. Al asignar una auditoría se guarda una copia, así que editar la plantilla después no cambia las auditorías ya asignadas.</p>
      {error && <p className="error">{error}</p>}
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Plantilla</th><th>Estado</th><th>Versión</th><th>Secciones</th><th>Preguntas</th><th>Mín. para aprobar</th><th /></tr></thead>
          <tbody>
            {items.map((t) => (
              <tr key={t.id}>
                <td><a href={`#/field-templates/${t.id}`}><strong>{t.name}</strong></a>{t.description && <><br /><small className="muted">{t.description}</small></>}</td>
                <td><span className={`badge ${statusClass(t.status)}`}>{statusLabels[t.status]}</span></td>
                <td>{t.version}</td><td>{t.sections_count}</td><td>{t.questions_count}</td><td>{t.pass_threshold}%</td>
                <td className="actions">
                  <button onClick={() => (location.hash = `#/field-templates/${t.id}`)}>{canWrite && t.status !== 'archived' ? 'Editar' : 'Ver'}</button>
                  {can(me, 'plantillas_auditoria', 'crear') && <button onClick={() => act(async () => { const c = await api<Summary>('POST', `audit-templates/${t.id}/duplicate`); location.hash = `#/field-templates/${c.id}`; })}>Duplicar</button>}
                  {canWrite && (t.status === 'archived'
                    ? <button onClick={() => act(() => api('POST', `audit-templates/${t.id}/restore`))}>Restaurar</button>
                    : <button onClick={() => act(() => api('POST', `audit-templates/${t.id}/archive`))}>Archivar</button>)}
                  {can(me, 'plantillas_auditoria', 'eliminar') && <button className="danger" onClick={() => confirm(`¿Eliminar la plantilla «${t.name}»? Las auditorías ya asignadas no se ven afectadas.`) && act(() => api('DELETE', `audit-templates/${t.id}`))}>Eliminar</button>}
                </td>
              </tr>
            ))}
            {items.length === 0 && <tr><td colSpan={7} className="muted">Aún no hay plantillas.</td></tr>}
          </tbody>
        </table>
      </div>
      {creating && <NewTemplate onClose={() => setCreating(false)} />}
    </section>
  );
}

function NewTemplate({ onClose }: { onClose: () => void }) {
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    try { const t = await api<Summary>('POST', 'audit-templates', { name, structure: { sections: [] } }); location.hash = `#/field-templates/${t.id}`; } catch (e2) { const ae = e2 as ApiError; setError(Object.values(ae.errors).flat().join(' ') || ae.message); }
  };
  return (
    <Modal title="Nueva plantilla" onClose={onClose}>
      <form onSubmit={submit} className="form one">
        <label><span>Nombre *</span><input value={name} onChange={(e) => setName(e.target.value)} required maxLength={150} autoFocus placeholder="Ej.: Auditoría de punto de venta" /></label>
        {error && <p className="error">{error}</p>}
        <div className="buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="primary">Crear y editar</button></div>
      </form>
    </Modal>
  );
}

// ───────────────────────────── editor ─────────────────────────────

function Builder({ me, id }: { me: Me; id: string }) {
  const [tpl, setTpl] = useState<Full | null>(null);
  const [meta, setMeta] = useState({ name: '', description: '', pass_threshold: 80 });
  const [structure, setStructure] = useState<Structure>({ sections: [] });
  const [dirty, setDirty] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const editable = can(me, 'plantillas_auditoria', 'editar') && tpl?.status !== 'archived';

  const load = useCallback(async () => {
    try {
      const t = await api<Full>('GET', `audit-templates/${id}`);
      setTpl(t); setMeta({ name: t.name, description: t.description ?? '', pass_threshold: t.pass_threshold }); setStructure(t.structure); setDirty(false); setErrors({});
    } catch (e) { setMsg({ ok: false, text: (e as Error).message }); }
  }, [id]);
  useEffect(() => { load(); }, [load]);

  useEffect(() => {
    if (!dirty) return;
    const h = (e: BeforeUnloadEvent) => { e.preventDefault(); };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  const change = (fn: (s: Structure) => Structure) => { setStructure((s) => fn(structuredClone(s))); setDirty(true); setMsg(null); };
  const setSection = (si: number, patch: Partial<Section>) => change((s) => { Object.assign(s.sections[si], patch); return s; });
  const setQuestion = (si: number, qi: number, q: Question) => change((s) => { s.sections[si].questions[qi] = q; return s; });
  const move = <T,>(arr: T[], i: number, d: number) => { const j = i + d; if (j < 0 || j >= arr.length) return; [arr[i], arr[j]] = [arr[j], arr[i]]; };

  const save = async (): Promise<boolean> => {
    setBusy(true); setMsg(null); setErrors({});
    try {
      const t = await api<Full>('PUT', `audit-templates/${id}`, { ...meta, description: meta.description || null, structure });
      setTpl(t); setStructure(t.structure); setDirty(false); setMsg({ ok: true, text: 'Cambios guardados.' });
      return true;
    } catch (e) {
      const ae = e as ApiError; setErrors(ae.errors ?? {}); setMsg({ ok: false, text: Object.keys(ae.errors ?? {}).length ? 'Revise los campos marcados.' : ae.message });
      return false;
    } finally { setBusy(false); }
  };
  const publish = async () => {
    if (dirty && !(await save())) return;
    setBusy(true);
    try { await api('POST', `audit-templates/${id}/publish`); await load(); setMsg({ ok: true, text: 'Plantilla publicada: ya se puede asignar.' }); }
    catch (e) { const ae = e as ApiError; setErrors(ae.errors ?? {}); setMsg({ ok: false, text: Object.keys(ae.errors ?? {}).length ? 'No se pudo publicar: revise los campos marcados.' : ae.message }); }
    finally { setBusy(false); }
  };

  const err = (path: string) => errors[`structure.${path}`] ?? [];
  const order = useMemo(() => structure.sections.flatMap((s, si) => s.questions.map((q, qi) => ({ q, si, qi }))), [structure]);

  if (!tpl) return <section>{msg ? <p className="error">{msg.text}</p> : <p className="muted">Cargando…</p>}</section>;

  return (
    <section>
      <header className="bar">
        <h1><a href="#/field-templates" className="back">←</a> {tpl.name} <span className={`badge ${statusClass(tpl.status)}`}>{statusLabels[tpl.status]}</span> <small className="muted">v{tpl.version}</small></h1>
        {editable && <>
          <button onClick={save} disabled={!dirty || busy}>{busy ? 'Guardando…' : dirty ? 'Guardar cambios' : 'Guardado'}</button>
          {tpl.status !== 'published' && <button className="primary" onClick={publish} disabled={busy}>Publicar</button>}
        </>}
      </header>
      {tpl.status === 'published' && editable && <p className="muted">Está publicada: los cambios que guarde crean una nueva versión. Las auditorías ya asignadas conservan la versión con la que se crearon.</p>}
      {msg && <p className={msg.ok ? 'ok' : 'error'}>{msg.text}</p>}

      <div className="card pad form">
        <label><span>Nombre *</span><input value={meta.name} disabled={!editable} onChange={(e) => { setMeta({ ...meta, name: e.target.value }); setDirty(true); }} />{errors.name?.map((m) => <small key={m} className="error">{m}</small>)}</label>
        <label><span>Porcentaje mínimo para aprobar</span><input type="number" min={1} max={100} value={meta.pass_threshold} disabled={!editable} onChange={(e) => { setMeta({ ...meta, pass_threshold: Number(e.target.value) }); setDirty(true); }} /></label>
        <label className="wide"><span>Descripción</span><textarea rows={2} value={meta.description} disabled={!editable} onChange={(e) => { setMeta({ ...meta, description: e.target.value }); setDirty(true); }} /></label>
      </div>
      {err('sections').map((m) => <p key={m} className="error">{m}</p>)}

      {structure.sections.map((s, si) => (
        <div key={s.id} className="card pad section-card">
          <div className="section-head">
            <input className="section-title" value={s.title} placeholder="Título de la sección" disabled={!editable} onChange={(e) => setSection(si, { title: e.target.value })} aria-label={`Título de la sección ${si + 1}`} />
            <label className="inline"><span>Peso</span><input type="number" min={0.1} max={10} step={0.1} value={s.weight} disabled={!editable} onChange={(e) => setSection(si, { weight: Number(e.target.value) })} /></label>
            {editable && <span className="mini">
              <button onClick={() => change((st) => { move(st.sections, si, -1); return st; })} disabled={si === 0} aria-label="Subir sección">↑</button>
              <button onClick={() => change((st) => { move(st.sections, si, 1); return st; })} disabled={si === structure.sections.length - 1} aria-label="Bajar sección">↓</button>
              <button className="danger" onClick={() => confirm('¿Eliminar la sección y sus preguntas?') && change((st) => { st.sections.splice(si, 1); return st; })}>Eliminar</button>
            </span>}
          </div>
          {[...err(`sections.${si}.title`), ...err(`sections.${si}.questions`)].map((m) => <p key={m} className="error">{m}</p>)}

          {s.questions.map((q, qi) => (
            <QuestionEditor
              key={q.id} q={q} index={qi} editable={!!editable} errors={(f) => err(`sections.${si}.questions.${qi}.${f}`)}
              earlier={order.filter((o) => o.si < si || (o.si === si && o.qi < qi)).map((o) => o.q)}
              onChange={(nq) => setQuestion(si, qi, nq)}
              onMove={(d) => change((st) => { move(st.sections[si].questions, qi, d); return st; })}
              onRemove={() => change((st) => { st.sections[si].questions.splice(qi, 1); return st; })}
              isFirst={qi === 0} isLast={qi === s.questions.length - 1}
            />
          ))}
          {editable && <button onClick={() => change((st) => { st.sections[si].questions.push(blankQuestion()); return st; })}>+ Agregar pregunta</button>}
        </div>
      ))}
      {editable && <button className="primary" onClick={() => change((st) => { st.sections.push({ id: newId('s'), title: '', weight: 1, questions: [blankQuestion()] }); return st; })}>+ Agregar sección</button>}
    </section>
  );
}

function QuestionEditor({ q, index, editable, errors, earlier, onChange, onMove, onRemove, isFirst, isLast }: {
  q: Question; index: number; editable: boolean; errors: (f: string) => string[]; earlier: Question[];
  onChange: (q: Question) => void; onMove: (d: number) => void; onRemove: () => void; isFirst: boolean; isLast: boolean;
}) {
  const [open, setOpen] = useState(!q.text);
  const set = (patch: Partial<Question>) => onChange({ ...q, ...patch });
  const isEvidence = q.type === 'photo' || q.type === 'signature';
  const hasErrors = ['text', 'type', 'options', 'scale', 'show_if'].some((f) => errors(f).length) || (q.options ?? []).some((_, i) => errors(`options.${i}.label`).length || errors(`options.${i}.score`).length);
  const setOption = (i: number, patch: Partial<Option>) => set({ options: q.options!.map((o, j) => (j === i ? { ...o, ...patch } : o)) });
  const cond = earlier.find((e) => e.id === q.show_if?.question);

  return (
    <div className={`qcard ${hasErrors ? 'has-error' : ''}`}>
      <div className="qhead" onClick={() => setOpen(!open)}>
        <span className="qnum">{index + 1}</span>
        <span className="qtext">{q.text || <em className="muted">Pregunta sin texto</em>}</span>
        <span className="qbadges">
          <span className="badge">{typeLabels[q.type]}</span>
          {q.critical && <span className="badge failed">Crítica</span>}
          {q.required && <span className="badge">Obligatoria</span>}
          {q.show_if && <span className="badge medium">Condicional</span>}
        </span>
        <span aria-hidden="true">{open ? '▲' : '▼'}</span>
      </div>
      {open && (
        <div className="qbody form">
          <label className="wide"><span>Pregunta *</span><input value={q.text} disabled={!editable} onChange={(e) => set({ text: e.target.value })} maxLength={500} />{errors('text').map((m) => <small key={m} className="error">{m}</small>)}</label>
          <label className="wide"><span>Ayuda para el auditor (opcional)</span><input value={q.help ?? ''} disabled={!editable} onChange={(e) => set({ help: e.target.value || null })} maxLength={500} /></label>
          <label><span>Tipo de respuesta</span>
            <select value={q.type} disabled={!editable} onChange={(e) => onChange(withType(q, e.target.value as QType))}>{(Object.keys(typeLabels) as QType[]).map((t) => <option key={t} value={t}>{typeLabels[t]}</option>)}</select>
          </label>
          <label><span>Peso en el puntaje</span><input type="number" min={0.1} max={10} step={0.1} value={q.weight} disabled={!editable} onChange={(e) => set({ weight: Number(e.target.value) })} /></label>
          <label className="check"><input type="checkbox" checked={q.required} disabled={!editable} onChange={(e) => set({ required: e.target.checked })} /> Obligatoria</label>
          <label className="check"><input type="checkbox" checked={q.critical} disabled={!editable} onChange={(e) => set({ critical: e.target.checked })} /> Crítica (si falla, la auditoría no cumple)</label>
          {!q.critical && <label><span>Severidad del hallazgo si falla</span><select value={q.severity} disabled={!editable} onChange={(e) => set({ severity: e.target.value as Question['severity'] })}>{Object.entries(severityLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>}

          {(q.type === 'choice' || q.type === 'multi_choice') && (
            <div className="wide">
              <span className="muted">Opciones y puntaje (0–100). Una opción sin puntaje no suma al resultado.</span>
              {errors('options').map((m) => <small key={m} className="error">{m}</small>)}
              {q.options!.map((o, i) => (
                <div key={o.id} className="optrow">
                  <input value={o.label} placeholder="Texto de la opción" disabled={!editable} onChange={(e) => setOption(i, { label: e.target.value })} aria-label={`Opción ${i + 1}`} />
                  <input type="number" min={0} max={100} value={o.score ?? ''} placeholder="Puntaje" disabled={!editable} onChange={(e) => setOption(i, { score: e.target.value === '' ? null : Number(e.target.value) })} aria-label={`Puntaje de la opción ${i + 1}`} />
                  {editable && <button onClick={() => set({ options: q.options!.filter((_, j) => j !== i) })} aria-label="Quitar opción">✕</button>}
                  {[...errors(`options.${i}.label`), ...errors(`options.${i}.score`)].map((m) => <small key={m} className="error">{m}</small>)}
                </div>
              ))}
              {editable && <button onClick={() => set({ options: [...q.options!, { id: newId('o'), label: '', score: null }] })}>+ Opción</button>}
            </div>
          )}
          {q.type === 'scale' && (
            <div className="wide optrow">
              <label className="inline"><span>Desde</span><input type="number" min={0} max={9} value={q.scale!.min} disabled={!editable} onChange={(e) => set({ scale: { ...q.scale!, min: Number(e.target.value) } })} /></label>
              <label className="inline"><span>Hasta</span><input type="number" min={1} max={10} value={q.scale!.max} disabled={!editable} onChange={(e) => set({ scale: { ...q.scale!, max: Number(e.target.value) } })} /></label>
              {errors('scale').map((m) => <small key={m} className="error">{m}</small>)}
            </div>
          )}
          {q.type === 'number' && (
            <div className="wide optrow">
              <label className="inline"><span>Unidad</span><input value={q.number!.unit ?? ''} disabled={!editable} onChange={(e) => set({ number: { ...q.number!, unit: e.target.value || null } })} placeholder="°C, kg…" /></label>
              {([['min', 'Mínimo permitido'], ['max', 'Máximo permitido'], ['pass_min', 'Cumple desde'], ['pass_max', 'Cumple hasta']] as const).map(([k, label]) => (
                <label key={k} className="inline"><span>{label}</span><input type="number" value={q.number![k] ?? ''} disabled={!editable} onChange={(e) => set({ number: { ...q.number!, [k]: e.target.value === '' ? null : Number(e.target.value) } })} /></label>
              ))}
            </div>
          )}

          {!isEvidence && (
            <>
              <label><span>Foto como evidencia</span>
                <select value={q.evidence.photo} disabled={!editable} onChange={(e) => set({ evidence: { ...q.evidence, photo: e.target.value as Question['evidence']['photo'] } })}>{Object.entries(evidenceLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
              </label>
              <label className="check"><input type="checkbox" checked={q.evidence.comment_on_fail} disabled={!editable} onChange={(e) => set({ evidence: { ...q.evidence, comment_on_fail: e.target.checked } })} /> Exigir comentario si falla</label>
            </>
          )}

          <div className="wide">
            <label><span>Mostrar solo si…</span>
              <select value={q.show_if?.question ?? ''} disabled={!editable || earlier.length === 0} onChange={(e) => set({ show_if: e.target.value ? { question: e.target.value, equals: null } : null })}>
                <option value="">Siempre se muestra</option>
                {earlier.filter((e) => ['yes_no', 'choice', 'scale', 'multi_choice'].includes(e.type)).map((e) => <option key={e.id} value={e.id}>{e.text || '(sin texto)'}</option>)}
              </select>
            </label>
            {q.show_if && cond && (
              <label><span>…tiene la respuesta</span>
                {cond.type === 'yes_no' ? <select value={String(q.show_if.equals ?? '')} disabled={!editable} onChange={(e) => set({ show_if: { ...q.show_if!, equals: e.target.value } })}><option value="">— elegir —</option><option value="yes">Sí</option><option value="no">No</option><option value="na">N/A</option></select>
                  : cond.options ? <select value={String(q.show_if.equals ?? '')} disabled={!editable} onChange={(e) => set({ show_if: { ...q.show_if!, equals: e.target.value } })}><option value="">— elegir —</option>{cond.options.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}</select>
                    : <input type="number" value={String(q.show_if.equals ?? '')} disabled={!editable} onChange={(e) => set({ show_if: { ...q.show_if!, equals: Number(e.target.value) } })} />}
              </label>
            )}
            {errors('show_if').map((m) => <small key={m} className="error">{m}</small>)}
          </div>

          {editable && (
            <div className="wide buttons left">
              <button onClick={() => onMove(-1)} disabled={isFirst} aria-label="Subir pregunta">↑ Subir</button>
              <button onClick={() => onMove(1)} disabled={isLast} aria-label="Bajar pregunta">↓ Bajar</button>
              <button className="danger" onClick={onRemove}>Eliminar pregunta</button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
