import { ChangeEvent, FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, download, Me, upload } from './api';
import { can, Modal } from './ResourcePage';
import { formatDateTime } from './fmt';

type Field = { key: string; label: string; required: boolean; required_unless: string | null };
type Target = { key: string; label: string; fields: Field[] };
type Warn = { row: number; source: string; first_name: string | null; last_name: string | null; second_last_name: string | null; confidence: 'medium' | 'low' | 'manual'; note: string | null };
type Parts = { first_name: string; last_name: string; second_last_name: string };
type Template = { id: string; name: string };
type Upload = { id: string; headers: string[]; preview: string[][]; total_rows: number; suggested_mapping: Record<string, number | null>; templates: Template[] };
type Err = { row: number | null; field: string | null; message: string };
type Job = {
  id: string; target: string; original_name: string; status: string; total_rows: number;
  created_count: number; updated_count: number; skipped_count: number; error_count: number; errors?: Err[]; warnings?: Warn[]; created_at: string;
};

const statusLabel: Record<string, string> = { uploaded: 'Subido', simulated: 'Simulado', completed: 'Completada', rejected: 'Rechazada', failed: 'Fallida' };

export function ImportsPage({ me }: { me: Me }) {
  const [targets, setTargets] = useState<Target[]>([]);
  const [orgs, setOrgs] = useState<any[]>([]);
  const [history, setHistory] = useState<Job[]>([]);
  const [detail, setDetail] = useState<Job | null>(null);
  const [error, setError] = useState('');
  const [up, setUp] = useState<(Upload & { target: Target; file: string }) | null>(null);

  const loadHistory = useCallback(() => { api<{ data: Job[] }>('GET', 'imports').then((r) => setHistory(r.data)).catch((e) => setError(e.message)); }, []);
  useEffect(() => {
    loadHistory();
    api<Target[]>('GET', 'imports/targets').then(setTargets).catch((e) => setError(e.message));
    api<{ data: any[] }>('GET', 'organizations').then((r) => setOrgs(r.data)).catch(() => {});
  }, [loadHistory]);

  const open = async (id: string) => { try { setDetail(await api<Job>('GET', `imports/${id}`)); } catch (e) { setError((e as Error).message); } };

  return (
    <section>
      <header className="bar"><h1>Importaciones</h1></header>
      {error && <p className="error">{error}</p>}
      {!up && can(me, 'importaciones', 'crear') && <StartCard targets={targets} onUploaded={setUp} />}
      {up && <Wizard up={up} orgs={orgs} onClose={() => { setUp(null); loadHistory(); }} />}

      <h2 className="section">Historial</h2>
      <div className="card table-wrap">
        <table>
          <thead><tr><th>Fecha</th><th>Archivo</th><th>Destino</th><th>Estado</th><th>Nuevos</th><th>Actualizados</th><th>Omitidos</th><th>Con error</th><th /></tr></thead>
          <tbody>
            {history.map((j) => (
              <tr key={j.id}>
                <td>{formatDateTime(j.created_at)}</td><td>{j.original_name}</td>
                <td>{targets.find((t) => t.key === j.target)?.label ?? j.target}</td>
                <td><span className={`badge ${j.status}`}>{statusLabel[j.status] ?? j.status}</span></td>
                <td>{j.created_count}</td><td>{j.updated_count}</td><td>{j.skipped_count}</td><td>{j.error_count}</td>
                <td className="actions"><button onClick={() => open(j.id)}>Ver detalle</button></td>
              </tr>
            ))}
            {history.length === 0 && <tr><td colSpan={9} className="muted">Aún no hay importaciones.</td></tr>}
          </tbody>
        </table>
      </div>
      {detail && (
        <Modal title={`Detalle · ${detail.original_name}`} onClose={() => setDetail(null)}>
          <Summary job={detail} />
          <ErrorTable errors={detail.errors ?? []} target={targets.find((t) => t.key === detail.target)} />
        </Modal>
      )}
    </section>
  );
}

function StartCard({ targets, onUploaded }: { targets: Target[]; onUploaded: (u: Upload & { target: Target; file: string }) => void }) {
  const [target, setTarget] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [templates, setTemplates] = useState<(Template & { target: string })[]>([]);
  const [tpl, setTpl] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const t = targets.find((x) => x.key === (target || targets[0]?.key));
  const mine = templates.filter((x) => x.target === t?.key);
  useEffect(() => { api<(Template & { target: string })[]>('GET', 'import-templates').then(setTemplates).catch(() => {}); }, []);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!t || !file) return;
    setBusy(true); setError('');
    const form = new FormData();
    form.append('target', t.key); form.append('file', file);
    if (tpl && mine.some((x) => x.id === tpl)) form.append('template_id', tpl);
    try {
      const r = await upload<Upload>('imports', form);
      onUploaded({ ...r, target: t, file: file.name });
    } catch (e2) { setError((e2 as ApiError).message); } finally { setBusy(false); }
  };

  if (targets.length === 0) return <p className="muted">Su perfil no permite importar datos.</p>;
  return (
    <form className="card pad form" onSubmit={submit}>
      <h2 className="wide">Nueva importación</h2>
      <label><span>¿Qué desea importar?</span>
        <select value={t?.key ?? ''} onChange={(e) => setTarget(e.target.value)}>{targets.map((x) => <option key={x.key} value={x.key}>{x.label}</option>)}</select>
      </label>
      <label><span>Archivo (.xlsx o .csv, máx. 5 MB y 5.000 filas)</span>
        <input type="file" accept=".xlsx,.csv,.txt" onChange={(e: ChangeEvent<HTMLInputElement>) => setFile(e.target.files?.[0] ?? null)} required />
      </label>
      {mine.length > 0 && (
        <label><span>Usar una relación de columnas guardada (opcional)</span>
          <select value={tpl} onChange={(e) => setTpl(e.target.value)}><option value="">— detectar automáticamente —</option>{mine.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
        </label>
      )}
      <div className="wide muted">
        La primera fila debe tener los nombres de las columnas; no es necesario que coincidan con los de HRX, en el siguiente paso las relaciona.{' '}
        {t && <button type="button" className="link" onClick={() => download(`imports/targets/${t.key}/template`, `plantilla-${t.key}.csv`)}>Descargar plantilla de ejemplo</button>}
      </div>
      {error && <p className="error wide">{error}</p>}
      <div className="wide buttons"><button className="primary" disabled={busy || !file}>{busy ? 'Leyendo archivo…' : 'Continuar'}</button></div>
    </form>
  );
}

function Wizard({ up, orgs, onClose }: { up: Upload & { target: Target; file: string }; orgs: any[]; onClose: () => void }) {
  const [mapping, setMapping] = useState<Record<string, number | null>>(up.suggested_mapping);
  const [updateExisting, setUpdateExisting] = useState(false);
  const [onError, setOnError] = useState<'skip' | 'abort'>('skip');
  const [org, setOrg] = useState('');
  const [saveAs, setSaveAs] = useState('');
  const [job, setJob] = useState<Job | null>(null);
  const [nameFormat, setNameFormat] = useState('auto');
  const [overrides, setOverrides] = useState<Record<number, Parts>>({});
  const [stale, setStale] = useState(false); // hay correcciones sin simular
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const done = job?.status === 'completed' || job?.status === 'rejected';
  const isPeople = up.target.key === 'people';

  const setMap = (field: string, v: string) => { setMapping((m) => ({ ...m, [field]: v === '' ? null : Number(v) })); setJob(null); };
  const missing = up.target.fields.filter((f) => f.required && mapping[f.key] == null && !(f.required_unless && mapping[f.required_unless] != null));
  const isRequired = (f: Field) => f.required && !(f.required_unless && mapping[f.required_unless] != null);
  const hasFullName = mapping.full_name != null;

  const run = async (mode: 'simulate' | 'commit') => {
    setBusy(true); setError('');
    try {
      setJob(await api<Job>('POST', `imports/${up.id}/run`, {
        mode, mapping, update_existing: updateExisting, on_error: onError, organization_id: org || null, save_template_as: mode === 'commit' && saveAs ? saveAs : null,
        name_format: nameFormat, name_overrides: overrides,
      }));
      setStale(false);
    } catch (e) {
      const ae = e as ApiError;
      setError(Object.values(ae.errors).flat().join(' ') || ae.message);
    } finally { setBusy(false); }
  };

  return (
    <div className="card pad">
      <div className="bar">
        <h2>{up.target.label} · {up.file}</h2><span className="muted">{up.total_rows} filas de datos</span>
        <button onClick={onClose}>{done ? 'Cerrar' : 'Cancelar'}</button>
      </div>

      <h3>1. Relacione las columnas</h3>
      <div className="table-wrap">
        <table>
          <thead><tr><th>Campo en HRX</th><th>Columna de su archivo</th><th>Ejemplo</th></tr></thead>
          <tbody>
            {up.target.fields.map((f) => (
              <tr key={f.key}>
                <td>{f.label}{isRequired(f) && ' *'}</td>
                <td>
                  <select value={mapping[f.key] ?? ''} onChange={(e) => setMap(f.key, e.target.value)} disabled={done}>
                    <option value="">— no importar —</option>
                    {up.headers.map((h, i) => <option key={i} value={i}>{h || `(columna ${i + 1})`}</option>)}
                  </select>
                </td>
                <td className="muted">{mapping[f.key] != null ? up.preview[0]?.[mapping[f.key]!] ?? '' : ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <h3>2. Opciones</h3>
      <div className="form">
        {isPeople && (
          <label><span>Organización por defecto (para filas sin empresa)</span>
            <select value={org} onChange={(e) => { setOrg(e.target.value); setJob(null); }} disabled={done}>
              <option value="">— ninguna —</option>{orgs.map((o) => <option key={o.id} value={o.id}>{o.legal_name}</option>)}
            </select>
          </label>
        )}
        {hasFullName && (
          <label><span>Orden del nombre completo</span>
            <select value={nameFormat} onChange={(e) => { setNameFormat(e.target.value); setJob(null); }} disabled={done}>
              <option value="auto">Detectar automáticamente</option>
              <option value="names_first">Nombres y luego apellidos (Ana María Pérez Soto)</option>
              <option value="surnames_first">Apellidos y luego nombres (Pérez Soto Ana María)</option>
              <option value="comma">Con coma (Pérez Soto, Ana María)</option>
            </select>
          </label>
        )}
        <label className="check wide"><input type="checkbox" checked={updateExisting} disabled={done} onChange={(e) => { setUpdateExisting(e.target.checked); setJob(null); }} />
          Actualizar los registros que ya existen (por RUT). Solo se modifican las columnas relacionadas y con valor.</label>
        <label><span>Si hay filas con error</span>
          <select value={onError} onChange={(e) => { setOnError(e.target.value as 'skip' | 'abort'); setJob(null); }} disabled={done}>
            <option value="skip">Importar las filas correctas y omitir las erróneas</option>
            <option value="abort">No importar nada si hay algún error</option>
          </select>
        </label>
        <label><span>Guardar esta relación de columnas como (opcional)</span><input value={saveAs} onChange={(e) => setSaveAs(e.target.value)} placeholder="Ej.: Nómina RRHH" disabled={done} /></label>
      </div>

      <h3>3. Vista previa del archivo</h3>
      <div className="table-wrap"><table>
        <thead><tr>{up.headers.map((h, i) => <th key={i}>{h}</th>)}</tr></thead>
        <tbody>{up.preview.map((r, i) => <tr key={i}>{up.headers.map((_, j) => <td key={j}>{r[j] ?? ''}</td>)}</tr>)}</tbody>
      </table></div>

      {error && <p className="error">{error}</p>}
      {missing.length > 0 && <p className="error">Falta relacionar: {missing.map((f) => f.label).join(', ')}.</p>}

      {job && <><h3>{done ? 'Resultado' : 'Resultado de la simulación'}</h3><Summary job={job} />
        {!done && (job.warnings?.length ?? 0) > 0 && (
          <NameReview warnings={job.warnings!} overrides={overrides} onChange={(row, parts) => { setOverrides((o) => ({ ...o, [row]: parts })); setStale(true); }} />
        )}
        <ErrorTable errors={job.errors ?? []} target={up.target} /></>}

      {!done && (
        <div className="buttons">
          <button disabled={busy || missing.length > 0} onClick={() => run('simulate')}>{busy ? 'Procesando…' : 'Simular (no guarda nada)'}</button>
          <button className="primary" disabled={busy || stale || missing.length > 0 || !job || job.status !== 'simulated' || (onError === 'abort' && job.error_count > 0) || (job.created_count + job.updated_count === 0)}
            onClick={() => run('commit')}>Importar</button>
        </div>
      )}
      {stale && <p className="muted">Hay correcciones de nombres sin aplicar: pulse «Simular» de nuevo para revisarlas antes de importar.</p>}
      {!done && !job && <small className="muted">Primero simule para revisar errores; después se habilita «Importar».</small>}
      {job?.status === 'completed' && <p className="ok">Importación completada. {job.created_count} nuevos, {job.updated_count} actualizados.</p>}
      {job?.status === 'rejected' && <p className="error">No se importó nada porque hay filas con error.</p>}
    </div>
  );
}

function Summary({ job }: { job: Job }) {
  const items: [string, number, string][] = [['Nuevos', job.created_count, 'ok'], ['Actualizados', job.updated_count, ''], ['Omitidos (ya existen)', job.skipped_count, ''], ['Con error', job.error_count, job.error_count ? 'bad' : '']];
  return <div className="stats">{items.map(([l, n, c]) => <div key={l} className={`stat ${c}`}><strong>{n}</strong><span>{l}</span></div>)}</div>;
}

function ErrorTable({ errors, target }: { errors: Err[]; target?: Target }) {
  if (errors.length === 0) return null;
  const label = (f: string | null) => (f ? target?.fields.find((x) => x.key === f)?.label ?? f : 'Fila completa');
  return (
    <div className="table-wrap">
      <table>
        <thead><tr><th>Fila</th><th>Campo</th><th>Problema</th></tr></thead>
        <tbody>{errors.map((e, i) => <tr key={i}><td>{e.row ?? '—'}</td><td>{label(e.field)}</td><td className="wrap">{e.message}</td></tr>)}</tbody>
      </table>
      {errors.length >= 500 && <small className="muted">Se muestran los primeros 500 problemas.</small>}
    </div>
  );
}

/** Tabla de revisión de nombres separados con baja/media confianza; permite corregirlos antes de importar. */
function NameReview({ warnings, overrides, onChange }: { warnings: Warn[]; overrides: Record<number, Parts>; onChange: (row: number, p: Parts) => void }) {
  const val = (w: Warn): Parts => overrides[w.row] ?? { first_name: w.first_name ?? '', last_name: w.last_name ?? '', second_last_name: w.second_last_name ?? '' };
  const input = (w: Warn, k: keyof Parts, label: string) => (
    <input aria-label={`${label} (fila ${w.row})`} value={val(w)[k]} onChange={(e) => onChange(w.row, { ...val(w), [k]: e.target.value })} />
  );
  return (
    <div className="review">
      <h3>Revise la separación de nombres</h3>
      <p className="muted">
        <span className="badge failed">Baja</span> no se importa hasta que la corrija. <span className="badge medium">Media</span> se importa tal como aparece aquí; <span className="badge completed">Corregida</span> usa su corrección;
        edite lo que esté mal y pulse «Simular» de nuevo. Las filas de confianza alta no se muestran.
      </p>
      <div className="table-wrap">
        <table>
          <thead><tr><th>Fila</th><th>Como venía</th><th>Nombres</th><th>Ap. paterno</th><th>Ap. materno</th><th>Confianza</th></tr></thead>
          <tbody>
            {warnings.map((w) => (
              <tr key={w.row}>
                <td>{w.row}</td><td>{w.source}</td>
                <td>{input(w, 'first_name', 'Nombres')}</td><td>{input(w, 'last_name', 'Apellido paterno')}</td><td>{input(w, 'second_last_name', 'Apellido materno')}</td>
                <td><span className={`badge ${w.confidence === 'low' ? 'failed' : w.confidence === 'manual' ? 'completed' : 'medium'}`}>{w.confidence === 'low' ? 'Baja' : w.confidence === 'manual' ? 'Corregida' : 'Media'}</span>{w.note && <small className="muted"> {w.note}</small>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
