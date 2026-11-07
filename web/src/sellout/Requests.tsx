import { useCallback, useEffect, useState } from 'react';
import { api, Me, upload } from '../api';
import { can } from '../ResourcePage';
import { formatDateTime } from '../fmt';

export type Req = {
  id: string; scope: 'point' | 'zone'; point_id: string | null; label: string; body: string; status: 'pending' | 'attended' | 'closed';
  response: string | null; response_photo: string | null; created_by: string | null; created_at: string; responded_at: string | null; log: { at: string; by: string; text: string }[];
};

const EVT = 'so-requests';
const bump = () => window.dispatchEvent(new Event(EVT));

export function useRequests(clientId: string) {
  const [list, setList] = useState<Req[]>([]);
  const [error, setError] = useState('');
  const load = useCallback(() => { api<Req[]>('GET', `sellout/requests?organization_id=${clientId}`).then(setList).catch((e) => setError(e.message)); }, [clientId]);
  useEffect(() => { load(); window.addEventListener(EVT, load); return () => window.removeEventListener(EVT, load); }, [load]);
  return { list, error, load };
}

export const pendingCount = (list: Req[], me: Me) => list.filter((r) => r.status === (can(me, 'sellout_solicitudes', 'atender') ? 'pending' : 'attended')).length;

const STATUS: Record<Req['status'], { text: string; cls: string }> = { pending: { text: '⏳ Pendiente', cls: 'warn' }, attended: { text: '✓ Atendida', cls: 'good' }, closed: { text: '● Cerrada', cls: 'good' } };

/** Una solicitud con su ciclo: el supervisor responde (explicando qué hizo), el cliente confirma o reabre. */
export function RequestItem({ me, r, showLabel, onOpenPoint }: { me: Me; r: Req; showLabel?: boolean; onOpenPoint?: (id: string) => void }) {
  const [form, setForm] = useState(false);
  const [text, setText] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const attend = can(me, 'sellout_solicitudes', 'atender'), create = can(me, 'sellout_solicitudes', 'crear');
  const st = STATUS[r.status];

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    setBusy(true); setMsg(null);
    try { await fn(); setMsg({ ok: true, text: ok }); setForm(false); setText(''); setFile(null); bump(); } catch (e) { setMsg({ ok: false, text: (e as Error).message }); } finally { setBusy(false); }
  };
  const respond = () => {
    if (!text.trim()) { setMsg({ ok: false, text: 'Escriba qué se hizo antes de marcar la solicitud como atendida.' }); return; }
    const fd = new FormData(); fd.append('response', text.trim()); if (file) fd.append('file', file);
    run(() => upload(`sellout/requests/${r.id}/respond`, fd), 'Solicitud atendida. El cliente recibe un correo con tu respuesta y puede confirmarla o reabrirla.');
  };

  return (
    <div className="so-req">
      <div className="so-reqh"><span className={`so-pill ${st.cls}`}>{st.text}</span><span className="so-lbl">{formatDateTime(r.created_at)}{r.created_by ? ` · ${r.created_by}` : ''}</span></div>
      {showLabel && <div className="rl">{r.scope === 'point' && r.point_id && onOpenPoint ? <button className="lk" onClick={() => onOpenPoint(r.point_id!)}>{r.label}</button> : r.label} <span className="so-lbl">{r.scope === 'point' ? 'sala' : 'zona'}</span></div>}
      <div>{r.body}</div>
      {r.response && (
        <div className="so-resp"><div className="so-lbl">Respuesta del supervisor{r.responded_at ? ` · ${formatDateTime(r.responded_at)}` : ''}</div>{r.response}{r.response_photo && <img src={r.response_photo} alt="Evidencia de la atención" />}</div>
      )}
      {r.status === 'pending' && attend && (form ? (
        <div className="so-respform">
          <div className="so-lbl">Qué se hizo (obligatorio)</div>
          <textarea value={text} onChange={(e) => setText(e.target.value)} placeholder="Ej.: se repuso producto, se rearmó la exhibición, se habló con el jefe de sala…" />
          <label className="btn">＋ Foto de evidencia (opcional)<input type="file" accept="image/*" hidden onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></label>{file && <span className="so-lbl"> {file.name}</span>}
          <p className="hint" style={{ margin: '8px 0' }}>El cliente recibe esta respuesta y puede confirmarla o reabrir la solicitud.</p>
          <div className="so-acts"><button className="primary" disabled={busy} onClick={respond}>Confirmar atención</button><button onClick={() => setForm(false)}>Cancelar</button></div>
        </div>
      ) : <div className="so-acts"><button className="primary" onClick={() => setForm(true)}>Responder y marcar atendida</button></div>)}
      {r.status === 'attended' && create && !attend && (
        <div className="so-acts"><button className="primary" disabled={busy} onClick={() => run(() => api('POST', `sellout/requests/${r.id}/close`), 'Solicitud cerrada.')}>Conforme, cerrar</button>
          <button disabled={busy} onClick={() => run(() => api('POST', `sellout/requests/${r.id}/reopen`, {}), 'Solicitud reabierta: vuelve a quedar pendiente.')}>Reabrir</button></div>
      )}
      {r.status === 'attended' && attend && <p className="hint" style={{ margin: '6px 0 0' }}>Esperando la confirmación del cliente.</p>}
      {msg && <p className={msg.ok ? 'ok' : 'error'}>{msg.text}</p>}
      {r.log.length > 1 && <details><summary>Historial ({r.log.length})</summary><ul className="so-log">{r.log.map((l, i) => <li key={i}><span className="num">{formatDateTime(l.at)}</span> {l.text}</li>)}</ul></details>}
    </div>
  );
}

/** Solicitudes de una sala o de una zona + formulario para el perfil cliente. */
export function RequestBox({ me, clientId, scope, pointId, zoneLabel, label }: { me: Me; clientId: string; scope: 'point' | 'zone'; pointId?: string; zoneLabel?: string; label?: string }) {
  const { list } = useRequests(clientId);
  const [text, setText] = useState('');
  const [err, setErr] = useState('');
  const mine = list.filter((r) => (scope === 'point' ? r.point_id === pointId : r.scope === 'zone' && r.label === zoneLabel));
  const send = async () => {
    if (!text.trim()) { setErr('Escriba la solicitud antes de enviarla.'); return; }
    setErr('');
    try { await api('POST', 'sellout/requests', { organization_id: clientId, scope, point_id: pointId, zone_label: zoneLabel, body: text.trim() }); setText(''); bump(); } catch (e) { setErr((e as Error).message); }
  };
  return (
    <div>
      <div className="so-lbl" style={{ marginTop: 8 }}>Solicitudes del cliente</div>
      {mine.length === 0 ? <p className="hint">Sin solicitudes.</p> : mine.map((r) => <RequestItem key={r.id} me={me} r={r} />)}
      {can(me, 'sellout_solicitudes', 'crear') && (
        <>
          <textarea value={text} onChange={(e) => setText(e.target.value)} placeholder={`Escriba una solicitud u observación para ${label ?? zoneLabel ?? 'la sala'}`} />
          {err && <p className="error">{err}</p>}
          <div style={{ marginTop: 6 }}><button className="primary" onClick={send}>Enviar solicitud</button></div>
        </>
      )}
    </div>
  );
}

export function RequestsTab({ me, clientId, onOpenPoint }: { me: Me; clientId: string; onOpenPoint: (id: string) => void }) {
  const { list, error } = useRequests(clientId);
  const n = (s: Req['status']) => list.filter((r) => r.status === s).length;
  const attend = can(me, 'sellout_solicitudes', 'atender');
  return (
    <>
      <div className="so-kpis">
        <div className="so-kpi"><div className="so-lbl">Pendientes</div><div className="v num">{n('pending')}</div><div className="d">{attend ? 'por atender' : 'esperando al supervisor'}</div></div>
        <div className="so-kpi"><div className="so-lbl">Atendidas</div><div className="v num">{n('attended')}</div><div className="d">{attend ? 'esperan al cliente' : 'por confirmar por usted'}</div></div>
        <div className="so-kpi"><div className="so-lbl">Cerradas</div><div className="v num">{n('closed')}</div><div className="d">conformidad del cliente</div></div>
      </div>
      <section className="so-card" style={{ marginTop: 16 }}>
        <h2>Solicitudes y observaciones del cliente</h2>
        <p className="hint">{attend ? 'Responda cada solicitud explicando qué se hizo, con foto opcional. El cliente la confirma o la reabre.' : 'Envíe solicitudes desde la ficha de una sala o desde el mapa (para una zona), y confirme o reabra las que el supervisor atiende.'}</p>
        {error && <p className="error">{error}</p>}
        {list.map((r) => <RequestItem key={r.id} me={me} r={r} showLabel onOpenPoint={onOpenPoint} />)}
        {list.length === 0 && <p className="hint">Sin solicitudes.</p>}
      </section>
    </>
  );
}
