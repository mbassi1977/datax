import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError } from './api';
import { Modal } from './ResourcePage';

type Assignment = { id: string; code: string; name: string; scope_type: string; scope_id: string | null; valid_from: string | null; valid_until: string | null };
type Profile = { code: string; name: string; permissions: string[] };

export function ProfilesPanel({ user, orgs, onClose }: { user: any; orgs: any[]; onClose: () => void }) {
  const [items, setItems] = useState<Assignment[]>([]);
  const [profiles, setProfiles] = useState<Profile[]>([]);
  const [holdings, setHoldings] = useState<{ id: string; name: string }[]>([]);
  const [form, setForm] = useState({ profile: '', scope_type: 'global', scope_id: '', valid_until: '' });
  const [error, setError] = useState('');

  const load = useCallback(async () => {
    try { setItems(await api('GET', `users/${user.id}/profiles`)); } catch (e) { setError((e as Error).message); }
  }, [user.id]);

  useEffect(() => {
    load();
    api<{ data: { id: string; name: string }[] }>('GET', 'holdings').then((r) => setHoldings(r.data)).catch(() => {});
    api<Profile[]>('GET', 'profiles').then((p) => { setProfiles(p); setForm((f) => ({ ...f, profile: p[0]?.code ?? '' })); }).catch(() => {});
  }, [load]);

  const orgName = (id: string | null) => orgs.find((o) => o.id === id)?.legal_name ?? id ?? '';
  const holdingName = (id: string | null) => holdings.find((h) => h.id === id)?.name ?? id ?? '';
  const scopeLabel = (a: Assignment) => (a.scope_type === 'global' ? 'Global' : a.scope_type === 'holding' ? `Holding: ${holdingName(a.scope_id)}` : `Organización: ${orgName(a.scope_id)}`);

  const add = async (e: FormEvent) => {
    e.preventDefault(); setError('');
    try {
      await api('POST', `users/${user.id}/profiles`, {
        profile: form.profile, scope_type: form.scope_type,
        scope_id: form.scope_type === 'global' ? null : form.scope_id,
        valid_until: form.valid_until || null,
      });
      load();
    } catch (e2) {
      const ae = e2 as ApiError;
      setError(Object.values(ae.errors).flat().join(' ') || ae.message);
    }
  };

  const revoke = async (a: Assignment) => {
    try { await api('DELETE', `users/${user.id}/profiles/${a.id}`); load(); } catch (e) { setError((e as Error).message); }
  };

  return (
    <Modal title={`Perfiles de ${user.email}`} onClose={onClose}>
      {error && <p className="error">{error}</p>}
      <table>
        <thead><tr><th>Perfil</th><th>Alcance</th><th>Vigencia</th><th /></tr></thead>
        <tbody>
          {items.map((a) => (
            <tr key={a.id}><td>{a.name}</td><td>{scopeLabel(a)}</td><td>{a.valid_until ? `hasta ${a.valid_until.slice(0, 10)}` : 'Indefinida'}</td>
              <td className="actions"><button className="danger" onClick={() => revoke(a)}>Quitar</button></td></tr>
          ))}
          {items.length === 0 && <tr><td colSpan={4} className="muted">Sin perfiles asignados.</td></tr>}
        </tbody>
      </table>
      <h3>Asignar perfil</h3>
      <form onSubmit={add} className="form">
        <label><span>Perfil</span>
          <select value={form.profile} onChange={(e) => setForm({ ...form, profile: e.target.value })}>{profiles.map((p) => <option key={p.code} value={p.code}>{p.name}</option>)}</select>
        </label>
        <label><span>Alcance</span>
          <select value={form.scope_type} onChange={(e) => setForm({ ...form, scope_type: e.target.value })}>
            <option value="global">Global (todo el tenant)</option><option value="holding">Un holding (todas sus organizaciones)</option><option value="organization">Una organización</option>
          </select>
        </label>
        {form.scope_type === 'holding' && (
          <label><span>Holding</span>
            <select required value={form.scope_id} onChange={(e) => setForm({ ...form, scope_id: e.target.value })}>
              <option value="">— elegir —</option>{holdings.map((h) => <option key={h.id} value={h.id}>{h.name}</option>)}
            </select>
          </label>
        )}
        {form.scope_type === 'organization' && (
          <label><span>Organización</span>
            <select required value={form.scope_id} onChange={(e) => setForm({ ...form, scope_id: e.target.value })}>
              <option value="">— elegir —</option>{orgs.map((o) => <option key={o.id} value={o.id}>{o.legal_name}</option>)}
            </select>
          </label>
        )}
        <label><span>Vigente hasta (opcional)</span><input type="date" value={form.valid_until} onChange={(e) => setForm({ ...form, valid_until: e.target.value })} /></label>
        <div className="wide buttons"><button className="primary">Asignar</button></div>
      </form>
      <small className="muted">Solo puede asignar perfiles cuyos permisos y alcance usted ya posee.</small>
    </Modal>
  );
}
