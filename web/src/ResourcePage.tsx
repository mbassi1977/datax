import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me } from './api';
import type { Field, Resource } from './resources';
import { ProfilesPanel } from './ProfilesPanel';

type Page = { data: any[]; current_page: number; last_page: number; total: number };

export function can(me: Me, perm: string, action: string) {
  return me.is_superadmin || me.permissions.includes(`${perm}.${action}`);
}

export function ResourcePage({ res, me }: { res: Resource; me: Me }) {
  const [page, setPage] = useState<Page | null>(null);
  const [q, setQ] = useState('');
  const [n, setN] = useState(1);
  const [error, setError] = useState('');
  const [refs, setRefs] = useState<Record<string, any[]>>({});
  const [editing, setEditing] = useState<{ row: any | null } | null>(null);
  const [profilesFor, setProfilesFor] = useState<any | null>(null);

  const load = useCallback(async () => {
    setError('');
    try {
      setPage(await api<Page>('GET', `${res.path}?page=${n}${q ? `&q=${encodeURIComponent(q)}` : ''}`));
    } catch (e) { setError((e as Error).message); }
  }, [res.path, n, q]);

  useEffect(() => { setN(1); setQ(''); setPage(null); }, [res.id]);
  useEffect(() => { load(); }, [load]);

  // Listas de referencia (organizaciones, áreas, personas...) para selects y columnas.
  useEffect(() => {
    const paths = new Set(['organizations']);
    res.fields.forEach((f) => f.ref && paths.add(f.ref.path));
    Promise.all([...paths].map(async (p) => {
      try { return [p, (await api<Page>('GET', p)).data] as const; } catch { return [p, []] as const; }
    })).then((e) => setRefs(Object.fromEntries(e)));
  }, [res.id]);

  const remove = async (row: any) => {
    if (!confirm('¿Eliminar este registro?')) return;
    try { await api('DELETE', `${res.path}/${row.id}`); load(); } catch (e) { setError((e as Error).message); }
  };

  return (
    <section>
      <header className="bar">
        <h1>{res.title}</h1>
        <form onSubmit={(e) => { e.preventDefault(); setN(1); load(); }} className="search">
          <input placeholder="Buscar…" value={q} onChange={(e) => setQ(e.target.value)} />
        </form>
        {can(me, res.perm, 'crear') && <button className="primary" onClick={() => setEditing({ row: null })}>+ Nuevo</button>}
      </header>
      {error && <p className="error">{error}</p>}
      <div className="card table-wrap">
        <table>
          <thead><tr>{res.columns.map((c) => <th key={c.key}>{c.label}</th>)}<th /></tr></thead>
          <tbody>
            {page?.data.map((r) => (
              <tr key={r.id}>
                {res.columns.map((c) => <td key={c.key}>{c.render ? c.render(r, refs) : String(r[c.key] ?? '—')}</td>)}
                <td className="actions">
                  {res.profilesPanel && can(me, res.perm, 'ver') && <button onClick={() => setProfilesFor(r)}>Perfiles</button>}
                  {can(me, res.perm, 'editar') && <button onClick={() => setEditing({ row: r })}>Editar</button>}
                  {can(me, res.perm, 'eliminar') && <button className="danger" onClick={() => remove(r)}>Eliminar</button>}
                </td>
              </tr>
            ))}
            {page && page.data.length === 0 && <tr><td colSpan={res.columns.length + 1} className="muted">Sin registros.</td></tr>}
            {!page && !error && <tr><td colSpan={res.columns.length + 1} className="muted">Cargando…</td></tr>}
          </tbody>
        </table>
      </div>
      {page && page.last_page > 1 && (
        <div className="pager">
          <button disabled={n <= 1} onClick={() => setN(n - 1)}>←</button>
          <span>Página {page.current_page} de {page.last_page} · {page.total} registros</span>
          <button disabled={n >= page.last_page} onClick={() => setN(n + 1)}>→</button>
        </div>
      )}
      {editing && <FormModal res={res} row={editing.row} refs={refs} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
      {profilesFor && <ProfilesPanel user={profilesFor} orgs={refs.organizations ?? []} onClose={() => setProfilesFor(null)} />}
    </section>
  );
}

function initial(f: Field, row: any | null): any {
  if (f.type === 'tags') return row?.[f.key] ?? [];
  if (f.type === 'date') return row?.[f.key]?.slice(0, 10) ?? '';
  if (row) return row[f.key] ?? '';
  return f.type === 'select' ? f.options![0][0] : '';
}

function FormModal({ res, row, refs, onClose, onSaved }: { res: Resource; row: any | null; refs: Record<string, any[]>; onClose: () => void; onSaved: () => void }) {
  const [v, setV] = useState<Record<string, any>>(() => Object.fromEntries(res.fields.map((f) => [f.key, initial(f, row)])));
  const [err, setErr] = useState<{ msg: string; fields: Record<string, string[]> } | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true); setErr(null);
    const body: Record<string, any> = {};
    for (const f of res.fields) {
      if (f.type === 'password' && v[f.key] === '' && row) continue; // no cambiar la clave
      body[f.key] = v[f.key] === '' ? null : v[f.key];
    }
    try {
      await (row ? api('PUT', `${res.path}/${row.id}`, body) : api('POST', res.path, body));
      onSaved();
    } catch (e2) {
      const ae = e2 as ApiError;
      setErr({ msg: ae.message, fields: ae.errors ?? {} });
    } finally { setBusy(false); }
  };

  const set = (k: string, val: any) => setV((s) => ({ ...s, [k]: val }));

  return (
    <Modal title={`${row ? 'Editar' : 'Nueva'} · ${res.title}`} onClose={onClose}>
      <form onSubmit={submit} className="form">
        {res.fields.map((f) => (
          <label key={f.key} className={f.type === 'textarea' || f.type === 'tags' ? 'wide' : ''}>
            <span>{f.label}{f.required && ' *'}</span>
            {f.type === 'select' ? (
              <select value={v[f.key]} onChange={(e) => set(f.key, e.target.value)}>{f.options!.map(([val, lab]) => <option key={val} value={val}>{lab}</option>)}</select>
            ) : f.type === 'ref' ? (
              <select value={v[f.key] ?? ''} required={f.required} onChange={(e) => set(f.key, e.target.value)}>
                <option value="">— ninguna —</option>
                {(refs[f.ref!.path] ?? []).filter((r) => r.id !== row?.id).map((r) => <option key={r.id} value={r.id}>{f.ref!.label(r)}</option>)}
              </select>
            ) : f.type === 'tags' ? (
              <div className="tags">{f.options!.map(([val, lab]) => (
                <label key={val} className="check"><input type="checkbox" checked={v[f.key].includes(val)} onChange={(e) => set(f.key, e.target.checked ? [...v[f.key], val] : v[f.key].filter((x: string) => x !== val))} />{lab}</label>
              ))}</div>
            ) : f.type === 'textarea' ? (
              <textarea rows={3} value={v[f.key] ?? ''} onChange={(e) => set(f.key, e.target.value)} />
            ) : (
              <input type={f.type ?? 'text'} value={v[f.key] ?? ''} required={f.required || (f.type === 'password' && !row)} autoComplete="off" onChange={(e) => set(f.key, e.target.value)} />
            )}
            {f.help && <small className="muted">{f.help}</small>}
            {err?.fields[f.key]?.map((m) => <small key={m} className="error">{m}</small>)}
          </label>
        ))}
        {err && Object.keys(err.fields).length === 0 && <p className="error wide">{err.msg}</p>}
        <div className="wide buttons">
          <button type="button" onClick={onClose}>Cancelar</button>
          <button className="primary" disabled={busy}>{busy ? 'Guardando…' : 'Guardar'}</button>
        </div>
      </form>
    </Modal>
  );
}

export function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  useEffect(() => {
    const h = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [onClose]);
  return (
    <div className="overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className="modal card" role="dialog" aria-label={title}>
        <header><h2>{title}</h2><button onClick={onClose} aria-label="Cerrar">✕</button></header>
        {children}
      </div>
    </div>
  );
}
