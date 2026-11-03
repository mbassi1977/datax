import { FormEvent, useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError, Me } from './api';
import { can, Modal } from './ResourcePage';
import {
  blankQuestion, canControl, canFinding, Cond, CondOp, describeCond, evidenceLabels, newId, Option, Question, QType, Section, severityLabels, statusClass, statusLabels, Structure,
  typeLabels, withType, yesNoLabels,
} from './audit';

type Summary = { id: string; name: string; description: string | null; status: string; version: number; pass_threshold: number; scoring_enabled: boolean; sections_count: number; questions_count: number; updated_at: string };
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
          <thead><tr><th>Plantilla</th><th>Estado</th><th>Versión</th><th>Secciones</th><th>Preguntas</th><th /></tr></thead>
          <tbody>
            {items.map((t) => (
              <tr key={t.id}>
                <td><a href={`#/field-templates/${t.id}`}><strong>{t.name}</strong></a>{t.scoring_enabled && <> <span className="badge medium">Con puntaje</span></>}{t.description && <><br /><small className="muted">{t.description}</small></>}</td>
                <td><span className={`badge ${statusClass(t.status)}`}>{statusLabels[t.status]}</span></td>
                <td>{t.version}</td><td>{t.sections_count}</td><td>{t.questions_count}</td>
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
            {items.length === 0 && <tr><td colSpan={6} className="muted">Aún no hay plantillas.</td></tr>}
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

type Flat = { q: Question; si: number; qi: number; n: number };

function Builder({ me, id }: { me: Me; id: string }) {
  const [tpl, setTpl] = useState<Full | null>(null);
  const [meta, setMeta] = useState({ name: '', description: '', pass_threshold: 80, scoring_enabled: false });
  const [structure, setStructure] = useState<Structure>({ sections: [] });
  const [dirty, setDirty] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [logicOpen, setLogicOpen] = useState(false);
  const editable = can(me, 'plantillas_auditoria', 'editar') && tpl?.status !== 'archived';

  const load = useCallback(async () => {
    try {
      const t = await api<Full>('GET', `audit-templates/${id}`);
      setTpl(t); setMeta({ name: t.name, description: t.description ?? '', pass_threshold: t.pass_threshold, scoring_enabled: t.scoring_enabled }); setStructure(t.structure); setDirty(false); setErrors({});
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
  const setMetaField = (patch: Partial<typeof meta>) => { setMeta({ ...meta, ...patch }); setDirty(true); setMsg(null); };

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
  const flat: Flat[] = useMemo(() => structure.sections.flatMap((s, si) => s.questions.map((q, qi) => ({ q, si, qi, n: 0 }))).map((f, i) => ({ ...f, n: i + 1 })), [structure]);
  const allQuestions = useMemo(() => flat.map((f) => f.q), [flat]);
  const numberOf = (qid: string) => flat.find((f) => f.q.id === qid)?.n;
  const withLogic = [
    ...structure.sections.filter((s) => s.show_if).map((s) => ({ key: s.id, what: `Sección «${s.title || 'sin título'}»`, cond: s.show_if })),
    ...flat.filter((f) => f.q.show_if).map((f) => ({ key: f.q.id, what: `Pregunta ${f.n}. ${f.q.text || '(sin texto)'}`, cond: f.q.show_if })),
  ];

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
        <label className="wide"><span>Nombre *</span><input value={meta.name} disabled={!editable} onChange={(e) => setMetaField({ name: e.target.value })} />{errors.name?.map((m) => <small key={m} className="error">{m}</small>)}</label>
        <label className="wide"><span>Descripción</span><textarea rows={2} value={meta.description} disabled={!editable} onChange={(e) => setMetaField({ description: e.target.value })} /></label>
        <label className="check wide"><input type="checkbox" checked={meta.scoring_enabled} disabled={!editable} onChange={(e) => setMetaField({ scoring_enabled: e.target.checked })} />
          Calcular un puntaje de cumplimiento (opcional) <small className="muted">— Déjelo apagado si esta auditoría solo levanta información y registra hallazgos.</small></label>
        {meta.scoring_enabled && (
          <label><span>Porcentaje mínimo para aprobar</span><input type="number" min={1} max={100} value={meta.pass_threshold} disabled={!editable} onChange={(e) => setMetaField({ pass_threshold: Number(e.target.value) })} /></label>
        )}
      </div>
      {err('sections').map((m) => <p key={m} className="error">{m}</p>)}

      {withLogic.length > 0 && (
        <div className="card pad logic">
          <button className="link" onClick={() => setLogicOpen(!logicOpen)}>{logicOpen ? '▼' : '▶'} Árbol de decisiones: {withLogic.length} regla{withLogic.length === 1 ? '' : 's'} de salto</button>
          {logicOpen && <ul>{withLogic.map((l) => <li key={l.key}><strong>{l.what}</strong><br /><span className="muted">se muestra solo si {describeCond(l.cond, allQuestions)}</span></li>)}</ul>}
        </div>
      )}

      {structure.sections.map((s, si) => (
        <div key={s.id} className="card pad section-card">
          <div className="section-head">
            <input className="section-title" value={s.title} placeholder="Título de la sección" disabled={!editable} onChange={(e) => setSection(si, { title: e.target.value })} aria-label={`Título de la sección ${si + 1}`} />
            {meta.scoring_enabled && <label className="inline"><span>Peso</span><input type="number" min={0.1} max={10} step={0.1} value={s.weight} disabled={!editable} onChange={(e) => setSection(si, { weight: Number(e.target.value) })} /></label>}
            {editable && <span className="mini">
              <button onClick={() => change((st) => { move(st.sections, si, -1); return st; })} disabled={si === 0} aria-label="Subir sección">↑</button>
              <button onClick={() => change((st) => { move(st.sections, si, 1); return st; })} disabled={si === structure.sections.length - 1} aria-label="Bajar sección">↓</button>
              <button className="danger" onClick={() => confirm('¿Eliminar la sección y sus preguntas?') && change((st) => { st.sections.splice(si, 1); return st; })}>Eliminar</button>
            </span>}
          </div>
          {[...err(`sections.${si}.title`), ...err(`sections.${si}.questions`)].map((m) => <p key={m} className="error">{m}</p>)}
          <ConditionEditor
            label="Mostrar esta sección…" value={s.show_if} editable={!!editable} questions={flat.filter((f) => f.si < si).map((f) => f.q)} numberOf={numberOf}
            errors={err(`sections.${si}.show_if`)} onChange={(c) => setSection(si, { show_if: c })}
          />

          {s.questions.map((q, qi) => {
            const n = flat.find((f) => f.q.id === q.id)?.n ?? qi + 1;
            return (
              <QuestionEditor
                key={q.id} q={q} n={n} editable={!!editable} scoring={meta.scoring_enabled} errors={(f) => err(`sections.${si}.questions.${qi}.${f}`)}
                earlier={flat.filter((f) => f.si < si || (f.si === si && f.qi < qi)).map((f) => f.q)} numberOf={numberOf} allQuestions={allQuestions}
                onChange={(nq) => setQuestion(si, qi, nq)}
                onMove={(d) => change((st) => { move(st.sections[si].questions, qi, d); return st; })}
                onRemove={() => change((st) => { st.sections[si].questions.splice(qi, 1); return st; })}
                isFirst={qi === 0} isLast={qi === s.questions.length - 1}
              />
            );
          })}
          {editable && <button onClick={() => change((st) => { st.sections[si].questions.push(blankQuestion()); return st; })}>+ Agregar pregunta</button>}
        </div>
      ))}
      {editable && <button className="primary" onClick={() => change((st) => { st.sections.push({ id: newId('s'), title: '', weight: 1, show_if: null, questions: [blankQuestion()] }); return st; })}>+ Agregar sección</button>}
    </section>
  );
}

/**
 * Constructor de condiciones en lenguaje claro: «Mostrar solo si [pregunta] [es / no es / es alguna de] [respuesta]».
 * Se pueden sumar varias y elegir si deben cumplirse todas o alguna.
 */
function ConditionEditor({ label, value, editable, questions, numberOf, errors, onChange }: {
  label: string; value: Cond; editable: boolean; questions: Question[]; numberOf: (id: string) => number | undefined; errors: string[]; onChange: (c: Cond) => void;
}) {
  const controllers = questions.filter((q) => canControl(q.type));
  const on = !!value;
  const conds = value?.conditions ?? [];
  const setCond = (i: number, patch: Partial<NonNullable<Cond>['conditions'][number]>) => onChange({ mode: value!.mode, conditions: conds.map((c, j) => (j === i ? { ...c, ...patch } : c)) });
  const optionsOf = (q?: Question): { value: string | number; label: string }[] =>
    q?.type === 'yes_no' ? Object.entries(yesNoLabels).map(([v, l]) => ({ value: v, label: l }))
      : q?.type === 'scale' ? Array.from({ length: q.scale!.max - q.scale!.min + 1 }, (_, i) => ({ value: q.scale!.min + i, label: String(q.scale!.min + i) }))
        : (q?.options ?? []).map((o) => ({ value: o.id, label: o.label || '(sin texto)' }));

  return (
    <div className="cond">
      <div className="cond-head">
        <strong>{label}</strong>
        <select value={on ? 'if' : 'always'} disabled={!editable || controllers.length === 0} aria-label={label}
          onChange={(e) => onChange(e.target.value === 'if' ? { mode: 'all', conditions: [{ question: controllers[0].id, op: 'equals', value: optionsOf(controllers[0])[0]?.value ?? null }] } : null)}>
          <option value="always">siempre</option>
          <option value="if">solo si…</option>
        </select>
        {controllers.length === 0 && <small className="muted">Disponible cuando haya una pregunta anterior de Sí/No, escala o selección.</small>}
      </div>
      {on && (
        <div className="cond-body">
          {conds.map((c, i) => {
            const ref = controllers.find((q) => q.id === c.question);
            const opts = optionsOf(ref);
            return (
              <div key={i} className="cond-row">
                <span className="muted">{i === 0 ? 'Si' : value!.mode === 'any' ? 'o si' : 'y si'}</span>
                <select value={c.question} disabled={!editable} aria-label="Pregunta de la que depende"
                  onChange={(e) => { const q = controllers.find((x) => x.id === e.target.value); setCond(i, { question: e.target.value, op: 'equals', value: optionsOf(q)[0]?.value ?? null }); }}>
                  {controllers.map((q) => <option key={q.id} value={q.id}>{numberOf(q.id)}. {q.text || '(sin texto)'}</option>)}
                </select>
                <select value={c.op} disabled={!editable} aria-label="Operador" onChange={(e) => setCond(i, { op: e.target.value as CondOp, value: e.target.value === 'any_of' ? [opts[0]?.value] : opts[0]?.value ?? null })}>
                  <option value="equals">es</option><option value="not_equals">no es</option><option value="any_of">es alguna de</option>
                </select>
                {c.op === 'any_of' ? (
                  <span className="cond-multi">{opts.map((o) => {
                    const sel = Array.isArray(c.value) ? (c.value as unknown[]) : [];
                    return <label key={String(o.value)} className="check"><input type="checkbox" checked={sel.includes(o.value)} disabled={!editable} onChange={(e) => setCond(i, { value: e.target.checked ? [...sel, o.value] : sel.filter((x) => x !== o.value) })} />{o.label}</label>;
                  })}</span>
                ) : (
                  <select value={String(c.value ?? '')} disabled={!editable} aria-label="Respuesta" onChange={(e) => setCond(i, { value: opts.find((o) => String(o.value) === e.target.value)?.value ?? e.target.value })}>
                    {opts.map((o) => <option key={String(o.value)} value={String(o.value)}>{o.label}</option>)}
                  </select>
                )}
                {editable && conds.length > 1 && <button onClick={() => onChange({ mode: value!.mode, conditions: conds.filter((_, j) => j !== i) })} aria-label="Quitar condición">✕</button>}
              </div>
            );
          })}
          {editable && (
            <div className="cond-actions">
              {conds.length < 5 && <button onClick={() => onChange({ mode: value!.mode, conditions: [...conds, { question: controllers[0].id, op: 'equals', value: optionsOf(controllers[0])[0]?.value ?? null }] })}>+ Otra condición</button>}
              {conds.length > 1 && (
                <label className="inline"><span>Se muestra si se cumple</span>
                  <select value={value!.mode} onChange={(e) => onChange({ mode: e.target.value as 'all' | 'any', conditions: conds })}><option value="all">todas las condiciones</option><option value="any">alguna de las condiciones</option></select>
                </label>
              )}
            </div>
          )}
          <p className="cond-sentence">{describeCond(value, controllers) ? <>Se mostrará solo si {describeCond(value, controllers)}. <span className="muted">Mientras no se responda esa pregunta, esta no aparece.</span></> : null}</p>
        </div>
      )}
      {errors.map((m) => <small key={m} className="error">{m}</small>)}
    </div>
  );
}

function QuestionEditor({ q, n, editable, scoring, errors, earlier, numberOf, allQuestions, onChange, onMove, onRemove, isFirst, isLast }: {
  q: Question; n: number; editable: boolean; scoring: boolean; errors: (f: string) => string[]; earlier: Question[]; numberOf: (id: string) => number | undefined; allQuestions: Question[];
  onChange: (q: Question) => void; onMove: (d: number) => void; onRemove: () => void; isFirst: boolean; isLast: boolean;
}) {
  const [open, setOpen] = useState(!q.text);
  const set = (patch: Partial<Question>) => onChange({ ...q, ...patch });
  const isEvidence = q.type === 'photo' || q.type === 'signature';
  const hasErrors = ['text', 'type', 'options', 'scale', 'show_if'].some((f) => errors(f).length) || (q.options ?? []).some((_, i) => errors(`options.${i}.label`).length || errors(`options.${i}.score`).length);
  const setOption = (i: number, patch: Partial<Option>) => set({ options: q.options!.map((o, j) => (j === i ? { ...o, ...patch } : o)) });
  const findingOn = q.finding_on ?? [];
  const hasFinding = canFinding(q.type);

  return (
    <div className={`qcard ${hasErrors ? 'has-error' : ''}`}>
      <div className="qhead" onClick={() => setOpen(!open)}>
        <span className="qnum">{n}</span>
        <span className="qtext">{q.text || <em className="muted">Pregunta sin texto</em>}</span>
        <span className="qbadges">
          <span className="badge">{typeLabels[q.type]}</span>
          {q.required && <span className="badge">Obligatoria</span>}
          {q.show_if && <span className="badge medium" title={`Se muestra solo si ${describeCond(q.show_if, allQuestions)}`}>Si {q.show_if.conditions.map((c) => `P${numberOf(c.question) ?? '?'}`).join(q.show_if.mode === 'any' ? ' o ' : ' y ')}</span>}
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
          <label className="check"><input type="checkbox" checked={q.required} disabled={!editable} onChange={(e) => set({ required: e.target.checked })} /> Obligatoria</label>
          {scoring && <label><span>Peso en el puntaje</span><input type="number" min={0.1} max={10} step={0.1} value={q.weight} disabled={!editable} onChange={(e) => set({ weight: Number(e.target.value) })} /></label>}

          {(q.type === 'choice' || q.type === 'multi_choice') && (
            <div className="wide">
              <span className="muted">Opciones{scoring ? ' y puntaje (0–100)' : ''}. Marque las que deben generar un hallazgo.</span>
              {errors('options').map((m) => <small key={m} className="error">{m}</small>)}
              {q.options!.map((o, i) => (
                <div key={o.id} className="optrow">
                  <input value={o.label} placeholder="Texto de la opción" disabled={!editable} onChange={(e) => setOption(i, { label: e.target.value })} aria-label={`Opción ${i + 1}`} />
                  {scoring && <input type="number" min={0} max={100} value={o.score ?? ''} placeholder="Puntaje" disabled={!editable} onChange={(e) => setOption(i, { score: e.target.value === '' ? null : Number(e.target.value) })} aria-label={`Puntaje de la opción ${i + 1}`} />}
                  <label className="check"><input type="checkbox" checked={!!o.finding} disabled={!editable} onChange={(e) => setOption(i, { finding: e.target.checked })} aria-label={`La opción ${i + 1} genera hallazgo`} /> Genera hallazgo</label>
                  {editable && <button onClick={() => set({ options: q.options!.filter((_, j) => j !== i) })} aria-label="Quitar opción">✕</button>}
                  {[...errors(`options.${i}.label`), ...errors(`options.${i}.score`)].map((m) => <small key={m} className="error">{m}</small>)}
                </div>
              ))}
              {editable && <button onClick={() => set({ options: [...q.options!, { id: newId('o'), label: '', score: null, finding: false }] })}>+ Opción</button>}
            </div>
          )}
          {q.type === 'scale' && (
            <div className="wide optrow">
              <label className="inline"><span>Desde</span><input type="number" min={0} max={9} value={q.scale!.min} disabled={!editable} onChange={(e) => set({ scale: { ...q.scale!, min: Number(e.target.value) } })} /></label>
              <label className="inline"><span>Hasta</span><input type="number" min={1} max={10} value={q.scale!.max} disabled={!editable} onChange={(e) => set({ scale: { ...q.scale!, max: Number(e.target.value) } })} /></label>
              <label className="inline"><span>Genera hallazgo si la nota es menor o igual a</span><input type="number" min={q.scale!.min} max={q.scale!.max} value={q.finding_at_or_below ?? ''} placeholder="nunca" disabled={!editable} onChange={(e) => set({ finding_at_or_below: e.target.value === '' ? null : Number(e.target.value) })} /></label>
              {errors('scale').map((m) => <small key={m} className="error">{m}</small>)}
            </div>
          )}
          {q.type === 'number' && (
            <div className="wide optrow">
              <label className="inline"><span>Unidad</span><input value={q.number!.unit ?? ''} disabled={!editable} onChange={(e) => set({ number: { ...q.number!, unit: e.target.value || null } })} placeholder="°C, kg…" /></label>
              {([['min', 'Mínimo permitido'], ['max', 'Máximo permitido'], ['pass_min', 'Rango aceptable desde'], ['pass_max', 'hasta']] as const).map(([k, label]) => (
                <label key={k} className="inline"><span>{label}</span><input type="number" value={q.number![k] ?? ''} disabled={!editable} onChange={(e) => set({ number: { ...q.number!, [k]: e.target.value === '' ? null : Number(e.target.value) } })} /></label>
              ))}
              <small className="muted">Un valor fuera del rango aceptable genera hallazgo.</small>
            </div>
          )}
          {q.type === 'yes_no' && (
            <div className="wide">
              <span className="muted">Genera hallazgo cuando la respuesta sea:</span>
              <div className="optrow">
                {(['yes', 'no', 'na'] as const).map((k) => (
                  <label key={k} className="check"><input type="checkbox" checked={findingOn.includes(k)} disabled={!editable} onChange={(e) => set({ finding_on: e.target.checked ? [...findingOn, k] : findingOn.filter((x) => x !== k) })} />{yesNoLabels[k]}</label>
                ))}
                <small className="muted">{findingOn.length === 0 ? 'Ninguna: sirve para preguntas que solo levantan información o deciden qué sigue.' : ''}</small>
              </div>
            </div>
          )}

          {hasFinding && (
            <label><span>Severidad del hallazgo</span><select value={q.severity} disabled={!editable} onChange={(e) => set({ severity: e.target.value as Question['severity'] })}>{Object.entries(severityLabels).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></label>
          )}
          {!isEvidence && (
            <>
              <label><span>Foto como evidencia</span>
                <select value={q.evidence.photo} disabled={!editable} onChange={(e) => set({ evidence: { ...q.evidence, photo: e.target.value as Question['evidence']['photo'] } })}>
                  {Object.entries(evidenceLabels).map(([k, v]) => <option key={k} value={k} disabled={k === 'on_fail' && !hasFinding}>{k === 'on_fail' ? 'Obligatoria si hay hallazgo' : v}</option>)}
                </select>
              </label>
              {hasFinding && <label className="check"><input type="checkbox" checked={q.evidence.comment_on_fail} disabled={!editable} onChange={(e) => set({ evidence: { ...q.evidence, comment_on_fail: e.target.checked } })} /> Exigir comentario si hay hallazgo</label>}
            </>
          )}

          <div className="wide">
            <ConditionEditor label="Mostrar esta pregunta…" value={q.show_if} editable={editable} questions={earlier} numberOf={numberOf} errors={errors('show_if')} onChange={(c) => set({ show_if: c })} />
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
