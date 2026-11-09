import { ReactNode, useEffect, useRef, useState } from 'react';
import { DS, Filters, PeriodKey, REGION_ORDER, ZONES, chainColor, periodLabels } from './model';
import { Logo, ProductAvatar } from './charts';

type Pop = { kind: 'chain' | 'prod' | 'regions'; key: string; l: number; b: number } | null;

/** Filtros del tablero: cadenas, regiones y productos con selección múltiple («solo este», quitar, todas) y período. */
export function FilterBar({ ds, f, setF, regionsAvailable, tabs }: { ds: DS; f: Filters; setF: (f: Filters) => void; regionsAvailable: string[]; tabs: ReactNode }) {
  const [pop, setPop] = useState<Pop>(null);
  const [open, setOpen] = useState(false);          // filtros desplegados en pantallas chicas
  const popRef = useRef<HTMLDivElement>(null);
  const ordered = REGION_ORDER.filter((r) => regionsAvailable.includes(r)).concat(regionsAvailable.filter((r) => !REGION_ORDER.includes(r)));

  useEffect(() => {
    if (!pop) return;
    const down = (e: MouseEvent) => { if (!popRef.current?.contains(e.target as Node) && !(e.target as HTMLElement).closest('[data-chip]')) setPop(null); };
    const key = (e: KeyboardEvent) => e.key === 'Escape' && setPop(null);
    document.addEventListener('mousedown', down); document.addEventListener('keydown', key);
    return () => { document.removeEventListener('mousedown', down); document.removeEventListener('keydown', key); };
  }, [pop]);

  const openPop = (e: React.MouseEvent, kind: 'chain' | 'prod' | 'regions', key: string) => {
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
    setPop((p) => (p && p.kind === kind && p.key === key ? null : { kind, key, l: r.left, b: r.bottom }));
  };

  const setSet = (kind: 'chain' | 'prod', next: Set<string>) => setF(kind === 'chain' ? { ...f, chains: next } : { ...f, prods: next });
  const act = (a: 'solo' | 'toggle' | 'all') => {
    if (!pop || pop.kind === 'regions') return;
    const all = pop.kind === 'chain' ? ds.chains.map((c) => c.id) : ds.products.map((p) => p.id), cur = pop.kind === 'chain' ? f.chains : f.prods;
    if (a === 'solo') setSet(pop.kind, new Set([pop.key]));
    else if (a === 'all') setSet(pop.kind, new Set(all));
    else { const n = new Set(cur); n.has(pop.key) ? n.size > 1 && n.delete(pop.key) : n.add(pop.key); setSet(pop.kind, n); }
    setPop(null);
  };
  const regAct = (a: string, key = '') => {
    let n = new Set(f.regions);
    if (a === 'all') n = new Set();
    else if (a === 'zone') n = new Set(ZONES[key].filter((r) => ordered.includes(r)));
    else if (a === 'solo') n = new Set([key]);
    else { const cur = f.regions.size === 0 ? new Set(ordered) : new Set(f.regions); cur.has(key) ? cur.delete(key) : cur.add(key); n = cur.size === 0 || cur.size === ordered.length ? new Set() : cur; }
    setF({ ...f, regions: n });
  };

  const regLabel = f.regions.size === 0 ? 'Todo Chile' : f.regions.size === 1 ? [...f.regions][0] : `${f.regions.size} regiones`;
  const nActive = Number(f.chains.size < ds.chains.length) + Number(f.regions.size > 0) + Number(f.prods.size < ds.products.length);
  const labels = periodLabels(ds);
  const itemName = pop && pop.kind !== 'regions' ? (pop.kind === 'chain' ? ds.chains.find((c) => c.id === pop.key)?.name : ds.products.find((p) => p.id === pop.key)?.name) ?? '' : '';
  const isOn = pop && pop.kind !== 'regions' ? (pop.kind === 'chain' ? f.chains : f.prods).has(pop.key) : false;

  return (
    <div className="so-bar">
      <div className="so-row">
        <button className="so-only-m" aria-expanded={open} onClick={() => setOpen(!open)}>Filtros{nActive ? ` · ${nActive} activo${nActive > 1 ? 's' : ''}` : ''} {open ? '▴' : '▾'}</button>
        <div className="so-seg" role="group" aria-label="Período">
          {(Object.keys(labels) as PeriodKey[]).map((k) => <button key={k} aria-pressed={f.period === k} onClick={() => setF({ ...f, period: k })}>{labels[k]}</button>)}
        </div>
      </div>
      <div className={`so-filters${open ? ' open' : ''}`}>
        <div className="so-chips" role="group" aria-label="Cadenas">
          {ds.chains.map((c) => (
            <button key={c.id} data-chip className="so-chip" aria-pressed={f.chains.has(c.id)} aria-haspopup="true" onClick={(e) => openPop(e, 'chain', c.id)}>
              {c.logo ? <Logo src={c.logo} name={c.name} color={chainColor(ds, c.id)} size={22} /> : <i style={{ background: chainColor(ds, c.id) }} />}{c.name}
            </button>
          ))}
        </div>
        <div className="so-chips"><button data-chip className="so-chip" aria-pressed={f.regions.size > 0} aria-haspopup="true" onClick={(e) => openPop(e, 'regions', 'all')}>Región: {regLabel} ▾</button></div>
        <div className="so-chips" role="group" aria-label="Productos">
          {ds.products.map((p) => (
            <button key={p.id} data-chip className="so-chip" aria-pressed={f.prods.has(p.id)} aria-haspopup="true" onClick={(e) => openPop(e, 'prod', p.id)}>
              <ProductAvatar p={p} ds={ds} h={26} />{p.name}
            </button>
          ))}
        </div>
      </div>
      {tabs}
      {pop && (
        <div ref={popRef} className="so-pop" style={{ left: Math.max(8, Math.min(pop.l, window.innerWidth - 300)), top: Math.min(pop.b + 6, window.innerHeight - 380) }}>
          {pop.kind === 'regions' ? (
            <>
              <div className="pt">Regiones y zonas</div>
              <div className="zrow">{Object.keys(ZONES).map((z) => <button key={z} className="so-chip" onClick={() => regAct('zone', z)}>{z}</button>)}<button className="so-chip" onClick={() => regAct('all')}>Todo Chile</button></div>
              {ordered.map((r) => {
                const on = f.regions.size === 0 || f.regions.has(r);
                return <div key={r} className="prow"><button onClick={() => regAct('toggle', r)} aria-pressed={on}><span className="ck">{on ? '☑' : '☐'}</span> {r}</button><button className="lk" onClick={() => regAct('solo', r)}>solo</button></div>;
              })}
            </>
          ) : (
            <>
              <div className="pt">{itemName}</div>
              <button onClick={() => act('solo')}>Solo {itemName}</button>
              <button onClick={() => act('toggle')}>{isOn ? 'Quitar' : 'Agregar'} {itemName}</button>
              <button onClick={() => act('all')}>{pop.kind === 'chain' ? 'Todas las cadenas' : 'Todos los productos'}</button>
            </>
          )}
        </div>
      )}
    </div>
  );
}

/** Para que el mapa de Chile y las barras de región cambien el filtro igual que el menú. */
export function toggleRegion(f: Filters, key: string, all: string[]): Filters {
  if (f.regions.size === 0) return { ...f, regions: new Set([key]) };
  const cur = new Set(f.regions); cur.has(key) ? cur.delete(key) : cur.add(key);
  return { ...f, regions: cur.size === 0 || cur.size === all.length ? new Set() : cur };
}
