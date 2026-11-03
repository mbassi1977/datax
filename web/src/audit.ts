// Tipos y etiquetas compartidas del módulo de auditoría en terreno.
export type QType = 'yes_no' | 'scale' | 'choice' | 'multi_choice' | 'number' | 'text' | 'date' | 'photo' | 'signature' | 'gps';
export type Option = { id: string; label: string; score: number | null };
export type Question = {
  id: string; text: string; help: string | null; type: QType; required: boolean; weight: number; critical: boolean; severity: 'low' | 'medium' | 'high' | 'critical';
  evidence: { photo: 'none' | 'optional' | 'required' | 'on_fail'; comment_on_fail: boolean };
  show_if: { question: string; equals: unknown } | null;
  options?: Option[]; scale?: { min: number; max: number };
  number?: { unit: string | null; min: number | null; max: number | null; pass_min: number | null; pass_max: number | null };
};
export type Section = { id: string; title: string; weight: number; questions: Question[] };
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
  id: newId('q'), text: '', help: null, type: 'yes_no', required: true, weight: 1, critical: false, severity: 'medium',
  evidence: { photo: 'none', comment_on_fail: false }, show_if: null,
});

/** Completa los datos propios del tipo al cambiarlo en el editor. */
export function withType(q: Question, type: QType): Question {
  const next: Question = { ...q, type };
  delete next.options; delete next.scale; delete next.number;
  if (type === 'choice' || type === 'multi_choice') next.options = q.options?.length ? q.options : [{ id: newId('o'), label: 'Cumple', score: 100 }, { id: newId('o'), label: 'No cumple', score: 0 }];
  if (type === 'scale') next.scale = { min: 1, max: 5 };
  if (type === 'number') next.number = { unit: null, min: null, max: null, pass_min: null, pass_max: null };
  if (type === 'photo' || type === 'signature') next.evidence = { photo: 'none', comment_on_fail: false };
  return next;
}

export const statusClass = (s: string) => (['approved', 'published', 'closed'].includes(s) ? 'completed' : ['cancelled', 'archived'].includes(s) ? '' : ['returned'].includes(s) ? 'failed' : s === 'in_progress' || s === 'submitted' ? 'medium' : '');
