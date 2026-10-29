import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me, session, setUnauthorizedHandler } from './api';
import { resources } from './resources';
import { can, Modal, ResourcePage } from './ResourcePage';

const useHash = () => {
  const [h, setH] = useState(location.hash.slice(2));
  useEffect(() => {
    const f = () => setH(location.hash.slice(2));
    window.addEventListener('hashchange', f);
    return () => window.removeEventListener('hashchange', f);
  }, []);
  return h;
};

export function App() {
  const [me, setMe] = useState<Me | null>(null);
  const [ready, setReady] = useState(false);

  const loadMe = useCallback(async () => {
    try { setMe(await api<Me>('GET', 'me')); } catch { setMe(null); session.setToken(null); }
    setReady(true);
  }, []);

  useEffect(() => {
    setUnauthorizedHandler(() => { session.setToken(null); setMe(null); });
    if (session.token()) loadMe(); else setReady(true);
  }, [loadMe]);

  if (!ready) return <p className="center muted">Cargando…</p>;
  if (!me) return <Login onDone={loadMe} />;
  return <Shell me={me} reload={loadMe} />;
}

function Login({ onDone }: { onDone: () => void }) {
  const [tenant, setTenant] = useState(session.tenant());
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setError('');
    try {
      const r = await api<{ token: string }>('POST', 'auth/login', { tenant, email, password });
      session.setTenant(tenant); session.setProfile(null); session.setToken(r.token);
      onDone();
    } catch (e2) { setError((e2 as ApiError).message); } finally { setBusy(false); }
  };

  return (
    <main className="login">
      <form className="card" onSubmit={submit}>
        <h1>HRX</h1>
        <p className="muted">Plataforma de Gestión Organizacional</p>
        <label><span>Organización cliente (tenant)</span><input value={tenant} onChange={(e) => setTenant(e.target.value)} required /></label>
        <label><span>Correo</span><input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus /></label>
        <label><span>Contraseña</span><input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required /></label>
        {error && <p className="error">{error}</p>}
        <button className="primary" disabled={busy}>{busy ? 'Ingresando…' : 'Ingresar'}</button>
      </form>
    </main>
  );
}

function Shell({ me, reload }: { me: Me; reload: () => void }) {
  const route = useHash();
  const [account, setAccount] = useState(false);
  const visible = resources.filter((r) => can(me, r.perm, 'ver'));
  const current = visible.find((r) => r.id === route);
  const profileCodes = [...new Map(me.profiles.map((p) => [p.code, p.name])).entries()];

  const logout = async () => {
    try { await api('POST', 'auth/logout'); } catch { /* token ya inválido */ }
    session.setToken(null); session.setProfile(null); location.hash = ''; location.reload();
  };

  return (
    <div className="shell">
      <nav>
        <a href="#/" className="brand">HRX</a>
        {visible.map((r) => <a key={r.id} href={`#/${r.id}`} className={r.id === route ? 'active' : ''}>{r.title}</a>)}
        <div className="spacer" />
        {profileCodes.length > 1 && (
          <label className="profile"><span>Perfil activo</span>
            <select value={session.profile() ?? ''} onChange={(e) => { session.setProfile(e.target.value || null); reload(); }}>
              <option value="">Todos mis perfiles</option>
              {profileCodes.map(([code, name]) => <option key={code} value={code}>{name}</option>)}
            </select>
          </label>
        )}
        <button onClick={() => setAccount(true)}>{me.email}</button>
        <button onClick={logout}>Salir</button>
      </nav>
      <main>
        {current ? <ResourcePage key={current.id} res={current} me={me} /> : <Home me={me} />}
      </main>
      {account && <Account onClose={() => setAccount(false)} />}
    </div>
  );
}

function Home({ me }: { me: Me }) {
  return (
    <section>
      <h1>Bienvenido</h1>
      <div className="card pad">
        <p><strong>{me.email}</strong></p>
        <p className="muted">Sus perfiles:</p>
        <ul>{me.profiles.map((p, i) => <li key={i}>{p.name} — {p.scope_type === 'global' ? 'alcance global' : `alcance: ${p.scope_type}`}</li>)}</ul>
        <p className="muted">Use el menú de la izquierda para gestionar la estructura, las personas y los proyectos.</p>
      </div>
    </section>
  );
}

function Account({ onClose }: { onClose: () => void }) {
  const [v, setV] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setMsg(null);
    try {
      await api('POST', 'me/password', v);
      setMsg({ ok: true, text: 'Contraseña actualizada.' });
      setV({ current_password: '', password: '', password_confirmation: '' });
    } catch (e2) {
      const ae = e2 as ApiError;
      setMsg({ ok: false, text: Object.values(ae.errors).flat().join(' ') || ae.message });
    }
  };
  const f = (k: keyof typeof v, label: string) => (
    <label><span>{label}</span><input type="password" required value={v[k]} onChange={(e) => setV({ ...v, [k]: e.target.value })} /></label>
  );

  return (
    <Modal title="Cambiar contraseña" onClose={onClose}>
      <form onSubmit={submit} className="form one">
        {f('current_password', 'Contraseña actual')}
        {f('password', 'Nueva contraseña (mín. 10, letras y números)')}
        {f('password_confirmation', 'Repita la nueva contraseña')}
        {msg && <p className={msg.ok ? 'ok' : 'error'}>{msg.text}</p>}
        <div className="buttons"><button type="button" onClick={onClose}>Cerrar</button><button className="primary">Cambiar</button></div>
      </form>
    </Modal>
  );
}
