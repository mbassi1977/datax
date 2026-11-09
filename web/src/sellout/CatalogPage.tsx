import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me, upload } from '../api';
import { can, Modal } from '../ResourcePage';
import { SERIES } from './model';
import { SketchCan } from './charts';
import { Geo, PointPicker, Tiles, loadGeo } from './MapView';
import './sellout.css';
import './sellout-map.css';

type Product = { id: string; name: string; aliases: string[]; color: string | null; units_per_box: number; active: boolean; image: string | null };
type Chain = { id: string; name: string; color: string | null };
type Point = { id: string; chain_id: string; code: string; name: string; address: string | null; commune: string | null; region: string | null; latitude: number | null; longitude: number | null; geo_quality: string; status: string };

const QUALITY: Record<string, string> = { exact: 'Exacta', commune: 'Por comuna', name: 'Por nombre', none: 'Sin ubicación' };

/** Catálogo del cliente en Sell-out: productos (con su diseño y equivalencias por cadena), cadenas y salas. */
export function SelloutCatalogPage({ me }: { me: Me }) {
  const [clients, setClients] = useState<{ id: string; name: string }[]>([]);
  const [clientId, setClientId] = useState('');
  const [tab, setTab] = useState<'products' | 'chains' | 'points'>('products');
  const [error, setError] = useState('');
  useEffect(() => { api<{ id: string; name: string }[]>('GET', 'sellout/clients').then((c) => { setClients(c); setClientId((p) => p || c[0]?.id || ''); }).catch((e) => setError(e.message)); }, []);
  const edit = can(me, 'sellout', 'editar');
  const tiles: Tiles = me.settings?.maps?.tile_url ? { url: me.settings.maps.tile_url, attribution: me.settings.maps.attribution ?? '' } : null;

  return (
    <section className="so">
      <header className="bar"><h1>Catálogo Sell-out</h1>
        {clients.length > 1 && <select aria-label="Cliente" value={clientId} onChange={(e) => setClientId(e.target.value)} style={{ maxWidth: 260 }}>{clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>}
      </header>
      {error && <p className="error">{error}</p>}
      {!clientId ? <p className="muted">No hay clientes disponibles.</p> : (
        <>
          <div className="so-tabs" role="tablist" style={{ marginBottom: 16 }}>
            {([['products', 'Productos'], ['chains', 'Cadenas'], ['points', 'Salas']] as const).map(([id, l]) => <button key={id} role="tab" aria-selected={tab === id} onClick={() => setTab(id)}>{l}</button>)}
          </div>
          {tab === 'products' && <Products clientId={clientId} edit={edit} />}
          {tab === 'chains' && <Chains clientId={clientId} edit={edit} />}
          {tab === 'points' && <Points clientId={clientId} edit={edit} tiles={tiles} />}
        </>
      )}
    </section>
  );
}

function Products({ clientId, edit }: { clientId: string; edit: boolean }) {
  const [list, setList] = useState<Product[]>([]);
  const [editing, setEditing] = useState<Product | 'new' | null>(null);
  const [error, setError] = useState('');
  const load = useCallback(() => { api<Product[]>('GET', `sellout/products?organization_id=${clientId}`).then(setList).catch((e) => setError(e.message)); }, [clientId]);
  useEffect(load, [load]);
  return (
    <>
      <div className="so-maphead"><p className="hint" style={{ margin: 0, maxWidth: '70ch' }}>Cada producto tiene el nombre con que se muestra, su diseño (imagen PNG/WebP con fondo transparente) y las <b>equivalencias</b>: textos con que cada cadena lo nombra en sus archivos. Al cargar ventas, el producto se reconoce si el texto contiene alguna equivalencia.</p>
        {edit && <button className="primary" onClick={() => setEditing('new')}>+ Nuevo producto</button>}</div>
      {error && <p className="error">{error}</p>}
      <div className="so-cans" style={{ justifyContent: 'flex-start', gap: 14 }}>
        {list.map((p, i) => (
          <button key={p.id} className="so-can" style={{ flex: '0 0 170px', border: '1px solid var(--border)', borderRadius: 8, padding: 12 }} onClick={() => edit && setEditing(p)} title={edit ? 'Editar' : undefined}>
            <span className="cimg">{p.image ? <img className="so-pav" src={p.image} alt={p.name} style={{ height: 112 }} /> : <SketchCan color={p.color ?? SERIES[i % SERIES.length]} level={0.6} w={44} h={112} />}</span>
            <strong>{p.name}{!p.active && ' (inactivo)'}</strong>
            <span className="muted" style={{ fontSize: 12 }}>{p.aliases.length ? p.aliases.join(' · ') : 'sin equivalencias'}</span>
            <span className="muted" style={{ fontSize: 12 }}>{p.units_per_box} un. por caja</span>
          </button>
        ))}
        {list.length === 0 && <p className="muted">Aún no hay productos. Agregue los del cliente antes de cargar ventas.</p>}
      </div>
      {editing && <ProductForm clientId={clientId} product={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
    </>
  );
}

function ProductForm({ clientId, product, onClose, onSaved }: { clientId: string; product: Product | null; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(product?.name ?? '');
  const [aliases, setAliases] = useState((product?.aliases ?? []).join(', '));
  const [color, setColor] = useState(product?.color ?? '#2a78d6');
  const [upb, setUpb] = useState(product?.units_per_box ?? 12);
  const [active, setActive] = useState(product?.active ?? true);
  const [image, setImage] = useState(product?.image ?? null);
  const [error, setError] = useState('');
  const fail = (e: unknown) => { const ae = e as ApiError; setError(Object.values(ae.errors ?? {}).flat().join(' ') || ae.message); };

  const save = async (e: FormEvent) => {
    e.preventDefault(); setError('');
    const body = { name, aliases: aliases.split(',').map((a) => a.trim()).filter(Boolean), color, units_per_box: upb, active };
    try { product ? await api('PUT', `sellout/products/${product.id}`, body) : await api('POST', `sellout/products?organization_id=${clientId}`, body); onSaved(); } catch (e2) { fail(e2); }
  };
  const putImage = async (file?: File) => {
    if (!file || !product) return;
    const fd = new FormData(); fd.append('file', file);
    try { const r = await upload<Product>(`sellout/products/${product.id}/image`, fd); setImage(r.image); } catch (e) { fail(e); }
  };
  const del = async () => { if (!product || !confirm(`¿Eliminar «${product.name}»?`)) return; try { await api('DELETE', `sellout/products/${product.id}`); onSaved(); } catch (e) { fail(e); } };

  return (
    <Modal title={product ? 'Editar producto' : 'Nuevo producto'} onClose={onClose}>
      <form className="form" onSubmit={save}>
        <label><span>Nombre</span><input value={name} onChange={(e) => setName(e.target.value)} required maxLength={150} /></label>
        <label><span>Equivalencias por cadena (separadas por coma)</span><input value={aliases} onChange={(e) => setAliases(e.target.value)} placeholder="entren, entrenubes 470" /></label>
        <p className="hint">Basta con un fragmento que identifique el producto en los archivos de las cadenas, p. ej. «MINGA».</p>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <label><span>Color</span><input type="color" value={color} onChange={(e) => setColor(e.target.value)} style={{ width: 70, padding: 2 }} /></label>
          <label><span>Unidades por caja</span><input type="number" min={1} value={upb} onChange={(e) => setUpb(+e.target.value)} style={{ width: 110 }} /></label>
          <label style={{ flexDirection: 'row', alignItems: 'center', gap: 8, alignSelf: 'end' }}><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} style={{ width: 'auto' }} /> Activo</label>
        </div>
        {product ? (
          <div><span className="muted" style={{ fontSize: 13 }}>Diseño del producto</span>
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginTop: 6 }}>
              {image ? <img className="so-pav" src={image} alt={product.name} style={{ height: 90 }} /> : <SketchCan color={color} level={0.6} w={36} h={90} />}
              <label className="btn">Subir imagen<input type="file" accept="image/png,image/webp,image/jpeg" hidden onChange={(e) => putImage(e.target.files?.[0])} /></label>
              {image && <button type="button" onClick={async () => { try { await api('DELETE', `sellout/products/${product.id}/image`); setImage(null); } catch (e) { fail(e); } }}>Quitar</button>}
            </div></div>
        ) : <p className="hint">Guarde el producto y luego suba su imagen.</p>}
        {error && <p className="error">{error}</p>}
        <div className="buttons">{product && <button type="button" className="danger" onClick={del}>Eliminar</button>}<span style={{ flex: 1 }} /><button type="button" onClick={onClose}>Cancelar</button><button className="primary">Guardar</button></div>
      </form>
    </Modal>
  );
}

type Banner = { id: string; chain_id: string; name: string; aliases: string[]; logo: string | null };

/** Cadenas con su logo y, dentro de cada una, sus formatos (p. ej. Jumbo, Santa Isabel y Spid en Cencosud) reconocidos por el nombre de la sala. */
function Chains({ clientId, edit }: { clientId: string; edit: boolean }) {
  const [list, setList] = useState<(Chain & { logo: string | null })[]>([]);
  const [banners, setBanners] = useState<Banner[]>([]);
  const [name, setName] = useState('');
  const [error, setError] = useState('');
  const load = useCallback(() => {
    api<(Chain & { logo: string | null })[]>('GET', `sellout/chains?organization_id=${clientId}`).then(setList).catch((e) => setError(e.message));
    api<Banner[]>('GET', `sellout/banners?organization_id=${clientId}`).then(setBanners).catch(() => {});
  }, [clientId]);
  useEffect(load, [load]);
  const fail = (e: unknown) => { const ae = e as ApiError; setError(Object.values(ae.errors ?? {}).flat().join(' ') || ae.message); };
  const add = async (e: FormEvent) => { e.preventDefault(); setError(''); try { await api('POST', `sellout/chains?organization_id=${clientId}`, { name }); setName(''); load(); } catch (e2) { fail(e2); } };
  const color = async (c: Chain, v: string) => { try { await api('PUT', `sellout/chains/${c.id}`, { color: v }); load(); } catch (e) { fail(e); } };
  const putLogo = async (path: string, file?: File) => { if (!file) return; const fd = new FormData(); fd.append('file', file); try { await upload(path, fd); load(); } catch (e) { fail(e); } };
  const addBanner = async (chain: Chain, n: string) => { try { await api('POST', `sellout/banners?organization_id=${clientId}`, { chain_id: chain.id, name: n, aliases: [n] }); load(); } catch (e) { fail(e); } };
  const aliases = async (b: Banner, v: string) => { try { await api('PUT', `sellout/banners/${b.id}`, { aliases: v.split(',').map((x) => x.trim()).filter(Boolean) }); load(); } catch (e) { fail(e); } };

  return (
    <div style={{ display: 'grid', gap: 16, maxWidth: 760 }}>
      <p className="hint" style={{ margin: 0 }}>Suba el logo de cada cadena (PNG o WebP, idealmente con fondo transparente). Si una cadena tiene varios formatos con marca propia, agréguelos: la sala toma el logo del formato cuyo nombre aparezca en el suyo (p. ej. «Jumbo Bilbao» → Jumbo). Las cadenas también se crean solas al cargar un archivo con la columna «Cadena».</p>
      {error && <p className="error">{error}</p>}
      {list.map((c, i) => (
        <div key={c.id} className="so-card">
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
            {c.logo ? <img className="so-logo" src={c.logo} alt={c.name} style={{ height: 44, maxWidth: 120 }} /> : <span className="so-logo ph" style={{ height: 44, width: 44, background: c.color ?? SERIES[i % SERIES.length] }}>{c.name.slice(0, 2).toUpperCase()}</span>}
            <strong style={{ flex: 1 }}>{c.name}</strong>
            <input type="color" value={c.color ?? SERIES[i % SERIES.length]} disabled={!edit} onChange={(e) => color(c, e.target.value)} style={{ width: 50, padding: 2 }} aria-label={`Color de ${c.name}`} />
            {edit && <label className="btn">{c.logo ? 'Cambiar logo' : 'Subir logo'}<input type="file" accept="image/png,image/webp,image/jpeg" hidden onChange={(e) => putLogo(`sellout/chains/${c.id}/logo`, e.target.files?.[0])} /></label>}
            {edit && c.logo && <button onClick={async () => { try { await api('DELETE', `sellout/chains/${c.id}/logo`); load(); } catch (e) { fail(e); } }}>Quitar</button>}
          </div>
          <div style={{ marginTop: 10 }}>
            <div className="so-lbl">Formatos</div>
            {banners.filter((b) => b.chain_id === c.id).map((b) => (
              <div key={b.id} style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '6px 0', borderTop: '1px solid var(--border)', flexWrap: 'wrap' }}>
                {b.logo ? <img className="so-logo" src={b.logo} alt={b.name} style={{ height: 36, maxWidth: 100 }} /> : <span className="so-logo ph" style={{ height: 36, width: 36, background: '#9aa3b5' }}>{b.name.slice(0, 2).toUpperCase()}</span>}
                <strong style={{ minWidth: 90 }}>{b.name}</strong>
                <input defaultValue={b.aliases.join(', ')} disabled={!edit} onBlur={(e) => aliases(b, e.target.value)} style={{ flex: 1, minWidth: 160 }} aria-label={`Palabras que identifican ${b.name}`} title="Palabras del nombre de la sala que identifican este formato, separadas por coma" />
                {edit && <label className="btn">{b.logo ? 'Cambiar' : 'Subir logo'}<input type="file" accept="image/png,image/webp,image/jpeg" hidden onChange={(e) => putLogo(`sellout/banners/${b.id}/logo`, e.target.files?.[0])} /></label>}
                {edit && <button className="danger" onClick={async () => { if (confirm(`¿Quitar el formato «${b.name}»?`)) { try { await api('DELETE', `sellout/banners/${b.id}`); load(); } catch (e) { fail(e); } } }}>Quitar</button>}
              </div>
            ))}
            {edit && <BannerAdd onAdd={(n) => addBanner(c, n)} />}
          </div>
        </div>
      ))}
      {edit && <form onSubmit={add} style={{ display: 'flex', gap: 8 }}><input value={name} onChange={(e) => setName(e.target.value)} placeholder="Nueva cadena" required maxLength={120} /><button className="primary">Agregar cadena</button></form>}
    </div>
  );
}

function BannerAdd({ onAdd }: { onAdd: (name: string) => void }) {
  const [n, setN] = useState('');
  return (
    <form onSubmit={(e) => { e.preventDefault(); if (n.trim()) { onAdd(n.trim()); setN(''); } }} style={{ display: 'flex', gap: 8, marginTop: 8 }}>
      <input value={n} onChange={(e) => setN(e.target.value)} placeholder="Agregar formato (Jumbo, Spid…)" maxLength={120} style={{ maxWidth: 280 }} /><button>+ Formato</button>
    </form>
  );
}

function Points({ clientId, edit, tiles }: { clientId: string; edit: boolean; tiles: Tiles }) {
  const [page, setPage] = useState<{ data: Point[]; current_page: number; last_page: number; total: number } | null>(null);
  const [chains, setChains] = useState<Chain[]>([]);
  const [q, setQ] = useState('');
  const [quality, setQuality] = useState('');
  const [n, setN] = useState(1);
  const [editing, setEditing] = useState<Point | null>(null);
  const [error, setError] = useState('');
  useEffect(() => { api<Chain[]>('GET', `sellout/chains?organization_id=${clientId}`).then(setChains).catch(() => {}); }, [clientId]);
  const load = useCallback(() => {
    const qs = new URLSearchParams({ organization_id: clientId, page: String(n) }); if (q) qs.set('q', q); if (quality) qs.set('quality', quality);
    api<any>('GET', `sellout/points?${qs}`).then(setPage).catch((e) => setError(e.message));
  }, [clientId, n, q, quality]);
  useEffect(load, [load]);
  return (
    <>
      <div className="so-maphead"><p className="hint" style={{ margin: 0, maxWidth: '70ch' }}>Las salas se ubican por la comuna de su dirección. Corrija la latitud y longitud de una sala para dejar su ubicación exacta; una carga posterior no la reemplaza.</p>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}><input placeholder="Buscar sala, código o dirección…" value={q} onChange={(e) => { setQ(e.target.value); setN(1); }} style={{ width: 260 }} />
          <select value={quality} onChange={(e) => { setQuality(e.target.value); setN(1); }} aria-label="Calidad de ubicación" style={{ width: 170 }}><option value="">Toda ubicación</option>{Object.entries(QUALITY).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></div></div>
      {error && <p className="error">{error}</p>}
      <div className="so-card so-scroll"><table className="so-tbl"><thead><tr><th>Sala</th><th>Cadena</th><th>Dirección</th><th>Región</th><th>Ubicación</th><th /></tr></thead>
        <tbody>{page?.data.map((p) => <tr key={p.id}><td>{p.name}<br /><span className="so-lbl">{p.code}</span></td><td>{chains.find((c) => c.id === p.chain_id)?.name}</td><td>{p.address ?? '—'}</td><td>{p.region ?? '—'}</td>
          <td><span className={`so-pill ${p.geo_quality === 'exact' ? 'good' : p.geo_quality === 'commune' ? 'warn' : 'bad'}`}>{QUALITY[p.geo_quality] ?? p.geo_quality}</span></td>
          <td className="r">{edit && <button onClick={() => setEditing(p)}>Editar</button>}</td></tr>)}
          {page && page.data.length === 0 && <tr><td colSpan={6} className="muted">Sin salas.</td></tr>}</tbody></table></div>
      {page && page.last_page > 1 && <div className="pager"><button disabled={n <= 1} onClick={() => setN(n - 1)}>← Anterior</button><span className="muted">Página {page.current_page} de {page.last_page} · {page.total} salas</span><button disabled={n >= page.last_page} onClick={() => setN(n + 1)}>Siguiente →</button></div>}
      {editing && <PointForm point={editing} tiles={tiles} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
    </>
  );
}

function PointForm({ point, tiles, onClose, onSaved }: { point: Point; tiles: Tiles; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(point.name), [address, setAddress] = useState(point.address ?? '');
  const [lat, setLat] = useState<string>(point.latitude?.toString() ?? ''), [lon, setLon] = useState<string>(point.longitude?.toString() ?? '');
  const [status, setStatus] = useState(point.status), [error, setError] = useState('');
  const [geo, setGeo] = useState<Geo | null>(null);
  const [moved, setMoved] = useState(false);
  useEffect(() => { loadGeo().then(setGeo); }, []);
  const la = lat.trim() === '' ? null : Number(lat), lo = lon.trim() === '' ? null : Number(lon);
  const valid = la !== null && lo !== null && Number.isFinite(la) && Number.isFinite(lo) && Math.abs(la) <= 90 && Math.abs(lo) <= 180;

  const save = async (e: FormEvent) => {
    e.preventDefault(); setError('');
    try { await api('PUT', `sellout/points/${point.id}`, { name, address: address || null, ...(valid ? { latitude: la, longitude: lo } : {}), status }); onSaved(); } catch (e2) { const ae = e2 as ApiError; setError(Object.values(ae.errors ?? {}).flat().join(' ') || ae.message); }
  };
  return (
    <Modal title={`Editar sala ${point.code}`} onClose={onClose}>
      <form className="form" onSubmit={save}>
        <label><span>Nombre</span><input value={name} onChange={(e) => setName(e.target.value)} required /></label>
        <label><span>Dirección</span><input value={address} onChange={(e) => setAddress(e.target.value)} /></label>
        <div className="wide">
          <span className="muted" style={{ fontSize: 13 }}>Ubicación en el mapa</span>
          <p className="hint" style={{ margin: '2px 0 6px' }}>Arrastre el punto o toque el mapa donde está la sala. {tiles ? 'Acerque con + / − o la rueda para ubicarla en su calle.' : 'Esta vista no muestra calles; acerque para mayor precisión.'}</p>
          <PointPicker geo={geo} tiles={tiles} lat={valid ? la : null} lon={valid ? lo : null}
            onChange={(a, b) => { setLat(String(a)); setLon(String(b)); setMoved(true); }} />
          <div className="so-maphead" style={{ marginTop: 8, marginBottom: 0 }}>
            <span className={`so-pill ${moved ? 'good' : point.geo_quality === 'exact' ? 'good' : 'warn'}`}>{moved ? 'Quedará exacta al guardar' : point.geo_quality === 'exact' ? 'Ubicación exacta' : point.geo_quality === 'none' ? 'Sin ubicación todavía' : 'Ubicación aproximada (por comuna)'}</span>
          </div>
        </div>
        <div className="wide" style={{ display: 'flex', gap: 12 }}>
          <label style={{ flex: 1 }}><span>Latitud</span><input value={lat} onChange={(e) => { setLat(e.target.value); setMoved(true); }} placeholder="-33.4172" inputMode="decimal" /></label>
          <label style={{ flex: 1 }}><span>Longitud</span><input value={lon} onChange={(e) => { setLon(e.target.value); setMoved(true); }} placeholder="-70.6063" inputMode="decimal" /></label>
        </div>
        <label className="wide"><span>Estado</span><select value={status} onChange={(e) => setStatus(e.target.value)}><option value="active">Activa</option><option value="inactive">Inactiva (no aparece en el tablero)</option></select></label>
        {error && <p className="error">{error}</p>}
        <div className="wide buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="primary">Guardar</button></div>
      </form>
    </Modal>
  );
}
