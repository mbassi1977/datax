// Modelo de datos y cálculos del tablero Sell-out. El servidor entrega, por sala, la venta mensual por producto
// [venta, unidades, costo] y las metas por mes; aquí se agregan por período, filtros y comparaciones.

export type Product = { id: string; name: string; color: string | null; upb: number; image: string | null };
export type Chain = { id: string; name: string; color: string | null };
export type Store = {
  id: string; ch: string; code: string; n: string; reg: string | null; com: string | null; lat: number | null; lon: number | null; q: string; ad: string | null;
  d: Record<string, number[][]>; t: Record<string, number>;
};
export type DS = { client: { id: string }; months: string[]; chains: Chain[]; products: Product[]; stores: Store[] };

export type PeriodKey = 'm1' | 'm3' | 'ytd' | 'all';
export type Filters = { chains: Set<string>; regions: Set<string>; prods: Set<string>; period: PeriodKey };
export type Agg = { s: number; u: number; c: number; bx: number };
export type Row = { s: Store; cur: Agg; prev: Agg | null };

export const MONTH_ABBR = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
export const monthLabel = (ym: string) => `${MONTH_ABBR[+ym.slice(5, 7) - 1]} ${ym.slice(2, 4)}`;

/** Zonas geográficas para agrupar regiones. */
export const ZONES: Record<string, string[]> = {
  Norte: ['Arica y Parinacota', 'Tarapacá', 'Antofagasta', 'Atacama', 'Coquimbo'],
  Centro: ['Valparaíso', 'Metropolitana', "O'Higgins"],
  'Centro-Sur': ['Maule', 'Ñuble', 'Biobío'],
  Sur: ['Araucanía', 'Los Ríos', 'Los Lagos'],
  Austral: ['Aysén', 'Magallanes'],
};
export const REGION_ORDER = Object.values(ZONES).flat();

export const periodLabels = (ds: DS): Record<PeriodKey, string> => ({
  m1: monthLabel(ds.months[ds.months.length - 1] ?? '2000-01'), m3: '3 meses', ytd: ds.months.length ? ds.months[ds.months.length - 1].slice(0, 4) : 'Año', all: 'Todo',
});

export function periodWindow(ds: DS, p: PeriodKey): [number, number] {
  const last = ds.months.length - 1;
  if (p === 'm1') return [last, last];
  if (p === 'm3') return [Math.max(0, last - 2), last];
  if (p === 'ytd') { const y = ds.months[last]?.slice(0, 4); const i = ds.months.findIndex((m) => m.startsWith(y)); return [Math.max(0, i), last]; }
  return [0, last];
}

export const inReg = (f: Filters, r: string | null) => f.regions.size === 0 || (r !== null && f.regions.has(r));

/** Productos filtrados: null = todos. */
export const prodSet = (f: Filters, ds: DS): Set<string> | null => (f.prods.size === ds.products.length ? null : f.prods);

export function agg(ds: DS, st: Store, a: number, b: number, prods: Set<string> | null): Agg {
  let s = 0, u = 0, c = 0, bx = 0;
  const upb = new Map(ds.products.map((p) => [p.id, p.upb || 12]));
  for (const pid in st.d) {
    if (prods && !prods.has(pid)) continue;
    const d = st.d[pid], k = upb.get(pid) ?? 12;
    for (let i = a; i <= b; i++) { s += d[0][i] ?? 0; u += d[1][i] ?? 0; c += d[2][i] ?? 0; bx += (d[1][i] ?? 0) / k; }
  }
  return { s, u, c, bx };
}

export function monthly(ds: DS, st: Store, prods: Set<string> | null, k = 0): number[] {
  const o = new Array(ds.months.length).fill(0);
  for (const pid in st.d) { if (prods && !prods.has(pid)) continue; st.d[pid][k].forEach((v, i) => { o[i] += v; }); }
  return o;
}

export function rows(ds: DS, f: Filters): Row[] {
  const [a, b] = periodWindow(ds, f.period), pf = prodSet(f, ds);
  return ds.stores.filter((s) => f.chains.has(s.ch) && inReg(f, s.reg)).map((s) => ({
    s, cur: agg(ds, s, a, b, pf), prev: a - 12 >= 0 ? agg(ds, s, a - 12, b - 12, pf) : null,
  }));
}

export function totals(R: Row[]) {
  const t = { s: 0, u: 0, c: 0, bx: 0, active: 0, cs: 0, ps: 0 };
  for (const r of R) {
    t.s += r.cur.s; t.u += r.cur.u; t.c += r.cur.c; t.bx += r.cur.bx;
    if (r.cur.s > 0) t.active++;
    if (r.prev && r.prev.s > 0) { t.cs += r.cur.s; t.ps += r.prev.s; }
  }
  return { ...t, margin: t.s > 0 ? (t.s - t.c) / t.s : null, yoy: t.ps > 0 ? t.cs / t.ps - 1 : (null as number | null) };
}

export function groupBy<T>(items: T[], key: (t: T) => string) {
  const m = new Map<string, T[]>();
  items.forEach((i) => { const k = key(i); const a = m.get(k); a ? a.push(i) : m.set(k, [i]); });
  return m;
}

export const hav = (a: { lat: number; lon: number }, b: { lat: number; lon: number }) => {
  const t = Math.PI / 180, dl = (b.lat - a.lat) * t, dn = (b.lon - a.lon) * t;
  const x = Math.sin(dl / 2) ** 2 + Math.cos(a.lat * t) * Math.cos(b.lat * t) * Math.sin(dn / 2) ** 2;
  return 12742 * Math.asin(Math.sqrt(x));
};

// ───────── formato ─────────
export const nf = (v: number, d = 0) => v.toLocaleString('es-CL', { maximumFractionDigits: d, minimumFractionDigits: d });
export const money = (v: number) => (Math.abs(v) >= 1e6 ? `$${nf(v / 1e6, 1)} M` : Math.abs(v) >= 1e3 ? `$${nf(v / 1e3, 0)} mil` : `$${nf(v)}`);
export const pct = (v: number, d = 0) => `${nf(v * 100, d)}%`;
export const sgn = (v: number) => `${v >= 0 ? '+' : '−'}${nf(Math.abs(v) * 100, 1)}%`;

export type Sema = 'good' | 'warn' | 'bad';
export const sema = (p: number): Sema => (p >= 1 ? 'good' : p >= 0.7 ? 'warn' : 'bad');
export const SEMA_TXT: Record<Sema, string> = { good: '● Cumple', warn: '▲ Cerca', bad: '▼ Bajo meta' };

/** Cumplimiento de una sala en un mes (índice): real en cajas (todos los productos) contra la meta. */
export function goal(ds: DS, st: Store, mi: number): { meta: number; real: number; pct: number } | null {
  const meta = st.t[mi];
  if (!meta || meta <= 0) return null;
  const real = agg(ds, st, mi, mi, null).bx;
  return { meta, real, pct: real / meta };
}

/** Colores por defecto para cadenas y productos sin color propio (paleta categórica validada, en orden). */
export const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'];
export const chainColor = (ds: DS, id: string) => ds.chains.find((c) => c.id === id)?.color ?? SERIES[Math.max(0, ds.chains.findIndex((c) => c.id === id)) % SERIES.length];
export const productColor = (ds: DS, id: string) => ds.products.find((p) => p.id === id)?.color ?? SERIES[Math.max(0, ds.products.findIndex((p) => p.id === id)) % SERIES.length];
