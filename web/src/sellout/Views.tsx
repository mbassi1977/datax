import { ReactNode, useEffect, useMemo, useState } from 'react';
import type L from 'leaflet';
import { Me } from '../api';
import {
  DS, Filters, Row, SEMA_TXT, Store, agg, lastIdx, logoOf, chainColor, goal, groupBy, hav, inReg, money, monthLabel, nf, pct, periodLabels, periodWindow, prodSet, productColor, rows, sema, sgn, totals, ZONES,
} from './model';
import { Bars, BarItem, Cans, Logo, StackedBars } from './charts';
import { ChileMap, Geo, MapPoint, NearMap, Tiles, ZoneMap, regionKey } from './MapView';
import { toggleRegion } from './Filters';
import { RequestBox } from './Requests';

export type Ctx = {
  me: Me; ds: DS; f: Filters; setF: (f: Filters) => void; R: Row[]; geo: Geo | null; tiles: Tiles; regions: string[];
  open: (id: string) => void; sel: string | null; goNear: (id: string) => void; clientId: string;
};

const Kpi = ({ label, value, sub }: { label: string; value: ReactNode; sub?: ReactNode }) => (
  <div className="so-kpi"><div className="so-lbl">{label}</div><div className="v num">{value}</div>{sub !== undefined && <div className="d">{sub}</div>}</div>
);
const Dot = ({ color }: { color: string }) => <i className="so-dot" style={{ background: color }} />;
const Pill = ({ kind, children }: { kind: 'good' | 'warn' | 'bad'; children: ReactNode }) => <span className={`so-pill ${kind}`}>{children}</span>;
const chainOf = (ds: DS, id: string) => ds.chains.find((c) => c.id === id)?.name ?? '';

export function StoreTable({ ctx, list }: { ctx: Ctx; list: Row[] }) {
  return (
    <div className="so-scroll"><table className="so-tbl">
      <thead><tr><th>Sala</th><th>Cadena</th><th>Región</th><th className="r">Venta</th><th className="r">Cajas</th><th className="r">Margen</th><th className="r">vs año anterior</th></tr></thead>
      <tbody>{list.map((r) => {
        const y = r.prev && r.prev.s > 0 ? r.cur.s / r.prev.s - 1 : null, m = r.cur.s > 0 ? (r.cur.s - r.cur.c) / r.cur.s : null;
        return (
          <tr key={r.s.id} className="click" onClick={() => ctx.open(r.s.id)}>
            <td>{r.s.n}{r.s.q === 'name' || r.s.q === 'none' ? <span className="so-lbl" title="Ubicación aproximada"> ~</span> : null}</td>
            <td><span className="so-chain"><Logo src={logoOf(ctx.ds, r.s)} name={chainOf(ctx.ds, r.s.ch)} color={chainColor(ctx.ds, r.s.ch)} size={22} />{chainOf(ctx.ds, r.s.ch)}</span></td><td>{r.s.reg ?? '—'}</td>
            <td className="r num">{money(r.cur.s)}</td><td className="r num">{nf(r.cur.bx, 1)}</td><td className="r num">{m === null ? '—' : pct(m)}</td>
            <td className={`r num ${y === null ? '' : y >= 0 ? 'up' : 'down'}`}>{y === null ? '—' : sgn(y)}</td>
          </tr>
        );
      })}</tbody>
    </table></div>
  );
}

// ───────────────────────── Resumen ─────────────────────────
export function ResumenView({ ctx }: { ctx: Ctx }) {
  const { ds, f, R } = ctx, T = totals(R), labels = periodLabels(ds), [a, b] = periodWindow(ds, f.period), pf = prodSet(f, ds);
  const reg = useMemo(() => [...groupBy(R, (r) => r.s.reg ?? 'Sin región')].map(([k, rs]) => ({ k, s: rs.reduce((x, r) => x + r.cur.s, 0), n: rs.filter((r) => r.cur.s > 0).length })).sort((x, y) => y.s - x.s), [R]);
  const cad = useMemo(() => [...groupBy(R, (r) => r.s.ch)].map(([k, rs]) => ({ k, s: rs.reduce((x, r) => x + r.cur.s, 0), n: rs.filter((r) => r.cur.s > 0).length })).sort((x, y) => y.s - x.s), [R]);
  const mix = ds.products.filter((p) => f.prods.has(p.id)).map((p) => {
    let u = 0, s = 0; R.forEach((r) => { const x = agg(ds, r.s, a, b, new Set([p.id])); u += x.u; s += x.s; });
    return { p, u, s };
  }).sort((x, y) => y.u - x.u);
  const totU = mix.reduce((x, y) => x + y.u, 0);
  const top = [...R].filter((r) => r.cur.s > 0).sort((x, y) => y.cur.s - x.cur.s), conc = T.s > 0 ? top.slice(0, 10).reduce((x, r) => x + r.cur.s, 0) / T.s : 0;
  const tg = (reg: string) => ctx.setF(toggleRegion(f, reg, ctx.regions));
  void pf;

  return (
    <>
      <div className="so-kpis">
        <Kpi label={`Venta neta · ${labels[f.period]}`} value={money(T.s)} sub={T.yoy === null ? <span className="muted">sin base del año anterior</span> : <><span className={T.yoy >= 0 ? 'up' : 'down'}>{T.yoy >= 0 ? '▲' : '▼'} {sgn(T.yoy)}</span> vs año anterior</>} />
        <Kpi label="Cajas" value={nf(T.bx, 0)} sub={`${nf(T.u)} unidades`} />
        <Kpi label="Salas con venta" value={nf(T.active)} sub={`de ${nf(R.length)} en el filtro`} />
        <Kpi label="Margen bruto" value={T.margin === null ? '—' : pct(T.margin, 1)} sub="venta − costo, sobre venta" />
        <Kpi label="Concentración" value={pct(conc)} sub="las 10 mejores salas" />
      </div>
      <div className="so-grid2">
        <section className="so-card wide"><h2>Venta mensual por cadena</h2><p className="hint">Pesos netos. La franja marca el período elegido.</p><StackedBars ds={ds} R={R} f={f} /></section>
        <section className="so-card"><h2>Venta por región</h2><p className="hint">Toque una región para filtrar todo el tablero.</p>
          <Bars items={reg.map<BarItem>((r) => ({ key: r.k, label: r.k, value: r.s, display: money(r.s), on: f.regions.has(r.k), tip: <><b>{r.k}</b><div>{money(r.s)} · {r.n} salas</div></>, onClick: () => tg(r.k) }))} /></section>
        <section className="so-card"><h2>Mix de producto</h2><p className="hint">Participación en unidades del período, con el diseño de cada producto. Toque una para ver solo ese producto.</p>
          <Cans ds={ds} items={mix} total={totU} onSolo={(id) => ctx.setF({ ...f, prods: new Set([id]) })} />
          <h2 style={{ marginTop: 14 }}>Venta por cadena</h2>
          <Bars items={cad.map<BarItem>((c) => ({ key: c.k, label: chainOf(ds, c.k), value: c.s, color: chainColor(ds, c.k), display: money(c.s), tip: <><b>{chainOf(ds, c.k)}</b><div>{c.n} salas con venta</div></> }))} /></section>
        <section className="so-card wide"><h2>Las 10 salas que más venden</h2><p className="hint">Toque una fila para abrir la ficha de la sala.</p><StoreTable ctx={ctx} list={top.slice(0, 10)} /></section>
      </div>
    </>
  );
}

// ───────────────────────── Mapa ─────────────────────────
const ZOOMS: Record<string, { l: string; b: [[number, number], [number, number]] }> = {
  rm: { l: 'Gran Santiago', b: [[-33.8, -71.05], [-33.15, -70.35]] }, vp: { l: 'Valparaíso', b: [[-33.65, -71.8], [-32.45, -70.45]] },
  bb: { l: 'Biobío', b: [[-37.6, -73.5], [-36.4, -71.9]] }, nt: { l: 'Norte', b: [[-30.4, -71.2], [-22, -67.8]] },
};
type Metric = 'sales' | 'units' | 'margin' | 'meta';
const METRICS: Record<Metric, string> = { sales: 'Venta', units: 'Cajas', margin: 'Margen', meta: 'Cumplimiento de meta' };
type SortKey = 'weight' | 'sales' | 'growth' | 'name';
const SORTS: Record<SortKey, string> = { weight: 'Peso', sales: 'Venta', growth: 'Crecimiento', name: 'Nombre' };
const GREY = '#9aa3b5', RED = '#c43129', AMBER = '#eda100', GREEN = '#12805c';
const nrmName = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
const PAGE = 8;
/** Algunos archivos traen el código pegado al nombre («J659-JUMBO …»): en pantalla se muestra una sola vez. */
const cleanName = (s: Store) => s.n.replace(/^[A-Za-z]{0,2}\d+[-\s]+/, '');

type Item = { id: string; store: Store; name: string; addr: string; lat: number; lon: number; value: number; valueTxt: string; growth: number | null; weight: number; color: string; approx: boolean; sales: number; boxes: number; metaPct: number | null };

export function MapaView({ ctx, metaMonth }: { ctx: Ctx; metaMonth: number }) {
  const { ds, f, R } = ctx;
  const [metric, setMetric] = useState<Metric>('sales');
  const [mode, setMode] = useState<'bubbles' | 'heat'>('bubbles');
  const [th, setTh] = useState({ red: 0, yellow: 3 });                      // semáforo de crecimiento, en %
  const [zoom, setZoom] = useState<string>('auto');
  const [view, setView] = useState<L.LatLngBounds | null>(null);
  const [showMarkers, setShowMarkers] = useState(true);
  const [zones, setZones] = useState<'none' | 'commune'>('none');
  const [sort, setSort] = useState<{ by: SortKey; desc: boolean }>({ by: 'weight', desc: true });
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [hover, setHover] = useState<string | null>(null);
  const [focus, setFocus] = useState<{ id: string; n: number } | null>(null);
  const [ctrlOpen, setCtrlOpen] = useState(false);
  const [chileOpen, setChileOpen] = useState(true);
  const selStore = ctx.sel ? ds.stores.find((s) => s.id === ctx.sel) : null;
  const T = useMemo(() => totals(R), [R]);

  // Autofoco: la sala abierta, o las regiones elegidas, o el Gran Santiago.
  const regionBounds = (): [[number, number], [number, number]] | null => {
    if (!ctx.geo || !f.regions.size) return null;
    let x0 = 1e9, x1 = -1e9, y0 = 1e9, y1 = -1e9;
    ctx.geo.regiones.features.forEach((ft: any) => { if (!f.regions.has(regionKey(ft.properties.Region) ?? '')) return; (ft.geometry.type === 'Polygon' ? [ft.geometry.coordinates] : ft.geometry.coordinates).forEach((pl: number[][][]) => pl[0].forEach((c) => { x0 = Math.min(x0, c[0]); x1 = Math.max(x1, c[0]); y0 = Math.min(y0, c[1]); y1 = Math.max(y1, c[1]); })); });
    return x0 > x1 ? null : [[y0 - 0.05, x0 - 0.05], [y1 + 0.05, x1 + 0.05]];
  };
  let bounds = ZOOMS.rm.b, zoneLabel = ZOOMS.rm.l, key = 'rm';
  if (zoom !== 'auto') { bounds = ZOOMS[zoom].b; zoneLabel = ZOOMS[zoom].l; key = zoom; }
  else if (selStore?.lat != null) { bounds = [[selStore.lat - 0.055, selStore.lon! - 0.07], [selStore.lat + 0.055, selStore.lon! + 0.07]]; zoneLabel = selStore.n; key = 'sel:' + selStore.id; }
  else { const rb = regionBounds(); if (rb) { bounds = rb; zoneLabel = f.regions.size === 1 ? [...f.regions][0] : `${f.regions.size} regiones`; key = 'reg:' + [...f.regions].join(','); } }

  const semaphore = (g: number | null) => (g === null ? GREY : g * 100 < th.red ? RED : g * 100 < th.yellow ? AMBER : GREEN);

  // Una ficha por sala con lo que muestran el mapa y la lista: valor, crecimiento contra el año anterior y peso en el total.
  const items: Item[] = useMemo(() => {
    const base = (s: Store, cur: { s: number; bx: number }, prev: { s: number } | null | undefined, value: number, valueTxt: string, color: string, metaPct: number | null): Item => ({
      id: s.id, store: s, name: `${s.code} ${cleanName(s)}`, addr: s.ad ?? s.com ?? '', lat: s.lat!, lon: s.lon!, value, valueTxt, color, approx: s.q !== 'exact' && s.q !== 'commune', sales: cur.s, boxes: cur.bx, metaPct,
      growth: prev && prev.s > 0 ? cur.s / prev.s - 1 : null, weight: T.s > 0 ? cur.s / T.s : 0,
    });
    if (metric === 'meta') {
      return ds.stores.filter((s) => f.chains.has(s.ch) && inReg(f, s.reg) && s.lat != null).flatMap((s) => {
        const g = goal(ds, s, metaMonth); if (!g) return [];
        const cur = agg(ds, s, metaMonth, metaMonth, null);
        return [base(s, cur, null, g.pct, pct(g.pct), colorOf(sema(g.pct)), g.pct)];
      });
    }
    return R.filter((r) => r.cur.s > 0 && r.s.lat != null).map((r) => {
      const margin = (r.cur.s - r.cur.c) / r.cur.s, v = metric === 'sales' ? r.cur.s : metric === 'units' ? r.cur.bx : margin;
      const txt = metric === 'sales' ? money(v) : metric === 'units' ? `${nf(v, 0)} cajas` : pct(v, 1);
      const it = base(r.s, r.cur, r.prev, v, txt, GREY, null);
      it.color = semaphore(it.growth);
      return it;
    });
  }, [ds, f, R, metric, metaMonth, th, T.s]);   // eslint-disable-line react-hooks/exhaustive-deps

  const points: MapPoint[] = useMemo(() => {
    const mx = Math.max(...items.map((i) => (metric === 'margin' ? i.sales : metric === 'meta' ? i.boxes : i.value)), 1e-9);
    return items.map((i) => {
      const size = metric === 'margin' ? i.sales : metric === 'meta' ? i.boxes : i.value;
      return {
        id: i.id, lat: i.lat, lon: i.lon, v: Math.max(i.value, 0), color: i.color, approx: i.approx, r: 4.5 + 15 * Math.sqrt(Math.max(size, 0) / mx),
        tip: `<b>${esc(i.name)}</b><br>${esc(chainOf(ds, i.store.ch))}${i.addr ? '<br>' + esc(i.addr) : ''}<br>${i.valueTxt}${i.approx ? ' · ubicación aproximada' : ''}`,
        popup: { title: i.name, sub: `${chainOf(ds, i.store.ch)}${i.addr ? ' · ' + i.addr : ''}`, big: i.valueTxt, bigNote: metric === 'meta' ? 'cumplimiento de la meta' : METRICS[metric].toLowerCase() + ' del período',
          kpis: metric === 'meta'
            ? [{ l: 'Venta', v: money(i.sales) }, { l: 'Cajas', v: nf(i.boxes, 1) }, { l: 'Peso', v: pct(i.weight, 1) }]
            : [{ l: 'Crec.', v: i.growth === null ? '—' : sgn(i.growth), cls: i.growth === null ? '' : i.growth >= 0 ? 'up' : 'down' }, { l: 'Peso', v: pct(i.weight, 1) }, { l: 'Cajas', v: nf(i.boxes, 0) }] },
      };
    });
  }, [items, metric, ds]);

  // Coloreado por comuna (selector de zonas): crecimiento de la venta de las salas de cada comuna.
  const communes = useMemo(() => {
    if (zones !== 'commune') return null;
    const m = new Map<string, { c: number; p: number }>();
    items.forEach((i) => { const k = nrmName(i.store.com ?? ''); if (!k) return; const o = m.get(k) ?? { c: 0, p: 0 }; o.c += i.sales; const r = R.find((x) => x.s.id === i.id); if (r?.prev && r.prev.s > 0) { o.p += r.prev.s; } else { o.c -= i.sales; } m.set(k, o); });
    const out: Record<string, string> = {};
    m.forEach((v, k) => { out[k] = semaphore(v.p > 0 ? v.c / v.p - 1 : null); });
    return out;
  }, [zones, items, R, th]);   // eslint-disable-line react-hooks/exhaustive-deps

  const regionVals = useMemo(() => {
    const sum: Record<string, number> = {}, cnt: Record<string, number> = {};
    items.forEach((i) => { const r = i.store.reg ?? ''; sum[r] = (sum[r] ?? 0) + i.value; cnt[r] = (cnt[r] ?? 0) + 1; });
    if (metric === 'margin' || metric === 'meta') Object.keys(sum).forEach((k) => { sum[k] /= cnt[k]; });
    return sum;
  }, [items, metric]);
  const fmt = (v: number) => (metric === 'meta' ? `Cumplimiento medio ${pct(v)}` : metric === 'margin' ? `Margen medio ${pct(v, 1)}` : metric === 'units' ? `${nf(v, 0)} cajas` : money(v));

  // Lista lateral: salas dentro del encuadre actual, con búsqueda y orden.
  const listed = useMemo(() => {
    const term = q.trim().toLowerCase();
    let l = items.filter((i) => (!view || view.contains([i.lat, i.lon])) && (!term || i.name.toLowerCase().includes(term) || i.addr.toLowerCase().includes(term)));
    const val = (i: Item) => (sort.by === 'weight' ? i.weight : sort.by === 'sales' ? i.sales : sort.by === 'growth' ? i.growth ?? -9 : 0);
    l = [...l].sort((a, b) => (sort.by === 'name' ? a.name.localeCompare(b.name, 'es') * (sort.desc ? -1 : 1) : (val(b) - val(a)) * (sort.desc ? 1 : -1)));
    return l;
  }, [items, view, q, sort]);
  const pages = Math.max(1, Math.ceil(listed.length / PAGE)), pg = Math.min(page, pages);
  useEffect(() => { setPage(1); }, [q, sort, metric, f, view?.getCenter().lat]);   // eslint-disable-line react-hooks/exhaustive-deps

  const num = (k: 'red' | 'yellow', v: string) => setTh((t) => ({ ...t, [k]: Number.isFinite(+v) ? +v : t[k] }));
  const initials = (n: string) => n.replace(/[^A-Za-zÁÉÍÓÚÑáéíóúñ ]/g, '').trim().slice(0, 2).toUpperCase();

  return (
    <>
      <div className="so-card">
        <div className="so-maphead">
          <div className="so-seg" role="group" aria-label="Indicador">{(Object.keys(METRICS) as Metric[]).map((m) => <button key={m} aria-pressed={metric === m} onClick={() => setMetric(m)}>{METRICS[m]}</button>)}</div>
          <div className="so-seg" role="group" aria-label="Representación"><button aria-pressed={mode === 'bubbles'} onClick={() => setMode('bubbles')}>Burbujas</button><button aria-pressed={mode === 'heat'} onClick={() => setMode('heat')}>Calor</button></div>
        </div>
        <div className="so-maphead">
          <div className="so-seg" role="group" aria-label="Zona">
            <button aria-pressed={zoom === 'auto'} onClick={() => setZoom('auto')}>Auto</button>
            {Object.entries(ZOOMS).map(([k, z]) => <button key={k} aria-pressed={zoom === k} onClick={() => setZoom(k)}>{z.l}</button>)}
          </div>
          <span className="so-lbl">{zoom === 'auto' ? `Enfoque automático: ${zoneLabel} · ` : ''}{items.length} salas · tamaño = {metric === 'margin' ? 'venta' : METRICS[metric].toLowerCase()}</span>
        </div>

        <div className="so-mapwrap">
          <div className="so-stage">
            <ZoneMap geo={ctx.geo} tiles={ctx.tiles} points={points} mode={mode} bounds={bounds} focusKey={key} hover={hover} focus={focus} communes={communes} showMarkers={showMarkers} onPick={ctx.open} onMove={setView} />

            <div className={`so-ctrl${ctrlOpen ? '' : ' closed'}`}>
              <button className="hd" onClick={() => setCtrlOpen(!ctrlOpen)} aria-expanded={ctrlOpen}>☰ Controlador</button>
              {ctrlOpen && (
                <div className="bd">
                  <div className="sec">{metric === 'meta' ? 'Semáforo: cumplimiento de meta' : 'Semáforo: % crecimiento'}</div>
                  {metric === 'meta' ? (
                    <div className="sem"><span><i style={{ background: RED }} />&lt; 70%</span><span><i style={{ background: AMBER }} />70–99%</span><span><i style={{ background: GREEN }} />≥ 100%</span></div>
                  ) : (
                    <div className="sem">
                      <span><i style={{ background: RED }} />menos de <input type="number" value={th.red} onChange={(e) => num('red', e.target.value)} aria-label="Límite rojo" />%</span>
                      <span><i style={{ background: AMBER }} />hasta <input type="number" value={th.yellow} onChange={(e) => num('yellow', e.target.value)} aria-label="Límite amarillo" />%</span>
                      <span><i style={{ background: GREEN }} />más que eso</span>
                      <span><i style={{ background: GREY }} />sin base del año anterior</span>
                    </div>
                  )}
                  <div className="sec">Marcadores</div>
                  <label className="chk"><input type="checkbox" checked={showMarkers} onChange={(e) => setShowMarkers(e.target.checked)} /> Salas</label>
                  <div className="sec">Selector de zonas</div>
                  <label className="chk"><input type="radio" name="zones" checked={zones === 'none'} onChange={() => setZones('none')} /> Ninguna</label>
                  <label className="chk"><input type="radio" name="zones" checked={zones === 'commune'} onChange={() => setZones('commune')} /> Comuna (color por crecimiento)</label>
                </div>
              )}
            </div>

            <div className={`so-inset${chileOpen ? '' : ' closed'}`}>
              <button className="hd" onClick={() => setChileOpen(!chileOpen)} aria-expanded={chileOpen} title="Mapa de Chile por región">{chileOpen ? '–' : 'Chile'}</button>
              {chileOpen && <ChileMap geo={ctx.geo} values={regionVals} selected={f.regions} format={fmt} view={view} height={210} onPick={(r) => { setZoom('auto'); ctx.setF(toggleRegion(f, r, ctx.regions)); }} />}
            </div>
          </div>

          <aside className="so-side" aria-label="Salas en el mapa">
            <div className="tools">
              <select value={sort.by} onChange={(e) => setSort({ ...sort, by: e.target.value as SortKey })} aria-label="Ordenar por">{(Object.keys(SORTS) as SortKey[]).map((k) => <option key={k} value={k}>{SORTS[k]}</option>)}</select>
              <button onClick={() => setSort({ ...sort, desc: !sort.desc })} aria-label="Invertir el orden" title="Invertir el orden">{sort.desc ? '↓' : '↑'}</button>
            </div>
            <input type="search" placeholder="Buscar sala…" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Buscar sala" />
            <div className="lst">
              {listed.slice((pg - 1) * PAGE, pg * PAGE).map((i) => (
                <button key={i.id} className={`row${hover === i.id ? ' on' : ''}`} onMouseEnter={() => setHover(i.id)} onMouseLeave={() => setHover(null)} onClick={() => setFocus({ id: i.id, n: Date.now() })}>
                  <span className="av"><Logo src={logoOf(ds, i.store)} name={chainOf(ds, i.store.ch)} color={chainColor(ds, i.store.ch)} size={36} /></span>
                  <span className="tx"><b>{i.name}</b><small>{i.addr || '—'}</small>
                    <span className="mt"><strong className="num">{i.valueTxt}</strong>
                      {metric !== 'meta' && <em className={i.growth === null ? '' : i.growth >= 0 ? 'up' : 'down'}>{i.growth === null ? '—' : `${i.growth >= 0 ? '▲' : '▼'} ${sgn(i.growth)}`}<small>crecimiento</small></em>}
                      <em><span className="num">{pct(i.weight, 1)}</span><small>peso</small></em></span></span>
                </button>
              ))}
              {listed.length === 0 && <p className="hint" style={{ padding: 12 }}>No hay salas en este encuadre. Aleje el mapa o cambie los filtros.</p>}
            </div>
            <div className="pg"><button disabled={pg <= 1} onClick={() => setPage(pg - 1)} aria-label="Página anterior">‹</button><span>Página {pg} de {pages} · {listed.length} salas</span><button disabled={pg >= pages} onClick={() => setPage(pg + 1)} aria-label="Página siguiente">›</button></div>
          </aside>
        </div>
        <p className="hint" style={{ margin: '8px 0 0' }}>{ctx.tiles ? 'Mapa de calles © OpenStreetMap. ' : ''}Toque una sala de la lista para acercar el mapa y ver su ficha; «más detalles» abre la ficha completa. Arrastre para mover, use + / − o la rueda (haga clic en el mapa primero). En el celular, dos dedos para mover y acercar.</p>
      </div>
      <section className="so-card" style={{ marginTop: 16 }}>
        <h2>Solicitudes para {zoneLabel}</h2><p className="hint">Una solicitud de zona queda asociada a la selección de regiones activa o, si no hay, a la zona del mapa.</p>
        <RequestBox me={ctx.me} clientId={ctx.clientId} scope="zone" zoneLabel={zoneLabel} />
      </section>
    </>
  );
}
const esc = (s: string) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] as string));
const colorOf = (s: 'good' | 'warn' | 'bad') => ({ good: '#12805c', warn: '#d98e04', bad: '#c43129' }[s]);

// ───────────────────────── Entorno ─────────────────────────
export function EntornoView({ ctx, nearId, setNear }: { ctx: Ctx; nearId: string | null; setNear: (id: string) => void }) {
  const { ds, f, R } = ctx;
  const [nn, setNn] = useState(5), [same, setSame] = useState(false), [q, setQ] = useState('');
  const mi = lastIdx(ds);
  const pool = R.map((r) => r.s);
  const base = ds.stores.find((s) => s.id === nearId && pool.some((p) => p.id === s.id)) ?? [...R].filter((r) => r.cur.s > 0 && !/dark|spid/i.test(r.s.n)).sort((a, b) => b.cur.s - a.cur.s)[0]?.s ?? pool[0];
  const [a, b] = periodWindow(ds, f.period), pf = prodSet(f, ds);
  if (!base || base.lat == null) return <div className="so-card"><p className="hint">No hay salas con ubicación para los filtros actuales.</p></div>;
  const info = (s: Store, d: number) => {
    const cur = agg(ds, s, a, b, pf), prev = a - 12 >= 0 ? agg(ds, s, a - 12, b - 12, pf) : null, g = goal(ds, s, mi);
    return { s, d, cur, yoy: prev && prev.s > 0 ? cur.s / prev.s - 1 : null, mg: cur.s > 0 ? (cur.s - cur.c) / cur.s : null, meta: g?.pct ?? null };
  };
  const nb = ds.stores.filter((s) => s.id !== base.id && s.lat != null && (!same || s.ch === base.ch)).map((s) => ({ s, d: hav({ lat: base.lat!, lon: base.lon! }, { lat: s.lat!, lon: s.lon! }) })).sort((x, y) => x.d - y.d).slice(0, nn);
  const B = info(base, 0), N = nb.map((o) => info(o.s, o.d)), ALL = [B, ...N];
  const avgS = N.length ? N.reduce((x, n) => x + n.cur.s, 0) / N.length : 0, rank = [...ALL].sort((x, y) => y.cur.s - x.cur.s).findIndex((i) => i === B) + 1;
  const vs = avgS > 0 ? B.cur.s / avgS - 1 : null, withMeta = N.filter((n) => n.meta !== null), mN = withMeta.length ? withMeta.reduce((x, n) => x + (n.meta as number), 0) / withMeta.length : null;
  const lead = vs === null ? 'Las salas vecinas no tienen venta en el período.' : `${base.n} vende ${vs >= 1 ? `${nf(vs + 1, 1)} veces lo` : vs >= 0 ? `${nf(vs * 100, 0)}% más` : `${nf(-vs * 100, 0)}% menos`} que el promedio de sus ${N.length} salas más cercanas y queda ${rank}° de ${ALL.length}.`;
  const leadM = B.meta !== null && mN !== null ? ` Su cumplimiento de meta es ${pct(B.meta)} contra ${pct(mN)} promedio del entorno.` : '';
  const maxD = N.length ? Math.max(...N.map((n) => n.d)) : 0;
  const options = pool.filter((s) => !q || s.n.toLowerCase().includes(q.toLowerCase())).slice(0, 80);

  return (
    <>
      <div className="so-card">
        <h2>Entorno de una sala</h2>
        <p className="hint">Elija una sala (solo las de los filtros de arriba) y compárela con las más cercanas de todas las cadenas. Toque un punto del mapa o una fila para cambiar de sala.</p>
        <div className="so-maphead">
          <input list="so-near-list" placeholder="Buscar sala por nombre…" aria-label="Buscar sala" value={q} onChange={(e) => { setQ(e.target.value); const m = pool.find((s) => `${s.n} · ${chainOf(ds, s.ch)}` === e.target.value); if (m) { setNear(m.id); setQ(''); } }} style={{ maxWidth: 280 }} />
          <datalist id="so-near-list">{options.map((s) => <option key={s.id} value={`${s.n} · ${chainOf(ds, s.ch)}`} />)}</datalist>
          <div className="so-seg" role="group" aria-label="Cantidad de salas cercanas">{[3, 5, 8, 10].map((n) => <button key={n} aria-pressed={nn === n} onClick={() => setNn(n)}>{n} cercanas</button>)}</div>
          <div className="so-seg" role="group" aria-label="Cadenas a comparar"><button aria-pressed={!same} onClick={() => setSame(false)}>Vecinas de todas las cadenas</button><button aria-pressed={same} onClick={() => setSame(true)}>Solo {chainOf(ds, base.ch)}</button></div>
        </div>
        <p className="so-lead">{lead}{leadM}</p>
      </div>
      <div className="so-kpis" style={{ marginTop: 16 }}>
        <Kpi label={`Venta ${periodLabels(ds)[f.period]}`} value={money(B.cur.s)} sub={<span className={vs === null ? '' : vs >= 0 ? 'up' : 'down'}>{vs === null ? '—' : `${vs >= 0 ? '▲ ' : '▼ '}${sgn(vs)} vs vecinas`}</span>} />
        <Kpi label="Puesto en el entorno" value={`${rank}° de ${ALL.length}`} sub="por venta del período" />
        <Kpi label="Cumplimiento de meta" value={B.meta === null ? '—' : pct(B.meta)} sub={mN === null ? 'sin meta en el entorno' : `entorno: ${pct(mN)}`} />
        <Kpi label="Radio del entorno" value={`${nf(maxD, 1)} km`} sub="distancia a la sala más lejana" />
      </div>
      <div className="so-grid2">
        <section className="so-card">
          <NearMap geo={ctx.geo} tiles={ctx.tiles} boundsKey={`${base.id}|${nn}|${same}`} onPick={setNear}
            base={{ id: base.id, lat: base.lat, lon: base.lon!, name: base.n, chain: chainOf(ds, base.ch), color: chainColor(ds, base.ch), logo: logoOf(ds, base), info: `Venta ${money(B.cur.s)}` }}
            neighbors={N.map((n) => ({ id: n.s.id, lat: n.s.lat!, lon: n.s.lon!, name: n.s.n, chain: chainOf(ds, n.s.ch), color: chainColor(ds, n.s.ch), logo: logoOf(ds, n.s), km: n.d, info: `Venta ${money(n.cur.s)}` }))} />
          <p className="hint" style={{ margin: '8px 0 0' }}>Distancias aproximadas: cada sala está ubicada en su comuna, no en su calle, salvo las que tienen ubicación exacta.</p>
        </section>
        <section className="so-card">
          <h2>Venta de la sala y su entorno</h2>
          <Bars items={ALL.map<BarItem>((i) => ({ key: i.s.id, label: i.s.n, value: i.cur.s, color: i === B ? 'var(--primary)' : chainColor(ds, i.s.ch), on: i === B, display: money(i.cur.s) }))} />
          <h2 style={{ marginTop: 14 }}>Detalle</h2>
          <div className="so-scroll"><table className="so-tbl"><thead><tr><th>Sala</th><th>Cadena</th><th className="r">Km</th><th className="r">Venta</th><th className="r">vs año ant.</th><th className="r">Margen</th><th className="r">Cumpl.</th></tr></thead>
            <tbody>{ALL.map((i) => (
              <tr key={i.s.id} className={i === B ? 'sel' : 'click'} onClick={i === B ? undefined : () => setNear(i.s.id)}>
                <td>{i.s.n}{i === B && <> <Pill kind="good">elegida</Pill></>}</td><td><span className="so-chain"><Logo src={logoOf(ds, i.s)} name={chainOf(ds, i.s.ch)} color={chainColor(ds, i.s.ch)} size={22} />{chainOf(ds, i.s.ch)}</span></td>
                <td className="r num">{i === B ? '—' : nf(i.d, 1)}</td><td className="r num">{money(i.cur.s)}</td>
                <td className={`r num ${i.yoy === null ? '' : i.yoy >= 0 ? 'up' : 'down'}`}>{i.yoy === null ? '—' : sgn(i.yoy)}</td><td className="r num">{i.mg === null ? '—' : pct(i.mg)}</td>
                <td className="r num">{i.meta === null ? '—' : <Pill kind={sema(i.meta)}>{pct(i.meta)}</Pill>}</td>
              </tr>))}</tbody></table></div>
        </section>
      </div>
    </>
  );
}

// ───────────────────────── Metas ─────────────────────────
export function MetasView({ ctx, month, setMonth }: { ctx: Ctx; month: number; setMonth: (m: number) => void }) {
  const { ds, f } = ctx;
  const monthsWithGoals = ds.months.map((m, i) => ({ m, i })).filter(({ i }) => ds.stores.some((s) => (s.t[i] ?? 0) > 0));
  const M = ds.stores.filter((s) => f.chains.has(s.ch) && inReg(f, s.reg)).flatMap((s) => { const g = goal(ds, s, month); return g ? [{ s, ...g }] : []; });
  const sum = (k: 'meta' | 'real') => M.reduce((x, y) => x + y[k], 0), mt = sum('meta'), rl = sum('real');
  const ok = M.filter((m) => m.pct >= 1).length, low = M.filter((m) => m.pct < 0.7).length;
  const grp = (fn: (m: (typeof M)[number]) => string) => [...groupBy(M, fn)].map(([k, v]) => { const m = v.reduce((x, y) => x + y.meta, 0), r = v.reduce((x, y) => x + y.real, 0); return { k, m, r, n: v.length, p: m ? r / m : 0 }; });
  const track = (o: { k: string; label?: string; m: number; r: number; n: number; p: number }): BarItem => ({ key: o.k, label: o.label ?? o.k, value: Math.min(o.p, 1.6), mark: 1, color: colorOf(sema(o.p)), display: pct(o.p), tip: <><b>{o.label ?? o.k}</b><div>Meta {nf(o.m, 1)} cajas · real {nf(o.r, 1)}</div><div>{o.n} salas</div></> });
  const sorted = [...M].sort((x, y) => x.pct - y.pct);
  const row = (m: (typeof M)[number]) => <tr key={m.s.id} className="click" onClick={() => ctx.open(m.s.id)}><td>{m.s.n}</td><td>{chainOf(ds, m.s.ch)}</td><td className="r num">{nf(m.meta, 1)}</td><td className="r num">{nf(m.real, 1)}</td><td className="r num">{pct(m.pct)}</td><td><Pill kind={sema(m.pct)}>{SEMA_TXT[sema(m.pct)]}</Pill></td></tr>;
  const head = <thead><tr><th>Sala</th><th>Cadena</th><th className="r">Meta</th><th className="r">Real</th><th className="r">Cumpl.</th><th>Estado</th></tr></thead>;

  if (!monthsWithGoals.length) return <div className="so-card"><h2>Metas</h2><p className="hint">Aún no hay metas cargadas. Súbalas desde Importaciones → «Sell-out · Metas».</p></div>;
  return (
    <>
      <div className="so-card" style={{ marginBottom: 16 }}>
        <div className="so-maphead"><div><h2>Metas de {monthLabel(ds.months[month])}</h2><p className="hint" style={{ margin: 0 }}>Meta mensual en cajas contra venta real del mes (todos los productos). La marca vertical es el 100%.</p></div>
          <select aria-label="Mes" value={month} onChange={(e) => setMonth(+e.target.value)} style={{ maxWidth: 160 }}>{monthsWithGoals.map(({ m, i }) => <option key={m} value={i}>{monthLabel(m)}</option>)}</select></div>
      </div>
      <div className="so-kpis">
        <Kpi label="Meta" value={`${nf(mt, 0)} cajas`} sub={`${nf(M.length)} salas con meta`} />
        <Kpi label="Venta real" value={`${nf(rl, 0)} cajas`} sub="mes completo" />
        <Kpi label="Cumplimiento" value={pct(mt ? rl / mt : 0, 1)} sub={<Pill kind={sema(mt ? rl / mt : 0)}>{SEMA_TXT[sema(mt ? rl / mt : 0)]}</Pill>} />
        <Kpi label="Salas que cumplen" value={nf(ok)} sub={`${pct(M.length ? ok / M.length : 0)} del total`} />
        <Kpi label="Salas bajo 70%" value={nf(low)} sub="requieren visita" />
      </div>
      <div className="so-grid2">
        <section className="so-card"><h2>Por región</h2><Bars max={1.6} items={grp((m) => m.s.reg ?? 'Sin región').sort((x, y) => y.m - x.m).map(track)} /></section>
        <section className="so-card"><h2>Por cadena</h2><Bars max={1.6} items={grp((m) => m.s.ch).sort((x, y) => y.p - x.p).map((o) => track({ ...o, label: chainOf(ds, o.k) }))} /></section>
        <section className="so-card wide"><h2>Salas más lejos de la meta</h2><p className="hint">Primero las de menor cumplimiento: son las visitas que más mueven el resultado.</p><div className="so-scroll"><table className="so-tbl">{head}<tbody>{sorted.slice(0, 10).map(row)}</tbody></table></div></section>
        <section className="so-card wide"><h2>Salas que mejor cumplen</h2><div className="so-scroll"><table className="so-tbl">{head}<tbody>{sorted.slice(-8).reverse().map(row)}</tbody></table></div></section>
      </div>
    </>
  );
}

// ───────────────────────── Alertas ─────────────────────────
export function AlertasView({ ctx, month }: { ctx: Ctx; month: number }) {
  const { ds, f } = ctx;
  const [all, setAll] = useState(true);
  const last = lastIdx(ds), [a0, b0] = periodWindow(ds, f.period);
  const PF = all ? null : prodSet(f, ds), chains = ds.chains.filter((c) => all || f.chains.has(c.id)), prods = ds.products.filter((p) => all || f.prods.has(p.id));
  const R = all ? ds.stores.map((s) => ({ s, cur: agg(ds, s, a0, b0, null), prev: null as null })) : rows(ds, f);
  const li = (r: { s: Store }, txt: ReactNode) => <li key={r.s.id}><button className="lk" onClick={() => ctx.open(r.s.id)}>{r.s.n} <span className="so-lbl">{chainOf(ds, r.s.ch)}</span></button><span className="num">{txt}</span></li>;

  const exp = chains.map((c) => { const st = R.filter((r) => r.s.ch === c.id); return { c, n0: st.filter((r) => agg(ds, r.s, last - 3, last - 3, PF).u > 0).length, n1: st.filter((r) => agg(ds, r.s, last, last, PF).u > 0).length }; });
  const q = R.map((r) => ({ r, prior: agg(ds, r.s, last - 3, last - 1, PF).s / 3, now: agg(ds, r.s, last, last, PF).s })).filter((x) => x.prior > 0 && x.now === 0).sort((x, y) => y.prior - x.prior);
  const g = R.map((r) => { const c = agg(ds, r.s, last - 2, last, PF).s, p = last - 14 >= 0 ? agg(ds, r.s, last - 14, last - 12, PF).s : 0; return { r, y: p > 0 ? c / p - 1 : null, p }; }).filter((x) => x.y !== null && x.p > 300000) as { r: { s: Store }; y: number; p: number }[];
  g.sort((x, y) => y.y - x.y);
  const metas = ds.stores.filter((s) => all || (f.chains.has(s.ch) && inReg(f, s.reg))).flatMap((s) => { const gl = goal(ds, s, month); return gl && gl.pct < 0.5 ? [{ s, ...gl }] : []; }).sort((x, y) => y.meta - x.meta);
  const mix = prods.map((p) => { let u1 = 0, u0 = 0; R.forEach((r) => { u1 += agg(ds, r.s, last - 2, last, new Set([p.id])).u; u0 += last - 5 >= 0 ? agg(ds, r.s, last - 5, last - 3, new Set([p.id])).u : 0; }); return { p, u1, u0 }; });
  const t1 = mix.reduce((x, y) => x + y.u1, 0) || 1, t0 = mix.reduce((x, y) => x + y.u0, 0) || 1;

  return (
    <>
      <div className="so-card" style={{ marginBottom: 12 }}>
        <div className="so-maphead" style={{ margin: 0 }}><span className="hint" style={{ margin: 0 }}>{all ? 'Alertas de todas las cadenas, regiones y productos.' : 'Alertas solo con los filtros actuales.'}</span>
          <div className="so-seg" role="group" aria-label="Alcance de las alertas"><button aria-pressed={all} onClick={() => setAll(true)}>Todas</button><button aria-pressed={!all} onClick={() => setAll(false)}>Con mis filtros</button></div></div>
      </div>
      <div className="so-alerts">
        <article className="so-alert"><Pill kind="good">▲ Oportunidad</Pill><h3>Distribución: salas con venta hoy vs hace 3 meses</h3><p>Más salas vendiendo es el primer motor de crecimiento.</p>
          <ul>{exp.map((e) => <li key={e.c.id}><span>{e.c.name}</span><span className={`num ${e.n1 > e.n0 ? 'up' : e.n1 < e.n0 ? 'down' : ''}`}>{e.n0} → {e.n1}</span></li>)}</ul></article>
        <article className="so-alert"><Pill kind="bad">▼ Revisar en sala</Pill><h3>{q.length} salas sin venta este mes</h3><p>Vendían en los 3 meses anteriores y hoy están en cero: posible quiebre de stock o delisting.</p>
          <ul>{q.slice(0, 6).map((x) => li(x.r, `${money(x.prior)}/mes antes`))}{!q.length && <li>Sin casos.</li>}</ul></article>
        <article className="so-alert"><Pill kind="good">▲ Líderes</Pill><h3>Salas que más crecen (3 meses vs año anterior)</h3><p>Para replicar su ejecución en otras salas.</p>
          <ul>{g.slice(0, 6).map((x) => li(x.r, sgn(x.y)))}{!g.length && <li>Sin base comparable.</li>}</ul></article>
        <article className="so-alert"><Pill kind="warn">▲ Atención</Pill><h3>Salas que más caen (3 meses vs año anterior)</h3><p>Caídas sobre una base relevante.</p>
          <ul>{[...g].reverse().filter((x) => x.y < 0).slice(0, 6).map((x) => li(x.r, sgn(x.y)))}{!g.some((x) => x.y < 0) && <li>Ninguna cae.</li>}</ul></article>
        <article className="so-alert"><Pill kind="bad">▼ Meta en riesgo</Pill><h3>{metas.length} salas bajo 50% de su meta de {monthLabel(ds.months[month] ?? '2000-01')}</h3><p>Ordenadas por tamaño de la meta, para priorizar la visita.</p>
          <ul>{metas.slice(0, 6).map((m) => <li key={m.s.id}><button className="lk" onClick={() => ctx.open(m.s.id)}>{m.s.n} <span className="so-lbl">{chainOf(ds, m.s.ch)}</span></button><span className="num">{pct(m.pct)} de {nf(m.meta, 1)} cj</span></li>)}{!metas.length && <li>Sin casos.</li>}</ul></article>
        <article className="so-alert"><Pill kind="good">● Mix</Pill><h3>Participación por producto: 3 meses vs 3 anteriores</h3><p>Qué producto gana o pierde espacio en sala.</p>
          <ul>{mix.map((x) => { const d = x.u1 / t1 - x.u0 / t0; return <li key={x.p.id}><span>{x.p.name}</span><span className={`num ${d > 0 ? 'up' : d < 0 ? 'down' : ''}`}>{pct(x.u1 / t1, 1)} <span className="muted">({d >= 0 ? '+' : '−'}{nf(Math.abs(d) * 100, 1)} pp)</span></span></li>; })}</ul></article>
      </div>
    </>
  );
}

export { ZONES, productColor, chainColor };
