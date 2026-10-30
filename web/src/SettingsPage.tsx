import { useCallback, useEffect, useMemo, useState } from 'react';
import { api, ApiError, Me, upload } from './api';
import { can } from './ResourcePage';
import { formatDateTime } from './fmt';

type Def = { key: string; type: 'string' | 'color' | 'timezone' | 'enum' | 'int' | 'bool'; label: string; group: string; help?: string; min?: number; max?: number; options?: string[]; value: unknown; inherited: unknown };
type View = { organization_id: string | null; definitions: Def[]; logo: { url: string | null; inherited_url: string | null } };
type Org = { id: string; legal_name: string; trade_name?: string | null };

const groups: Record<string, string> = { brand: 'Identidad visual', locale: 'Regional', security: 'Seguridad' };
const dateLabels: Record<string, string> = { 'dd/mm/yyyy': 'dd/mm/aaaa (31/12/2026)', 'yyyy-mm-dd': 'aaaa-mm-dd (2026-12-31)', 'mm/dd/yyyy': 'mm/dd/aaaa (12/31/2026)' };
const zones: string[] = (() => { try { return (Intl as any).supportedValuesOf('timeZone') as string[]; } catch { return ['America/Santiago', 'America/Punta_Arenas', 'Pacific/Easter', 'UTC']; } })();

export function SettingsPage({ me, reload }: { me: Me; reload: () => void }) {
  const [orgs, setOrgs] = useState<Org[]>([]);
  const [scope, setScope] = useState('');             // '' = general (tenant)
  const [view, setView] = useState<View | null>(null);
  const [draft, setDraft] = useState<Record<string, unknown>>({});
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const canEdit = can(me, 'configuracion', 'editar');

  useEffect(() => { api<{ data: Org[] }>('GET', 'organizations').then((r) => setOrgs(r.data)).catch(() => {}); }, []);

  // keepDraft: al subir el logo se refresca la vista sin perder los cambios que aún no se han guardado.
  const load = useCallback(async (keepDraft = false) => {
    try {
      const v = await api<View>('GET', `settings${scope ? `?organization_id=${scope}` : ''}`);
      setView(v); if (!keepDraft) setDraft(Object.fromEntries(v.definitions.map((d) => [d.key, d.value])));
      setMsg(null);
    } catch (e) { setMsg({ ok: false, text: (e as Error).message }); setView(null); }
  }, [scope]);
  useEffect(() => { load(); }, [load]);

  const dirty = useMemo(() => view?.definitions.filter((d) => JSON.stringify(draft[d.key] ?? null) !== JSON.stringify(d.value ?? null)) ?? [], [view, draft]);

  const save = async () => {
    setBusy(true); setMsg(null);
    try {
      await api('PUT', 'settings', { organization_id: scope || null, values: Object.fromEntries(dirty.map((d) => [d.key, draft[d.key] ?? null])) });
      await load(); reload(); setMsg({ ok: true, text: 'Configuración guardada.' });
    } catch (e) { const ae = e as ApiError; setMsg({ ok: false, text: Object.values(ae.errors).flat().join(' ') || ae.message }); } finally { setBusy(false); }
  };

  const logoAction = async (file?: File) => {
    setBusy(true); setMsg(null);
    try {
      if (file) { const f = new FormData(); f.append('file', file); if (scope) f.append('organization_id', scope); await upload('settings/logo', f); }
      else await api('DELETE', `settings/logo${scope ? `?organization_id=${scope}` : ''}`);
      await load(true); reload();
    } catch (e) { setMsg({ ok: false, text: (e as Error).message }); } finally { setBusy(false); }
  };

  const shown = (d: Def, v: unknown) => (v === null || v === undefined || v === '' ? '—' : d.type === 'bool' ? (v ? 'Sí' : 'No') : d.type === 'enum' ? dateLabels[String(v)] ?? String(v) : String(v));

  const control = (d: Def) => {
    const v = draft[d.key];
    const off = !canEdit || busy;
    switch (d.type) {
      case 'color': return <span className="colorrow"><input type="color" value={String(v)} disabled={off} onChange={(e) => setDraft({ ...draft, [d.key]: e.target.value })} /><code>{String(v)}</code></span>;
      case 'timezone': return <select value={String(v)} disabled={off} onChange={(e) => setDraft({ ...draft, [d.key]: e.target.value })}>{zones.map((z) => <option key={z}>{z}</option>)}</select>;
      case 'enum': return <select value={String(v)} disabled={off} onChange={(e) => setDraft({ ...draft, [d.key]: e.target.value })}>{d.options!.map((o) => <option key={o} value={o}>{dateLabels[o] ?? o}</option>)}</select>;
      case 'int': return <input type="number" min={d.min} max={d.max} value={Number(v)} disabled={off} onChange={(e) => setDraft({ ...draft, [d.key]: Number(e.target.value) })} />;
      case 'bool': return <label className="check"><input type="checkbox" checked={Boolean(v)} disabled={off} onChange={(e) => setDraft({ ...draft, [d.key]: e.target.checked })} />{Boolean(v) ? 'Sí' : 'No'}</label>;
      default: return <input value={String(v ?? '')} maxLength={d.max} disabled={off} onChange={(e) => setDraft({ ...draft, [d.key]: e.target.value })} />;
    }
  };

  const row = (d: Def) => {
    const own = draft[d.key] !== null && draft[d.key] !== undefined;
    return (
      <div key={d.key} className="setting">
        <div className="head"><strong>{d.label}</strong>{d.help && <small className="muted">{d.help}</small>}</div>
        {own ? (
          <div className="ctl">{control(d)}{canEdit && <button type="button" className="link" onClick={() => setDraft({ ...draft, [d.key]: null })}>{scope ? 'Usar el valor general' : 'Restablecer valor por defecto'}</button>}</div>
        ) : (
          <div className="ctl inherit">
            <span className="muted">{scope ? 'General' : 'Por defecto'}: <strong>{shown(d, d.inherited)}</strong></span>
            {canEdit && <button type="button" onClick={() => setDraft({ ...draft, [d.key]: d.inherited ?? (d.type === 'string' ? '' : d.type === 'bool' ? false : d.min ?? 0) })}>Personalizar</button>}
          </div>
        )}
      </div>
    );
  };

  return (
    <section>
      <header className="bar"><h1>Configuración</h1></header>
      <p className="muted">Los parámetros generales rigen para todas las empresas. Cada empresa puede personalizar los suyos; lo que no personalice, lo hereda del nivel general.</p>
      <div className="card pad scopebar">
        <label><span>Ámbito</span>
          <select value={scope} onChange={(e) => setScope(e.target.value)}>
            <option value="">General (toda la plataforma)</option>
            {orgs.map((o) => <option key={o.id} value={o.id}>Empresa: {o.trade_name || o.legal_name}</option>)}
          </select>
        </label>
      </div>
      {msg && <p className={msg.ok ? 'ok' : 'error'}>{msg.text}</p>}
      {view && Object.entries(groups).map(([g, title]) => (
        <div key={g} className="card pad group-card">
          <h2>{title}</h2>
          {g === 'brand' && (
            <div className="setting">
              <div className="head"><strong>Logo</strong><small className="muted">PNG, JPG, WebP o SVG, hasta 1 MB. Los raster se reducen a 512 px.</small></div>
              <div className="ctl">
                {(view.logo.url ?? view.logo.inherited_url) ? <img src={(view.logo.url ?? view.logo.inherited_url)!} alt="Logo" className="logo-preview" /> : <span className="muted">Sin logo</span>}
                {!view.logo.url && view.logo.inherited_url && <span className="muted">(heredado del nivel general)</span>}
                {canEdit && <label className="btn">Subir logo<input type="file" hidden accept=".png,.jpg,.jpeg,.webp,.svg" disabled={busy} onChange={(e) => { logoAction(e.target.files?.[0]); e.target.value = ''; }} /></label>}
                {canEdit && view.logo.url && <button disabled={busy} onClick={() => logoAction()}>Quitar</button>}
              </div>
            </div>
          )}
          {view.definitions.filter((d) => d.group === g).map(row)}
          {g === 'locale' && <small className="muted">Hora actual en la zona elegida: {formatDateTime(new Date().toISOString())}</small>}
        </div>
      ))}
      {canEdit && view && (
        <div className="buttons stickybar">
          <button onClick={() => view && setDraft(Object.fromEntries(view.definitions.map((d) => [d.key, d.value])))} disabled={dirty.length === 0 || busy}>Descartar cambios</button>
          <button className="primary" onClick={save} disabled={dirty.length === 0 || busy}>{busy ? 'Guardando…' : `Guardar${dirty.length ? ` (${dirty.length})` : ''}`}</button>
        </div>
      )}
    </section>
  );
}
