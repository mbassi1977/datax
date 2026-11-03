// Tipos y etiquetas compartidas del módulo de auditoría en terreno.
export type QType = 'yes_no' | 'scale' | 'choice' | 'multi_choice' | 'number' | 'text' | 'date' | 'photo' | 'signature' | 'gps';
export type Option = { id: string; label: string; score: number | null; finding?: boolean };
export type CondOp = 'equals' | 'not_equals' | 'any_of';
export type Cond = { mode: 'all' | 'any'; conditions: { question: string; op: CondOp; value: unknown }[] } | null;
export type Question = {
  id: string; text: string; help: string | null; type: QType; required: boolean; weight: number; severity: 'low' | 'medium' | 'high' | 'critical';
  finding_on?: ('yes' | 'no' | 'na')[]; finding_at_or_below?: number | null;
  evidence: { photo: 'none' | 'optional' | 'required' | 'on_fail'; comment_on_fail: boolean };
  show_if: Cond;
  options?: Option[]; scale?: { min: number; max: number };
  number?: { unit: string | null; min: number | null; max: number | null; pass_min: number | null; pass_max: number | null };
};
export type Section = { id: string; title: string; weight: number; show_if: Cond; questions: Question[] };
export type Structure = { sections: Section[] };

export const typeLabels: Record<QType, string> = {
  yes_no: 'Sí / No / N/A', scale: 'Escala', choice: 'Selección única', multi_choice: 'Selección múltiple', number: 'Número', text: 'Texto', date: 'Fecha', photo: 'Foto', signature: 'Firma', gps: 'Ubicación GPS',
};
export const severityLabels = { low: 'Baja', medium: 'Media', high: 'Alta', critical: 'Crítica' } as const;
export const statusLabels: Record<string, string> = {
  scheduled: 'Programada', in_progress: 'En curso', submitted: 'Enviada', approved: 'Aprobada', returned: 'Devuelta', cancelled: 'Cancelada',
  draft: 'Borrador', published: 'Publicada', archived: 'Archivada', open: 'Abierto', closed: 'Cerrado',
};
export const resultLabels: Record<string, string> = { pass: 'Cumple', fail: 'No cumple' };
export const evidenceLabels = { none: 'Sin foto', optional: 'Foto opcional', required: 'Foto obligatoria', on_fail: 'Foto si falla' } as const;

export const newId = (p: string) => `${p}_${Math.random().toString(36).slice(2, 10)}`;

export const blankQuestion = (): Question => ({
  id: newId('q'), text: '', help: null, type: 'yes_no', required: true, weight: 1, severity: 'medium', finding_on: ['no'],
  evidence: { photo: 'none', comment_on_fail: false }, show_if: null,
});

/** Completa los datos propios del tipo al cambiarlo en el editor. */
export function withType(q: Question, type: QType): Question {
  const next: Question = { ...q, type };
  delete next.options; delete next.scale; delete next.number; delete next.finding_on; delete next.finding_at_or_below;
  if (type === 'yes_no') next.finding_on = ['no'];
  if (type === 'choice' || type === 'multi_choice') next.options = q.options?.length ? q.options : [{ id: newId('o'), label: 'Cumple', score: 100, finding: false }, { id: newId('o'), label: 'No cumple', score: 0, finding: true }];
  if (type === 'scale') { next.scale = { min: 1, max: 5 }; next.finding_at_or_below = null; }
  if (type === 'number') next.number = { unit: null, min: null, max: null, pass_min: null, pass_max: null };
  if (type === 'photo' || type === 'signature') next.evidence = { photo: 'none', comment_on_fail: false };
  return next;
}

export const statusClass = (s: string) => (['approved', 'published', 'closed'].includes(s) ? 'completed' : ['cancelled', 'archived'].includes(s) ? '' : ['returned'].includes(s) ? 'failed' : s === 'in_progress' || s === 'submitted' ? 'medium' : '');

/** Los tipos que pueden generar hallazgo según la respuesta. */
export const canFinding = (t: QType) => ['yes_no', 'scale', 'choice', 'multi_choice', 'number'].includes(t);
/** Los tipos que pueden controlar una condición («mostrar si…»). */
export const canControl = (t: QType) => ['yes_no', 'choice', 'multi_choice', 'scale'].includes(t);

type Answers = Record<string, { value?: unknown } | undefined>;
const passes = (c: Cond, answers: Answers, visible: Record<string, boolean>): boolean => {
  if (!c || !c.conditions?.length) return true;
  const results = c.conditions.map((k) => {
    if (!visible[k.question]) return false;                                   // depende de algo oculto: no aplica
    const v = answers[k.question]?.value;
    if (v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)) return false;   // sin responder: aún no aplica
    const have = Array.isArray(v) ? v : [v];
    if (k.op === 'not_equals') return !have.includes(k.value);
    if (k.op === 'any_of') return (Array.isArray(k.value) ? k.value : [k.value]).some((x) => have.includes(x));
    return have.includes(k.value);
  });
  return c.mode === 'any' ? results.some(Boolean) : results.every(Boolean);
};

/** Árbol de decisiones: qué preguntas se muestran según las respuestas (mismo cálculo que el servidor). */
export function visibility(structure: Structure, answers: Answers): Record<string, boolean> {
  const visible: Record<string, boolean> = {};
  for (const s of structure.sections) {
    const ok = passes(s.show_if, answers, visible);
    for (const q of s.questions) visible[q.id] = ok && passes(q.show_if, answers, visible);
  }
  return visible;
}

export const yesNoLabels: Record<string, string> = { yes: 'Sí', no: 'No', na: 'N/A' };

/** Texto legible de una condición: «¿Tiene exhibición?» es Sí. */
export function describeCond(c: Cond, all: Question[]): string {
  if (!c || !c.conditions.length) return '';
  const parts = c.conditions.map((k) => {
    const q = all.find((x) => x.id === k.question);
    const label = (v: unknown) => (q?.type === 'yes_no' ? yesNoLabels[String(v)] ?? String(v) : q?.options?.find((o) => o.id === v)?.label ?? String(v));
    const vals = Array.isArray(k.value) ? k.value.map(label).join(' o ') : label(k.value);
    return `«${q?.text || 'pregunta'}» ${k.op === 'not_equals' ? 'no es' : k.op === 'any_of' ? 'es alguna de' : 'es'} ${vals}`;
  });
  return parts.join(c.mode === 'any' ? ' o ' : ' y ');
}
