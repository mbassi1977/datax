import { useEffect, useMemo, useState } from 'react';
import { api, Me } from '../api';
import { can } from '../ResourcePage';
import { DS, Filters, REGION_ORDER, goal, lastIdx, rows } from './model';
import { FilterBar } from './Filters';
import { Ctx, AlertasView, EntornoView, MapaView, MetasView, ResumenView } from './Views';
import { Ficha } from './Ficha';
import { RequestsTab, pendingCount, useRequests } from './Requests';
import { Geo, Tiles, loadGeo } from './MapView';
import './sellout.css';
import './sellout-map.css';

const TABS: [string, string][] = [['resumen', 'Resumen'], ['mapa', 'Mapa de salas'], ['entorno', 'Entorno'], ['metas', 'Metas'], ['alertas', 'Alertas'], ['solicitudes', 'Solicitudes']];

/** Tablero Sell-out de un cliente: venta en sala por cadena, región y producto; mapa; entorno de cada sala; metas, alertas y solicitudes. */
export function SelloutPage({ me, tab, onTab }: { me: Me; tab?: string; onTab: (t: string) => void }) {
  const [clients, setClients] = useState<{ id: string; name: string }[] | null>(null);
  const [clientId, setClientId] = useState<string>(() => { try { return localStorage.getItem('hrx_so_client') ?? ''; } catch { return ''; } });
  const [ds, setDs] = useState<DS | null>(null);
  const [error, setError] = useState('');
  const [geo, setGeo] = useState<Geo | null>(null);

  useEffect(() => { api<{ id: string; name: string }[]>('GET', 'sellout/clients').then((c) => { setClients(c); if (c.length && !c.some((x) => x.id === clientId)) setClientId(c[0].id); }).catch((e) => setError(e.message)); }, []);   // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { loadGeo().then(setGeo); }, []);
  useEffect(() => {
    if (!clientId) return;
    try { localStorage.setItem('hrx_so_client', clientId); } catch { /* sin storage */ }
    setDs(null); setError('');
    api<DS>('GET', `sellout/dataset?organization_id=${clientId}`).then(setDs).catch((e) => setError(e.message));
  }, [clientId]);

  if (error) return <section><header className="bar"><h1>Sell-out</h1></header><p className="error">{error}</p></section>;
  if (clients && clients.length === 0) return <section><header className="bar"><h1>Sell-out</h1></header><p className="muted">No hay clientes con acceso al módulo. Cree una organización de tipo «Cliente» y asígnele un perfil con permisos de Sell-out.</p></section>;
  if (!ds) return <section><header className="bar"><h1>Sell-out</h1></header><p className="muted">Cargando datos…</p></section>;
  return <Dashboard key={clientId} me={me} ds={ds} geo={geo} clients={clients ?? []} clientId={clientId} setClient={setClientId} tab={TABS.some((t) => t[0] === tab) ? (tab as string) : 'resumen'} onTab={onTab} />;
}

function Dashboard({ me, ds, geo, clients, clientId, setClient, tab, onTab }: { me: Me; ds: DS; geo: Geo | null; clients: { id: string; name: string }[]; clientId: string; setClient: (id: string) => void; tab: string; onTab: (t: string) => void }) {
  const [f, setF] = useState<Filters>(() => ({ chains: new Set(ds.chains.map((c) => c.id)), regions: new Set(), prods: new Set(ds.products.map((p) => p.id)), period: 'm3' }));
  const [sel, setSel] = useState<string | null>(null);
  const [near, setNear] = useState<string | null>(null);
  const [month, setMonth] = useState(() => { for (let i = lastIdx(ds); i >= 0; i--) if (ds.stores.some((s) => (s.t[i] ?? 0) > 0)) return i; return lastIdx(ds); });
  const { list } = useRequests(clientId);

  const tiles: Tiles = me.settings?.maps?.tile_url ? { url: me.settings.maps.tile_url, attribution: me.settings.maps.attribution ?? '' } : null;
  const R = useMemo(() => rows(ds, f), [ds, f]);
  const regions = useMemo(() => REGION_ORDER.filter((r) => ds.stores.some((s) => s.reg === r)).concat([...new Set(ds.stores.map((s) => s.reg).filter((r): r is string => !!r && !REGION_ORDER.includes(r)))]), [ds]);
  const store = sel ? ds.stores.find((s) => s.id === sel) ?? null : null;
  void goal;

  const ctx: Ctx = { me, ds, f, setF, R, geo, tiles, regions, open: setSel, sel, goNear: (id) => { setNear(id); setSel(null); onTab('entorno'); }, clientId };
  const pend = pendingCount(list, me);

  const tabs = (
    <div className="so-tabs" role="tablist">
      {TABS.map(([id, label]) => <button key={id} role="tab" aria-selected={tab === id} onClick={() => { onTab(id); }}>{label}{id === 'solicitudes' && pend ? <span className="so-badge">{pend}</span> : null}</button>)}
    </div>
  );
  const empty = ds.stores.length === 0 || ds.months.length === 0;

  return (
    <section className="so">
      <header className="bar">
        <h1>Sell-out</h1>
        {clients.length > 1 && <select aria-label="Cliente" value={clientId} onChange={(e) => setClient(e.target.value)} style={{ maxWidth: 260 }}>{clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>}
        {clients.length === 1 && <span className="muted">{clients[0].name}</span>}
      </header>
      <FilterBar ds={ds} f={f} setF={setF} regionsAvailable={regions} tabs={tabs} />
      {empty ? (
        <div className="so-card" style={{ marginTop: 16 }}><h2>Aún no hay datos</h2><p className="hint">Cargue las salas, el catálogo de productos y las ventas desde Importaciones («Sell-out · Salas», «Sell-out · Ventas»). El catálogo de productos y las cadenas se administran en Sell-out → Catálogo.</p></div>
      ) : (
        <div className={`so-layout${store ? ' with-drawer' : ''}`}>
          <main>
            {tab === 'resumen' && <ResumenView ctx={ctx} />}
            {tab === 'mapa' && <MapaView ctx={ctx} metaMonth={month} />}
            {tab === 'entorno' && <EntornoView ctx={ctx} nearId={near} setNear={setNear} />}
            {tab === 'metas' && <MetasView ctx={ctx} month={month} setMonth={setMonth} />}
            {tab === 'alertas' && <AlertasView ctx={ctx} month={month} />}
            {tab === 'solicitudes' && <RequestsTab me={me} clientId={clientId} onOpenPoint={setSel} />}
          </main>
          {store && <><div className="so-backdrop" onClick={() => setSel(null)} /><Ficha me={me} ds={ds} f={f} store={store} clientId={clientId} monthIdx={month} onClose={() => setSel(null)} onNear={ctx.goNear} /></>}
        </div>
      )}
      <p className="so-foot">Venta neta sin IVA según cada cadena. Cajas = unidades ÷ unidades por caja del producto. Variación contra el mismo período del año anterior sobre salas comparables. {tiles ? 'Mapa de calles © OpenStreetMap. ' : ''}Las salas se ubican por la comuna de su dirección; la ubicación exacta se corrige por sala en Sell-out → Catálogo.{can(me, 'sellout', 'crear') ? '' : ''}</p>
    </section>
  );
}
