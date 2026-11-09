import { useEffect, useState } from 'react';
import { api, Me, upload } from '../api';
import { can } from '../ResourcePage';
import { formatDateTime } from '../fmt';
import { DS, Filters, Store, agg, chainColor, logoOf, goal, money, monthly, nf, pct, periodLabels, periodWindow, prodSet, productColor, sgn, SEMA_TXT, sema } from './model';
import { BarItem, Bars, Logo, useTip } from './charts';
import { RequestBox } from './Requests';

type Detail = { photos: { id: string; url: string; caption: string | null; by: string | null; at: string }[]; notes: { id: string; body: string; by: string | null; at: string }[] };

/** Ficha de una sala: indicadores, evolución, mix, fotos y comentarios del supervisor, y solicitudes del cliente. */
export function Ficha({ me, ds, f, store, clientId, monthIdx, onClose, onNear }: { me: Me; ds: DS; f: Filters; store: Store; clientId: string; monthIdx: number; onClose: () => void; onNear: (id: string) => void }) {
  const [detail, setDetail] = useState<Detail>({ photos: [], notes: [] });
  const [note, setNote] = useState('');
  const [err, setErr] = useState('');
  const [busy, setBusy] = useState(false);
  const { bind, el } = useTip();
  const field = can(me, 'sellout', 'campo');
  const load = () => api<Detail>('GET', `sellout/points/${store.id}/detail`).then(setDetail).catch((e) => setErr(e.message));
  useEffect(() => { setDetail({ photos: [], notes: [] }); setErr(''); load(); }, [store.id]);   // eslint-disable-line react-hooks/exhaustive-deps

  const [a, b] = periodWindow(ds, f.period), pf = prodSet(f, ds), cur = agg(ds, store, a, b, pf), prev = a - 12 >= 0 ? agg(ds, store, a - 12, b - 12, pf) : null;
  const y = prev && prev.s > 0 ? cur.s / prev.s - 1 : null, mg = cur.s > 0 ? (cur.s - cur.c) / cur.s : null, g = goal(ds, store, monthIdx);
  const peers = ds.stores.filter((s) => s.ch === store.ch).map((s) => ({ id: s.id, v: agg(ds, s, a, b, pf).s })).sort((x, z) => z.v - x.v), rk = peers.findIndex((p) => p.id === store.id) + 1;
  const ms = monthly(ds, store, pf, 0), mx = Math.max(...ms, 1), W = 340, H = 110, bw = (W - 8) / Math.max(ms.length, 1);
  const mix = ds.products.filter((p) => f.prods.has(p.id)).map((p) => ({ p, u: agg(ds, store, a, b, new Set([p.id])).u })).filter((m) => m.u > 0).sort((x, z) => z.u - x.u), tu = mix.reduce((s, m) => s + m.u, 0) || 1;

  const addPhoto = async (files: FileList | null) => {
    if (!files?.length) return; setBusy(true); setErr('');
    try { for (const file of Array.from(files)) { const fd = new FormData(); fd.append('file', file); await upload(`sellout/points/${store.id}/photos`, fd); } await load(); } catch (e) { setErr((e as Error).message); } finally { setBusy(false); }
  };
  const delPhoto = async (id: string) => { try { await api('DELETE', `sellout/photos/${id}`); await load(); } catch (e) { setErr((e as Error).message); } };
  const addNote = async () => { if (!note.trim()) return; try { await api('POST', `sellout/points/${store.id}/notes`, { body: note.trim() }); setNote(''); await load(); } catch (e) { setErr((e as Error).message); } };

  return (
    <aside className="so-drawer" aria-label={`Ficha de ${store.n}`}>
      <button className="so-x" onClick={onClose} aria-label="Cerrar ficha">✕</button>
      <div className="so-fhead"><Logo src={logoOf(ds, store)} name={ds.chains.find((c) => c.id === store.ch)?.name ?? ''} color={chainColor(ds, store.ch)} size={44} /><span className="so-lbl">{ds.chains.find((c) => c.id === store.ch)?.name} · cód. {store.code}</span></div>
      <h3>{store.n}</h3>
      <div className="hint">{store.ad ? `${store.ad} · ` : store.com ? `${store.com} · ` : ''}{store.reg}{store.q === 'name' || store.q === 'none' ? ' · ubicación aproximada' : store.q === 'commune' ? ' · ubicada por comuna' : ''}</div>
      <div className="so-mini">
        <div><div className="so-lbl">Venta {periodLabels(ds)[f.period]}</div><div className="v num">{money(cur.s)}</div></div>
        <div><div className="so-lbl">vs año anterior</div><div className={`v num ${y === null ? '' : y >= 0 ? 'up' : 'down'}`}>{y === null ? '—' : sgn(y)}</div></div>
        <div><div className="so-lbl">Cajas</div><div className="v num">{nf(cur.bx, 1)}</div></div>
        <div><div className="so-lbl">Margen</div><div className="v num">{mg === null ? '—' : pct(mg)}</div></div>
        {g && <div style={{ gridColumn: '1 / -1' }}><div className="so-lbl">Meta del mes</div><div className="v num">{pct(g.pct)} <span className={`so-pill ${sema(g.pct)}`}>{SEMA_TXT[sema(g.pct)]}</span> <span className="muted" style={{ fontSize: 12 }}>{nf(g.real, 1)} de {nf(g.meta, 1)} cajas</span></div></div>}
      </div>
      <div className="hint">Puesto {rk} de {peers.length} salas de la cadena por venta del período.</div>
      <div style={{ margin: '8px 0' }}><button className="primary" disabled={store.lat == null} onClick={() => onNear(store.id)}>Ver entorno · salas más cercanas</button></div>
      <svg viewBox={`0 0 ${W} ${H}`} role="img" aria-label="Venta mensual de la sala" className="so-svg">
        {ms.map((v, i) => { const h = v / mx * (H - 22); return <rect key={i} x={4 + i * bw + 1} y={H - 16 - h} width={bw - 2} height={Math.max(h, 0.5)} rx="2" fill={chainColor(ds, store.ch)} opacity={i >= a && i <= b ? 1 : 0.45} {...bind(<><b>{ds.months[i]}</b><div>{money(v)}</div></>)} />; })}
        <text x="4" y={H - 3}>{ds.months[0]}</text><text x={W - 4} y={H - 3} textAnchor="end">{ds.months[ds.months.length - 1]}</text>
      </svg>
      <div className="so-lbl" style={{ marginTop: 8 }}>Mix del período</div>
      <Bars items={mix.map<BarItem>((m) => ({ key: m.p.id, label: m.p.name, value: m.u, color: productColor(ds, m.p.id), display: pct(m.u / tu) }))} />

      <div className="so-lbl" style={{ marginTop: 14 }}>Fotos de la sala</div>
      <div className="so-photos">
        {detail.photos.map((p) => <figure key={p.id}><img src={p.url} alt={p.caption ?? 'Foto de la sala'} /><figcaption>{formatDateTime(p.at)}{field && <button className="lk" onClick={() => delPhoto(p.id)}>quitar</button>}</figcaption></figure>)}
        {detail.photos.length === 0 && <div className="ph">Sin fotos todavía</div>}
      </div>
      {field ? <label className="btn">＋ Agregar foto<input type="file" accept="image/*" multiple hidden disabled={busy} onChange={(e) => { addPhoto(e.target.files); e.target.value = ''; }} /></label> : <p className="hint">Solo el supervisor sube fotos y comentarios.</p>}

      <div className="so-lbl" style={{ marginTop: 14 }}>Comentarios del supervisor</div>
      {detail.notes.map((n) => <div key={n.id} className="so-note">{n.body}<br /><span className="so-lbl">{formatDateTime(n.at)}{n.by ? ` · ${n.by}` : ''}</span></div>)}
      {detail.notes.length === 0 && <p className="hint">Sin comentarios todavía.</p>}
      {field && <><textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="Ej.: góndola completa, falta exhibición en cabecera" /><div style={{ marginTop: 6 }}><button className="primary" onClick={addNote}>Guardar comentario</button></div></>}
      {err && <p className="error">{err}</p>}

      <RequestBox me={me} clientId={clientId} scope="point" pointId={store.id} label={store.n} />
      {el}
    </aside>
  );
}
