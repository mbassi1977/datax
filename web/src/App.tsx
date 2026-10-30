import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me, session, setUnauthorizedHandler, upload } from './api';
import { groupOrder, pages, resources } from './resources';
import type { NavItem } from './resources';
import { ImportsPage } from './ImportsPage';
import { ProfilesPage } from './ProfilesPage';
import { AvatarGalleriesPage } from './AvatarGalleriesPage';
import { Avatar } from './Avatar';
import { Icon } from './Icon';
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

const readOpenGroups = (): string[] => {
  try { return JSON.parse(localStorage.getItem('hrx_nav_groups') ?? 'null') ?? [groupOrder[0]]; } catch { return [groupOrder[0]]; }
};
const saveOpenGroups = (g: string[]) => { try { localStorage.setItem('hrx_nav_groups', JSON.stringify(g)); } catch { /* sin storage */ } return g; };
const readCollapsed = () => { try { return localStorage.getItem('hrx_nav_collapsed') === '1'; } catch { return false; } };

function Shell({ me, reload }: { me: Me; reload: () => void }) {
  const route = useHash();
  const [account, setAccount] = useState(false);
  const [collapsed, setCollapsed] = useState(readCollapsed); // escritorio: solo iconos
  const [open, setOpen] = useState(false);                   // móvil: cajón lateral
  const visible: NavItem[] = [...resources, ...pages].filter((r) => can(me, r.perm, 'ver'));
  const current = visible.find((r) => r.id === route);
  const currentResource = resources.find((r) => r.id === current?.id);
  const profileCodes = [...new Map(me.profiles.map((p) => [p.code, p.name])).entries()];
  const groups = groupOrder.map((g) => ({ name: g, items: visible.filter((r) => r.group === g) })).filter((g) => g.items.length > 0);

  // Acordeón: cada grupo se abre/cierra por separado; el grupo de la sección actual siempre queda abierto.
  const [openGroups, setOpenGroups] = useState<string[]>(readOpenGroups);
  useEffect(() => {
    if (current && !openGroups.includes(current.group)) setOpenGroups((o) => saveOpenGroups([...o, current.group]));
  }, [current?.id]); // eslint-disable-line react-hooks/exhaustive-deps
  const toggleGroup = (g: string) => setOpenGroups((o) => saveOpenGroups(o.includes(g) ? o.filter((x) => x !== g) : [...o, g]));

  const toggleCollapsed = () => {
    setCollapsed((c) => {
      try { localStorage.setItem('hrx_nav_collapsed', c ? '0' : '1'); } catch { /* sin storage */ }
      return !c;
    });
  };

  // El cajón móvil se cierra al navegar y con Escape.
  useEffect(() => { setOpen(false); }, [route]);
  useEffect(() => {
    if (!open) return;
    const h = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  }, [open]);

  const logout = async () => {
    try { await api('POST', 'auth/logout'); } catch { /* token ya inválido */ }
    session.setToken(null); session.setProfile(null); location.hash = ''; location.reload();
  };

  return (
    <div className={`shell${collapsed ? ' collapsed' : ''}${open ? ' open' : ''}`}>
      <header className="topbar">
        <button onClick={() => setOpen(true)} aria-label="Abrir menú" aria-expanded={open}><Icon name="menu" /></button>
        <strong>{current?.title ?? 'HRX'}</strong>
      </header>
      {open && <div className="backdrop" onClick={() => setOpen(false)} />}
      <nav aria-label="Menú principal">
        <div className="nav-head">
          <a href="#/" className="brand">HRX</a>
          <button className="toggle desktop-only" onClick={toggleCollapsed} aria-label={collapsed ? 'Expandir menú' : 'Colapsar menú'} title={collapsed ? 'Expandir menú' : 'Colapsar menú'}>
            <Icon name={collapsed ? 'expand' : 'collapse'} />
          </button>
          <button className="toggle mobile-only" onClick={() => setOpen(false)} aria-label="Cerrar menú"><Icon name="close" /></button>
        </div>
        <a href="#/" className={route === '' ? 'active' : ''} title="Inicio"><Icon name="home" /><span>Inicio</span></a>
        {groups.map((g, i) => {
          const isOpen = openGroups.includes(g.name);
          return (
            <div key={g.name} className={`group${isOpen ? ' open' : ''}`}>
              <button className="group-head" onClick={() => toggleGroup(g.name)} aria-expanded={isOpen} aria-controls={`grp-${i}`}>
                <span>{g.name}</span><Icon name="chevron" />
              </button>
              <div className="group-body" id={`grp-${i}`}><div>
                {g.items.map((r) => (
                  <a key={r.id} href={`#/${r.id}`} className={r.id === route ? 'active' : ''} title={r.title} tabIndex={isOpen ? 0 : -1}><Icon name={r.id} /><span>{r.title}</span></a>
                ))}
              </div></div>
            </div>
          );
        })}
        <div className="spacer" />
        {profileCodes.length > 1 && (
          <label className="profile"><span>Perfil activo</span>
            <select value={session.profile() ?? ''} onChange={(e) => { session.setProfile(e.target.value || null); reload(); }}>
              <option value="">Todos mis perfiles</option>
              {profileCodes.map(([code, name]) => <option key={code} value={code}>{name}</option>)}
            </select>
          </label>
        )}
        <button className="navbtn" onClick={() => setAccount(true)} title="Mi cuenta: foto y contraseña"><Avatar url={me.avatar_url} label={me.email} size={24} /><span>{me.email}</span></button>
        <button className="navbtn" onClick={logout} title="Salir"><Icon name="logout" /><span>Salir</span></button>
      </nav>
      <main>
        {currentResource ? <ResourcePage key={currentResource.id} res={currentResource} me={me} />
          : current?.id === 'imports' ? <ImportsPage me={me} />
          : current?.id === 'profiles' ? <ProfilesPage me={me} />
          : current?.id === 'avatar-galleries' ? <AvatarGalleriesPage me={me} />
          : <Home me={me} />}
      </main>
      {account && <Account me={me} reload={reload} onClose={() => setAccount(false)} />}
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

type AvatarOptions = { avatar_url: string | null; selected_id: string | null; has_upload: boolean; collections: { id: string; name: string; description: string | null; avatars: { id: string; name: string; url: string }[] }[] };

function Account({ me, reload, onClose }: { me: Me; reload: () => void; onClose: () => void }) {
  const [tab, setTab] = useState<'photo' | 'password'>('photo');
  return (
    <Modal title="Mi cuenta" onClose={onClose}>
      <div className="tabs" role="tablist">
        <button role="tab" aria-selected={tab === 'photo'} className={tab === 'photo' ? 'on' : ''} onClick={() => setTab('photo')}>Foto de perfil</button>
        <button role="tab" aria-selected={tab === 'password'} className={tab === 'password' ? 'on' : ''} onClick={() => setTab('password')}>Contraseña</button>
      </div>
      {tab === 'photo' ? <PhotoTab me={me} reload={reload} /> : <PasswordTab onClose={onClose} />}
    </Modal>
  );
}

function PhotoTab({ me, reload }: { me: Me; reload: () => void }) {
  const [opts, setOpts] = useState<AvatarOptions | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => { api<AvatarOptions>('GET', 'me/avatar').then(setOpts).catch((e) => setError(e.message)); }, []);
  useEffect(load, [load]);

  const act = async (fn: () => Promise<unknown>) => {
    setBusy(true); setError('');
    try { await fn(); load(); reload(); } catch (e) { setError((e as ApiError).message); } finally { setBusy(false); }
  };
  const pick = (id: string) => act(() => api('PUT', 'me/avatar', { avatar_id: id }));
  const remove = () => act(() => api('DELETE', 'me/avatar'));
  const send = (file?: File) => {
    if (!file) return;
    const form = new FormData();
    form.append('file', file);
    return act(() => upload('me/avatar', form));
  };

  return (
    <div className="photo">
      <div className="current">
        <Avatar url={opts?.avatar_url ?? me.avatar_url} label={me.email} size={96} />
        <div>
          <p><strong>{me.email}</strong></p>
          <div className="buttons left">
            <label className="btn">
              Subir mi foto<input type="file" accept="image/jpeg,image/png,image/webp" hidden disabled={busy} onChange={(e) => { send(e.target.files?.[0]); e.target.value = ''; }} />
            </label>
            {(opts?.avatar_url ?? me.avatar_url) && <button onClick={remove} disabled={busy}>Quitar</button>}
          </div>
          <small className="muted">JPG, PNG o WebP de hasta 4 MB. Se recorta a cuadrado y se reduce.</small>
        </div>
      </div>
      {error && <p className="error">{error}</p>}
      <h3>O elija un avatar de la galería</h3>
      {opts && opts.collections.length === 0 && <p className="muted">Su empresa aún no tiene galerías de avatares disponibles.</p>}
      {opts?.collections.map((c) => (
        <div key={c.id}>
          <h4>{c.name}</h4>
          <div className="picker">
            {c.avatars.map((a) => (
              <button key={a.id} type="button" className={opts.selected_id === a.id ? 'sel' : ''} title={a.name} aria-pressed={opts.selected_id === a.id} disabled={busy} onClick={() => pick(a.id)}>
                <img src={a.url} alt={a.name} />
              </button>
            ))}
          </div>
        </div>
      ))}
    </div>
  );
}

function PasswordTab({ onClose }: { onClose: () => void }) {
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
    <form onSubmit={submit} className="form one">
      {f('current_password', 'Contraseña actual')}
      {f('password', 'Nueva contraseña (mín. 10, letras y números)')}
      {f('password_confirmation', 'Repita la nueva contraseña')}
      {msg && <p className={msg.ok ? 'ok' : 'error'}>{msg.text}</p>}
      <div className="buttons"><button type="button" onClick={onClose}>Cerrar</button><button className="primary">Cambiar</button></div>
    </form>
  );
}
