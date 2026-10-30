import { PointerEvent as RPointerEvent, useCallback, useEffect, useRef, useState } from 'react';

type Sel = { x: number; y: number; s: number };
type Corner = 'tl' | 'tr' | 'bl' | 'br';

const VIEW = 340;          // lado máximo del área de edición (px CSS)
const MIN = 48;            // tamaño mínimo del recorte (px CSS)
const MAX_SIDE = 2000;     // las fotos de celular se reducen al cargarlas
const OUT = 512;           // lado de la imagen resultante (el servidor la lleva a 256)

const cameraAvailable = () => typeof navigator !== 'undefined' && window.isSecureContext && !!navigator.mediaDevices?.getUserMedia;

/** Rota una imagen en pasos de 90° y la devuelve en un canvas nuevo. */
function rotateCanvas(src: HTMLCanvasElement, quarter: number): HTMLCanvasElement {
  const q = ((quarter % 4) + 4) % 4;
  const c = document.createElement('canvas');
  c.width = q % 2 ? src.height : src.width;
  c.height = q % 2 ? src.width : src.height;
  const g = c.getContext('2d')!;
  g.translate(c.width / 2, c.height / 2);
  g.rotate((q * Math.PI) / 2);
  g.drawImage(src, -src.width / 2, -src.height / 2);
  return c;
}

function toCanvas(img: CanvasImageSource, w: number, h: number): HTMLCanvasElement {
  const k = Math.min(1, MAX_SIDE / Math.max(w, h));
  const c = document.createElement('canvas');
  c.width = Math.max(1, Math.round(w * k));
  c.height = Math.max(1, Math.round(h * k));
  c.getContext('2d')!.drawImage(img, 0, 0, c.width, c.height);
  return c;
}

const maxSquare = (dw: number, dh: number): Sel => { const s = Math.min(dw, dh); return { x: (dw - s) / 2, y: (dh - s) / 2, s }; };

/**
 * Editor de foto de perfil: elegir archivo o tomar una foto con la cámara, girar y recortar un cuadrado
 * moviéndolo y cambiándole el tamaño. Entrega un Blob cuadrado listo para subir.
 */
export function PhotoEditor({ onCancel, onSave }: { onCancel: () => void; onSave: (blob: Blob) => Promise<void> | void }) {
  const [source, setSource] = useState<HTMLCanvasElement | null>(null);   // imagen original (sin girar)
  const [quarter, setQuarter] = useState(0);                              // giros de 90° a la derecha
  const [cam, setCam] = useState<'off' | 'on'>('off');
  const [facing, setFacing] = useState<'user' | 'environment'>('user');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const captureRef = useRef<HTMLInputElement>(null);
  const videoRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);

  const stopCamera = useCallback(() => { streamRef.current?.getTracks().forEach((t) => t.stop()); streamRef.current = null; }, []);
  useEffect(() => stopCamera, [stopCamera]);

  const load = (file: File | undefined) => {
    if (!file) return;
    setError('');
    if (!file.type.startsWith('image/')) return setError('Elija un archivo de imagen (JPG, PNG o WebP).');
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => { setSource(toCanvas(img, img.naturalWidth, img.naturalHeight)); setQuarter(0); URL.revokeObjectURL(url); };
    img.onerror = () => { setError('No se pudo leer la imagen.'); URL.revokeObjectURL(url); };
    img.src = url;
  };

  const startCamera = async (mode: 'user' | 'environment' = facing) => {
    setError('');
    if (!cameraAvailable()) { captureRef.current?.click(); return; }   // sin HTTPS: se usa la cámara nativa del dispositivo
    stopCamera();
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: mode, width: { ideal: 1280 }, height: { ideal: 960 } }, audio: false });
      streamRef.current = stream; setFacing(mode); setCam('on');
    } catch (e) {
      setCam('off');
      setError((e as DOMException).name === 'NotAllowedError' ? 'No se concedió permiso para usar la cámara. Puede permitirlo en el navegador o elegir un archivo.' : 'No se pudo abrir la cámara en este dispositivo.');
    }
  };
  useEffect(() => { if (cam === 'on' && videoRef.current && streamRef.current) { videoRef.current.srcObject = streamRef.current; videoRef.current.play().catch(() => {}); } }, [cam]);

  const snap = () => {
    const v = videoRef.current;
    if (!v || !v.videoWidth) return;
    setSource(toCanvas(v, v.videoWidth, v.videoHeight)); setQuarter(0);
    stopCamera(); setCam('off');
  };

  if (cam === 'on') {
    return (
      <div className="pe">
        <div className="pe-stage"><video ref={videoRef} playsInline muted className={facing === 'user' ? 'mirror' : ''} /></div>
        <div className="buttons">
          <button onClick={() => { stopCamera(); setCam('off'); }}>Cancelar</button>
          <button onClick={() => startCamera(facing === 'user' ? 'environment' : 'user')}>Cambiar cámara</button>
          <button className="primary" onClick={snap}>Capturar</button>
        </div>
      </div>
    );
  }

  if (!source) {
    return (
      <div className="pe">
        <p className="muted">Elija una foto de su dispositivo o tómela ahora. En el siguiente paso podrá girarla y recortarla.</p>
        <div className="pe-choose">
          <button className="primary" onClick={() => fileRef.current?.click()}>Elegir archivo…</button>
          <button onClick={() => startCamera()}>Tomar foto con la cámara</button>
        </div>
        {!cameraAvailable() && <small className="muted">Este navegador no permite usar la cámara directamente en una conexión sin HTTPS; en un celular se abrirá la cámara del dispositivo.</small>}
        <input ref={fileRef} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={(e) => { load(e.target.files?.[0]); e.target.value = ''; }} />
        <input ref={captureRef} type="file" accept="image/*" capture="user" hidden onChange={(e) => { load(e.target.files?.[0]); e.target.value = ''; }} />
        {error && <p className="error">{error}</p>}
        <div className="buttons"><button onClick={onCancel}>Cancelar</button></div>
      </div>
    );
  }

  return (
    <Cropper
      source={source} quarter={quarter} busy={busy} error={error}
      onRotate={(d) => setQuarter((q) => q + d)}
      onOther={() => { setSource(null); setError(''); }}
      onCancel={onCancel}
      onSave={async (blob) => { setBusy(true); setError(''); try { await onSave(blob); } catch (e) { setError((e as Error).message); setBusy(false); } }}
    />
  );
}

function Cropper({ source, quarter, busy, error, onRotate, onOther, onCancel, onSave }: {
  source: HTMLCanvasElement; quarter: number; busy: boolean; error: string;
  onRotate: (d: number) => void; onOther: () => void; onCancel: () => void; onSave: (b: Blob) => void;
}) {
  const rotated = useRef<HTMLCanvasElement>(source);
  const shown = useRef<HTMLCanvasElement>(null);
  const [dims, setDims] = useState({ dw: VIEW, dh: VIEW, k: 1 });          // tamaño en pantalla y factor a píxeles reales
  const [sel, setSel] = useState<Sel>({ x: 0, y: 0, s: VIEW });
  const drag = useRef<{ mode: 'move' | Corner; px: number; py: number; start: Sel } | null>(null);

  // Al cambiar la imagen o el giro: se recalcula el lienzo y el recorte vuelve al cuadrado máximo centrado.
  useEffect(() => {
    const r = rotateCanvas(source, quarter);
    rotated.current = r;
    const scale = Math.min(VIEW / r.width, VIEW / r.height, 1);
    const dw = Math.round(r.width * scale), dh = Math.round(r.height * scale);
    setDims({ dw, dh, k: r.width / dw });
    setSel(maxSquare(dw, dh));
    const c = shown.current;
    if (c) { c.width = r.width; c.height = r.height; c.getContext('2d')!.drawImage(r, 0, 0); }
  }, [source, quarter]);

  const clamp = (s: Sel): Sel => {
    const size = Math.max(MIN, Math.min(s.s, dims.dw, dims.dh));
    return { s: size, x: Math.min(Math.max(0, s.x), dims.dw - size), y: Math.min(Math.max(0, s.y), dims.dh - size) };
  };

  const down = (mode: 'move' | Corner) => (e: RPointerEvent) => {
    e.preventDefault(); e.stopPropagation();
    (e.currentTarget as Element).setPointerCapture(e.pointerId);
    drag.current = { mode, px: e.clientX, py: e.clientY, start: sel };
  };
  const move = (e: RPointerEvent) => {
    const d = drag.current;
    if (!d) return;
    const dx = e.clientX - d.px, dy = e.clientY - d.py, a = d.start;
    if (d.mode === 'move') return setSel(clamp({ ...a, x: a.x + dx, y: a.y + dy }));
    // Redimensionar: la esquina opuesta queda fija y el recorte se mantiene cuadrado.
    const fx = d.mode.includes('l') ? a.x + a.s : a.x;            // esquina fija
    const fy = d.mode.includes('t') ? a.y + a.s : a.y;
    const cx = (d.mode.includes('l') ? a.x : a.x + a.s) + dx;      // esquina que se arrastra
    const cy = (d.mode.includes('t') ? a.y : a.y + a.s) + dy;
    const room = Math.min(d.mode.includes('l') ? fx : dims.dw - fx, d.mode.includes('t') ? fy : dims.dh - fy);
    const s = Math.max(MIN, Math.min(Math.max(Math.abs(cx - fx), Math.abs(cy - fy)), room));
    setSel({ s, x: d.mode.includes('l') ? fx - s : fx, y: d.mode.includes('t') ? fy - s : fy });
  };
  const up = () => { drag.current = null; };

  const key = (e: React.KeyboardEvent) => {
    const step = e.shiftKey ? 10 : 2;
    const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
    if (moves[e.key]) { e.preventDefault(); setSel((s) => clamp({ ...s, x: s.x + moves[e.key][0], y: s.y + moves[e.key][1] })); }
    else if (e.key === '+' || e.key === '=') { e.preventDefault(); setSel((s) => clamp({ ...s, s: s.s + step * 2 })); }
    else if (e.key === '-') { e.preventDefault(); setSel((s) => clamp({ ...s, s: s.s - step * 2 })); }
  };

  const save = () => {
    const r = rotated.current, k = dims.k;
    const side = Math.max(1, Math.round(sel.s * k));
    const out = document.createElement('canvas');
    out.width = out.height = Math.min(OUT, side);
    out.getContext('2d')!.drawImage(r, Math.round(sel.x * k), Math.round(sel.y * k), side, side, 0, 0, out.width, out.height);
    out.toBlob((b) => b && onSave(b), 'image/jpeg', 0.92);
  };

  return (
    <div className="pe">
      <div className="pe-tools">
        <button onClick={() => onRotate(-1)} aria-label="Girar a la izquierda">⟲ Girar izquierda</button>
        <button onClick={() => onRotate(1)} aria-label="Girar a la derecha">Girar derecha ⟳</button>
        <button onClick={() => setSel(maxSquare(dims.dw, dims.dh))}>Recorte máximo</button>
      </div>
      <div className="pe-stage" style={{ width: dims.dw, height: dims.dh }}>
        <canvas ref={shown} style={{ width: dims.dw, height: dims.dh }} aria-label="Foto a recortar" />
        <div className="pe-shade" style={{ clipPath: `polygon(0 0, 100% 0, 100% 100%, 0 100%, 0 0, ${sel.x}px ${sel.y}px, ${sel.x}px ${sel.y + sel.s}px, ${sel.x + sel.s}px ${sel.y + sel.s}px, ${sel.x + sel.s}px ${sel.y}px, ${sel.x}px ${sel.y}px)` }} />
        <div
          className="pe-sel" role="slider" tabIndex={0} aria-label="Área de recorte (flechas para mover, + y − para el tamaño)" aria-valuenow={Math.round(sel.s * dims.k)}
          style={{ left: sel.x, top: sel.y, width: sel.s, height: sel.s }}
          onPointerDown={down('move')} onPointerMove={move} onPointerUp={up} onPointerCancel={up} onKeyDown={key}
        >
          {(['tl', 'tr', 'bl', 'br'] as Corner[]).map((c) => (
            <span key={c} className={`pe-h ${c}`} data-corner={c} onPointerDown={down(c)} onPointerMove={move} onPointerUp={up} onPointerCancel={up} />
          ))}
        </div>
      </div>
      <p className="muted pe-hint">Arrastre el cuadrado para moverlo y las esquinas para cambiar su tamaño. Resultado: {Math.round(sel.s * dims.k)} × {Math.round(sel.s * dims.k)} px.</p>
      {error && <p className="error">{error}</p>}
      <div className="buttons">
        <button onClick={onOther} disabled={busy}>Otra foto</button>
        <button onClick={onCancel} disabled={busy}>Cancelar</button>
        <button className="primary" onClick={save} disabled={busy}>{busy ? 'Guardando…' : 'Usar esta foto'}</button>
      </div>
    </div>
  );
}
