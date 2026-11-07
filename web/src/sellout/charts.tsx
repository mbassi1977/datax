import { ReactNode, useId, useState } from 'react';
import { DS, Product, Row, SERIES, chainColor, lastIdx, monthLabel, money, monthly, nf, pct, productColor, prodSet, Filters, periodWindow } from './model';

// ───────── tooltip ─────────
export function useTip() {
  const [tip, setTip] = useState<{ x: number; y: number; body: ReactNode } | null>(null);
  const bind = (body: ReactNode) => ({
    onPointerMove: (e: React.PointerEvent) => setTip({ x: e.clientX, y: e.clientY, body }),
    onPointerLeave: () => setTip(null),
  });
  const el = tip && (
    <div className="so-tip" style={{ left: Math.min(tip.x + 14, window.innerWidth - 240), top: Math.min(tip.y + 14, window.innerHeight - 100) }}>{tip.body}</div>
  );
  return { bind, el };
}

const niceMax = (v: number) => { const p = Math.pow(10, Math.floor(Math.log10(v || 1))); const n = v / p; return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p; };

/** Venta mensual por cadena (barras apiladas con tope redondeado). La franja marca el período elegido. */
export function StackedBars({ ds, R, f }: { ds: DS; R: Row[]; f: Filters }) {
  const { bind, el } = useTip();
  const W = 1100, H = 300, L = 52, B = 26, T = 8, RR = 6, pw = W - L - RR, ph = H - T - B, n = lastIdx(ds) + 1;
  const chains = ds.chains.filter((c) => f.chains.has(c.id)), pf = prodSet(f, ds);
  const data = chains.map((c) => { const arr = new Array(n).fill(0); R.forEach((r) => { if (r.s.ch === c.id) monthly(ds, r.s, pf, 0).forEach((v, i) => { arr[i] += v; }); }); return { c, arr }; });
  const tot = new Array(n).fill(0); data.forEach((d) => d.arr.forEach((v, i) => { tot[i] += v; }));
  const mx = niceMax(Math.max(...tot, 1)), bw = pw / Math.max(n, 1), [a, b] = periodWindow(ds, f.period);
  return (
    <div>
      <div className="so-legend">{chains.map((c) => <span key={c.id}><i style={{ background: chainColor(ds, c.id) }} />{c.name}</span>)}</div>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Venta mensual por cadena" className="so-svg">
        {[0, 1, 2, 3, 4].map((k) => { const y = T + ph - ph * k / 4; return <g key={k}><line x1={L} x2={W - RR} y1={y} y2={y} className="so-grid" /><text x={L - 6} y={y + 4} textAnchor="end">{nf(mx * k / 4 / 1e6, 0)} M</text></g>; })}
        <rect x={L + a * bw} y={T} width={(b - a + 1) * bw} height={ph} className="so-band" />
        {ds.months.slice(0, n).map((m, i) => {
          const x = L + i * bw + bw * 0.14, w = bw * 0.72, seg = data.filter((d) => d.arr[i] > 0), last = seg[seg.length - 1];
          let y = T + ph;
          const tipBody = <><b>{monthLabel(m)}</b>{data.map((d) => <div key={d.c.id}>{d.c.name}: {money(d.arr[i])}</div>)}<div>Total: {money(tot[i])}</div></>;
          return (
            <g key={m} {...bind(tipBody)}>
              {data.map((d) => {
                const h = d.arr[i] / mx * ph; if (h <= 0) return null;
                y -= h; const hh = Math.max(h - 1.5, 0.5), r = Math.min(4, hh / 2, w / 2), col = chainColor(ds, d.c.id);
                return d === last
                  ? <path key={d.c.id} d={`M${x} ${y + hh}V${y + r}Q${x} ${y} ${x + r} ${y}H${x + w - r}Q${x + w} ${y} ${x + w} ${y + r}V${y + hh}Z`} fill={col} />
                  : <rect key={d.c.id} x={x} y={y} width={w} height={hh} fill={col} />;
              })}
              <rect x={L + i * bw} y={T} width={bw} height={ph} fill="transparent" />
              {(i % 3 === 0 || i === n - 1) && <text x={x + w / 2} y={H - 8} textAnchor="middle">{monthLabel(m)}</text>}
            </g>
          );
        })}
      </svg>
      {el}
    </div>
  );
}

export type BarItem = { key: string; label: string; value: number; color?: string; on?: boolean; tip?: ReactNode; display: string; onClick?: () => void; mark?: number };

/** Barras horizontales redondeadas. `mark` dibuja una marca vertical (p. ej. el 100% de una meta), con `value` normalizado a `max`. */
export function Bars({ items, max }: { items: BarItem[]; max?: number }) {
  const { bind, el } = useTip();
  const mx = max ?? Math.max(...items.map((i) => i.value), 1);
  return (
    <div>
      {items.map((i) => {
        const inner = (
          <>
            <span className="nm">{i.label}</span>
            <span className="track"><i style={{ width: `${Math.max(1, i.value / mx * 100)}%`, background: i.color }} />{i.mark !== undefined && <b style={{ left: `${i.mark / mx * 100}%` }} />}</span>
            <span className="num">{i.display}</span>
          </>
        );
        return i.onClick
          ? <button key={i.key} className={`so-brow${i.on ? ' on' : ''}`} onClick={i.onClick} {...(i.tip ? bind(i.tip) : {})}>{inner}</button>
          : <div key={i.key} className="so-brow" {...(i.tip ? bind(i.tip) : {})}>{inner}</div>;
      })}
      {el}
    </div>
  );
}

/** Lata genérica dibujada a mano (trazo tipo tiza): se usa cuando el producto aún no tiene su diseño cargado. */
export function SketchCan({ color, level = 0.6, w = 44, h = 112, label = 'Beer' }: { color: string; level?: number; w?: number; h?: number; label?: string }) {
  const id = useId().replace(/:/g, ''), lvl = Math.max(0.05, Math.min(1, level)), top = 118 - lvl * 86;
  const body = 'M12 31Q12 25 17 23L18 21H46L47 23Q52 25 52 31V108Q52 117 43 117H21Q12 117 12 108Z';
  const hatchL = Array.from({ length: 13 }, (_, i) => `M${13.2 + (i % 2) * 0.6} ${36 + i * 6.4}l5 -${3.6 + (i % 3)}`).join('');
  const hatchR = Array.from({ length: 12 }, (_, i) => `M45.4 ${40 + i * 6.4}l5 -${3 + (i % 3)}`).join('');
  const hatchM = Array.from({ length: 7 }, (_, i) => `M21 ${57 + i * 5.2}l${10 + (i % 3) * 2} -${6 + (i % 2)}`).join('');
  return (
    <svg viewBox="10.5 16 43 104" preserveAspectRatio="none" width={w} height={h} aria-hidden="true" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round">
      <defs><clipPath id={`cp${id}`}><path d={body} /></clipPath></defs>
      <g clipPath={`url(#cp${id})`}><rect x="10" y={top} width="44" height={120 - top} fill={color} stroke="none" opacity=".38" /></g>
      <path d={body} strokeWidth="1.5" opacity=".9" />
      <path d="M13.5 31Q13.5 26 18 24.5M50.5 31Q50.5 26 46 24.5" strokeWidth=".7" opacity=".6" />
      <ellipse cx="32" cy="21" rx="15" ry="3.6" strokeWidth="1.3" /><ellipse cx="32" cy="20.6" rx="11" ry="2.2" strokeWidth=".8" opacity=".8" /><path d="M28 19.4q4 -1.6 8 0" strokeWidth=".8" />
      <path d={hatchL + hatchR} strokeWidth=".7" opacity=".55" />
      <path d="M23 29V50M26 29V46M40 29V44M43 31V52M24 94V108M42 92V108" strokeWidth=".7" opacity=".5" />
      <rect x="17" y="52" width="30" height="40" rx="9" strokeWidth="1.2" opacity=".9" /><rect x="18.4" y="53.4" width="27.2" height="37.2" rx="8" strokeWidth=".5" opacity=".5" />
      <path d={hatchM} strokeWidth=".5" opacity=".35" />
      <text x="32" y="75.5" textAnchor="middle" stroke="none" style={{ font: "italic 11px Georgia,'Times New Roman',serif", fill: 'currentColor' }}>{label}</text>
      <path d="M15 111q17 4 34 0" strokeWidth=".8" opacity=".6" /><path d="M13 113.5q19 5 38 0" strokeWidth=".6" opacity=".45" />
    </svg>
  );
}

/** Avatar de producto: su diseño si existe, si no la lata genérica. */
export function ProductAvatar({ p, ds, h = 26 }: { p: Product; ds: DS; h?: number }) {
  return p.image ? <img className="so-pav" src={p.image} alt={p.name} style={{ height: h }} /> : <SketchCan color={productColor(ds, p.id)} level={0.6} w={Math.round(h * 0.39)} h={h} />;
}

/** Mix de producto: cada lata con su barra de peso (tocar una deja solo ese producto). */
export function Cans({ ds, items, total, onSolo }: { ds: DS; items: { p: Product; u: number; s: number }[]; total: number; onSolo: (id: string) => void }) {
  const { bind, el } = useTip(), mx = Math.max(...items.map((i) => i.u), 1), H = 112;
  return (
    <div className="so-cans">
      {items.map(({ p, u, s }) => (
        <button key={p.id} className="so-can" onClick={() => onSolo(p.id)} {...bind(<><b>{p.name}</b><div>{nf(u)} unidades · {money(s)}</div><div>Toque para ver solo este producto</div></>)}>
          <span className="cimg">
            {p.image ? <img className="so-pav" src={p.image} alt={p.name} style={{ height: H }} /> : <SketchCan color={productColor(ds, p.id)} level={u / mx} w={Math.round(H * 0.39)} h={H} />}
            <span className="share"><i style={{ width: `${Math.max(4, u / mx * 100)}%`, background: productColor(ds, p.id) }} /></span>
          </span>
          <span className="cn">{p.name}</span>
          <span className="num">{pct(u / (total || 1), 1)}</span>
        </button>
      ))}
      {el}
    </div>
  );
}

export { SERIES };
