import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import 'leaflet.heat';

// Mapas del módulo: límites oficiales de comunas y regiones (GeoJSON incluido), capa de calles configurable y mapa de calor.
// Se pueden arrastrar y acercar; la vista se conserva mientras no cambie el «foco» (región, sala o zona elegida).

declare module 'leaflet' { function heatLayer(latlngs: [number, number, number][], options?: Record<string, unknown>): L.Layer }

export type Geo = { comunas: any; regiones: any };
let geoPromise: Promise<Geo> | null = null;
export const loadGeo = () => (geoPromise ??= Promise.all([import('./geo/comunas.json'), import('./geo/regiones.json')]).then(([c, r]) => ({ comunas: (c as any).default ?? c, regiones: (r as any).default ?? r })));

export type Popup = { title: string; sub: string; kpis: { l: string; v: string; cls?: string }[]; big: string; bigNote: string };
export type MapPoint = { id: string; lat: number; lon: number; v: number; color?: string; tip: string; approx?: boolean; label?: string; r?: number; popup?: Popup };
export type Tiles = { url: string; attribution: string } | null;

const css = (n: string) => getComputedStyle(document.documentElement).getPropertyValue(n).trim() || undefined;
const hex = (h: string) => { h = h.replace('#', ''); if (h.length === 3) h = h.split('').map((c) => c + c).join(''); return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)); };
export const mixHex = (a: string, b: string, t: number) => { const A = hex(a), B = hex(b); return '#' + A.map((v, i) => Math.round(v + (B[i] - v) * t).toString(16).padStart(2, '0')).join(''); };
const themeColor = (name: string, fallback: string) => { const v = css(name); return v && v.startsWith('#') ? v : fallback; };

type Bounds = [[number, number], [number, number]];
const views = new Map<string, { key: string; c: L.LatLng; z: number }>();   // vista recordada por mapa

const nrmName = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();

/** Ficha emergente de una sala sobre el mapa (como en las herramientas de trade marketing): indicadores y enlace a «más detalles». */
function popupEl(p: MapPoint, onDetails: (id: string) => void): HTMLElement {
  const d = p.popup!, root = document.createElement('div'); root.className = 'so-pop-card';
  const esc = (t: string) => t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] as string));
  root.innerHTML = `<div class="ph"><b>${esc(d.title)}</b><span>${esc(d.sub)}</span></div>
    <div class="pk">${d.kpis.map((k) => `<div><b class="${k.cls ?? ''}">${esc(k.v)}</b><span>${esc(k.l)}</span></div>`).join('')}</div>
    <div class="pb"><strong>${esc(d.big)}</strong><span>${esc(d.bigNote)}</span></div>
    <button type="button" class="pm">más detalles</button>`;
  root.querySelector('.pm')!.addEventListener('click', () => onDetails(p.id));
  return root;
}

/** Mapa de zona: comunas, burbujas con semáforo (o calor), ficha emergente, y enfoque desde la lista lateral. */
export function ZoneMap({ geo, tiles, points, mode, bounds, focusKey, hover, focus, communes, showMarkers, onPick, onMove, height = 620 }: {
  geo: Geo | null; tiles: Tiles; points: MapPoint[]; mode: 'bubbles' | 'heat'; bounds: Bounds; focusKey: string; hover?: string | null;
  focus: { id: string; n: number } | null; communes: Record<string, string> | null; showMarkers: boolean;
  onPick: (id: string) => void; onMove?: (b: L.LatLngBounds) => void; height?: number;
}) {
  const el = useRef<HTMLDivElement>(null);
  const mapRef = useRef<L.Map | null>(null);
  const layer = useRef<L.LayerGroup | null>(null);
  const markers = useRef(new Map<string, L.CircleMarker>());
  const onPickRef = useRef(onPick); onPickRef.current = onPick;
  const onMoveRef = useRef(onMove); onMoveRef.current = onMove;
  const boundsRef = useRef(bounds); boundsRef.current = bounds;
  const keyRef = useRef(focusKey); keyRef.current = focusKey;

  // 1) El mapa y sus capas base viven mientras no cambien los datos geográficos ni la capa de calles.
  useEffect(() => {
    if (!el.current || !geo) return;
    const m = L.map(el.current, { preferCanvas: true, scrollWheelZoom: false, dragging: !L.Browser.mobile, zoomSnap: 0.25, zoomDelta: 0.5, minZoom: 3 });
    m.attributionControl.setPrefix(false);
    const saved = views.get('zone');
    if (saved && saved.key === keyRef.current) m.setView(saved.c, saved.z, { animate: false }); else m.fitBounds(boundsRef.current, { padding: [14, 14], animate: false, maxZoom: 15 });
    m.on('moveend', () => { views.set('zone', { key: keyRef.current, c: m.getCenter(), z: m.getZoom() }); onMoveRef.current?.(m.getBounds()); });
    m.on('click focus', () => m.scrollWheelZoom.enable()); m.on('mouseout blur', () => m.scrollWheelZoom.disable());
    if (tiles) L.tileLayer(tiles.url, { maxZoom: 19, attribution: tiles.attribution }).addTo(m);
    mapRef.current = m; layer.current = L.layerGroup().addTo(m);
    onMoveRef.current?.(m.getBounds());
    const t = setTimeout(() => m.invalidateSize(), 0);
    return () => { clearTimeout(t); m.remove(); mapRef.current = null; layer.current = null; markers.current.clear(); };
  }, [geo, tiles]);

  // 2) Cuando cambia el foco (región, sala o zona elegida) se reencuadra; al cambiar solo los datos se conserva la vista.
  useEffect(() => {
    const m = mapRef.current; if (!m) return;
    const saved = views.get('zone');
    if (!saved || saved.key !== focusKey) m.fitBounds(bounds, { padding: [14, 14], animate: true, maxZoom: 15 });
  }, [focusKey, bounds, geo, tiles]);

  // 3) Comunas, calor y burbujas se rehacen cuando cambian los datos.
  useEffect(() => {
    const m = mapRef.current, g = layer.current; if (!m || !g || !geo) return;
    g.clearLayers(); markers.current.clear();
    const coast = themeColor('--border', '#c3cddb'), land = themeColor('--surface', '#ffffff');
    L.geoJSON(geo.comunas, {
      style: (f) => { const c = communes?.[nrmName(String(f!.properties.Comuna))]; return { color: coast, weight: tiles ? 1 : 0.8, opacity: tiles ? 0.7 : 1, fillColor: c ?? land, fillOpacity: c ? 0.45 : tiles ? 0 : 1 }; },
      onEachFeature: (f, l) => l.bindTooltip(String(f.properties.Comuna), { sticky: true }),
    }).addTo(g);
    const accent = themeColor('--primary', '#2a78d6');
    if (mode === 'heat' && points.length) {
      const mx = Math.max(...points.map((p) => p.v), 1e-9), [r, gg, b] = hex(accent);
      L.heatLayer(points.map((p) => [p.lat, p.lon, 0.15 + 0.85 * Math.sqrt(p.v / mx)]), { radius: 26, blur: 22, maxZoom: 13, max: 1, minOpacity: 0.25, gradient: { 0.25: `rgba(${r},${gg},${b},.35)`, 0.6: `rgba(${r},${gg},${b},.7)`, 1: `rgba(${r},${gg},${b},1)` } }).addTo(g);
    }
    if (showMarkers) {
      [...points].sort((a, b) => (b.r ?? 5) - (a.r ?? 5)).forEach((p) => {      // las grandes atrás, las chicas encima
        const bubble = mode === 'bubbles';
        const mk = L.circleMarker([p.lat, p.lon], { radius: bubble ? p.r ?? 6 : 4, fillColor: p.color ?? accent, fillOpacity: bubble ? (p.approx ? 0.5 : 0.78) : 1, color: '#ffffff', weight: 1.2 }).addTo(g);
        mk.bindTooltip(p.tip, { direction: 'top', offset: [0, -4] });
        if (p.popup) mk.bindPopup(() => popupEl(p, (id) => onPickRef.current(id)), { className: 'so-popup', maxWidth: 270, minWidth: 230, autoPanPadding: [30, 30] });
        else mk.on('click', () => onPickRef.current(p.id));
        markers.current.set(p.id, mk);
      });
    }
  }, [geo, tiles, points, mode, communes, showMarkers]);

  // 4) Resaltado al pasar el mouse por la lista.
  useEffect(() => {
    const accent = themeColor('--primary', '#2a78d6');
    markers.current.forEach((mk, id) => { const on = id === hover; mk.setStyle({ color: on ? accent : '#ffffff', weight: on ? 3.5 : 1.2 }); on && mk.bringToFront(); });
  }, [hover, points, showMarkers, mode]);

  // 5) Elegir una sala en la lista: el mapa se acerca y abre su ficha emergente.
  useEffect(() => {
    if (!focus || !mapRef.current) return;
    const mk = markers.current.get(focus.id); if (!mk) return;
    mapRef.current.flyTo(mk.getLatLng(), Math.max(mapRef.current.getZoom(), 13), { duration: 0.6 });
    const t = setTimeout(() => mk.openPopup(), 650);
    return () => clearTimeout(t);
  }, [focus]);

  return <div ref={el} className="so-map" style={{ height }} role="application" aria-label="Mapa interactivo" />;
}

/** Mapa de Chile por regiones (colores por valor). Tocar una región la agrega o quita del filtro. */
export function ChileMap({ geo, values, selected, format, onPick, view, height }: {
  geo: Geo | null; values: Record<string, number>; selected: Set<string>; format: (v: number) => string; onPick: (region: string) => void; view: L.LatLngBounds | null; height?: number;
}) {
  const el = useRef<HTMLDivElement>(null);
  const onPickRef = useRef(onPick); onPickRef.current = onPick;
  const rect = useRef<L.Rectangle | null>(null);

  useEffect(() => {
    if (!el.current || !geo) return;
    const m = L.map(el.current, { preferCanvas: true, zoomControl: false, attributionControl: false, scrollWheelZoom: false, dragging: !L.Browser.mobile, zoomSnap: 0.25, minZoom: 2 });
    m.fitBounds([[-56, -76], [-17.5, -66]], { animate: false });
    const base = themeColor('--bg', '#eef1f6'), accent = themeColor('--primary', '#2a78d6'), mx = Math.max(...Object.values(values), 1e-9);
    L.geoJSON(geo.regiones, {
      style: (f) => { const k = regionKey(f!.properties.Region), v = values[k ?? ''], on = !!k && selected.has(k); return { color: on ? accent : themeColor('--border', '#c3cddb'), weight: on ? 2.5 : 0.8, fillColor: v === undefined ? base : mixHex(base, accent, 0.18 + 0.7 * v / mx), fillOpacity: 1 }; },
      onEachFeature: (f, l) => {
        const k = regionKey(f.properties.Region), v = values[k ?? ''];
        l.bindTooltip(`<b>${String(f.properties.Region).replace('Región de ', '').replace('Región del ', '')}</b>${v !== undefined ? '<br>' + format(v) : ''}`, { sticky: true });
        l.on('click', () => k && onPickRef.current(k));
      },
    }).addTo(m);
    rect.current = L.rectangle([[0, 0], [0, 0]], { color: accent, weight: 1.5, fill: false, dashArray: '4 3', interactive: false }).addTo(m);
    const t = setTimeout(() => m.invalidateSize(), 0);
    return () => { clearTimeout(t); rect.current = null; m.remove(); };
  }, [geo, values, selected, format]);

  useEffect(() => { if (view && rect.current) rect.current.setBounds(view); }, [view, geo, values, selected]);
  return <div ref={el} className="so-map chile" style={height ? { height } : undefined} role="application" aria-label="Mapa político de Chile por región" />;
}

const REGION_KEYS: [string, string][] = [['arica y parinacota', 'Arica y Parinacota'], ['tarapaca', 'Tarapacá'], ['antofagasta', 'Antofagasta'], ['atacama', 'Atacama'], ['coquimbo', 'Coquimbo'], ['valparaiso', 'Valparaíso'], ['metropolitana', 'Metropolitana'], ['o higgins', "O'Higgins"], ['maule', 'Maule'], ['nuble', 'Ñuble'], ['bio', 'Biobío'], ['araucania', 'Araucanía'], ['los rios', 'Los Ríos'], ['los lagos', 'Los Lagos'], ['aysen', 'Aysén'], ['magallanes', 'Magallanes']];
const nrm = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().replace('bio bio', 'bio');
export function regionKey(name: string): string | null { const n = nrm(name); return REGION_KEYS.find(([k]) => n.includes(k))?.[1] ?? null; }

/** Mapa de entorno: una sala elegida y las más cercanas, con distancia. */
export type NearPin = { id: string; lat: number; lon: number; name: string; chain: string; color: string; logo: string | null; km?: number; info?: string };

const escHtml = (t: string) => t.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c] as string));
/** Ficha de una sala sobre el mapa: logo (o iniciales), nombre y distancia; la sala elegida va más grande y resaltada. */
function pinIcon(p: NearPin, selected: boolean, left = false): L.DivIcon {
  const ini = escHtml(p.chain.replace(/[^A-Za-zÁÉÍÓÚÑáéíóúñ ]/g, '').trim().slice(0, 2).toUpperCase());
  const ic = p.logo ? `<img src="${escHtml(p.logo)}" alt="">` : `<i>${ini}</i>`;
  const km = p.km !== undefined ? `<small>${p.km.toLocaleString('es-CL', { maximumFractionDigits: 1 })} km</small>` : `<small>sala elegida</small>`;
  const size = selected ? 52 : 38;
  return L.divIcon({ className: 'so-nmw', iconSize: [size, size], iconAnchor: [size / 2, size / 2],
    html: `<div class="so-nm${selected ? ' sel' : ''}${left ? ' left' : ''}" style="--c:${p.color};--s:${size}px"><span class="ic">${ic}</span><span class="tx"><b>${escHtml(p.name)}</b>${km}</span></div>` });
}

/** Mapa de entorno: una sala elegida y las más cercanas, cada una con su logo y nombre, y la distancia. */
export function NearMap({ geo, tiles, base, neighbors, onPick, boundsKey }: {
  geo: Geo | null; tiles: Tiles; base: NearPin; neighbors: NearPin[]; onPick: (id: string) => void; boundsKey: string;
}) {
  const el = useRef<HTMLDivElement>(null);
  const onPickRef = useRef(onPick); onPickRef.current = onPick;
  useEffect(() => {
    if (!el.current || !geo) return;
    const m = L.map(el.current, { preferCanvas: true, scrollWheelZoom: false, dragging: !L.Browser.mobile, zoomSnap: 0.25, zoomDelta: 0.5, minZoom: 3 });
    m.attributionControl.setPrefix(false);
    const pts: [number, number][] = [[base.lat, base.lon], ...neighbors.map((n) => [n.lat, n.lon] as [number, number])];
    m.fitBounds(L.latLngBounds(pts), { animate: false, maxZoom: 16, paddingTopLeft: [170, 70], paddingBottomRight: [170, 50] });
    m.on('click focus', () => m.scrollWheelZoom.enable()); m.on('mouseout blur', () => m.scrollWheelZoom.disable());
    if (tiles) L.tileLayer(tiles.url, { maxZoom: 19, attribution: tiles.attribution }).addTo(m);
    L.geoJSON(geo.comunas, { style: () => ({ color: themeColor('--border', '#c3cddb'), weight: tiles ? 1 : 0.8, opacity: tiles ? 0.7 : 1, fillColor: themeColor('--surface', '#fff'), fillOpacity: tiles ? 0 : 1 }), onEachFeature: (f, l) => l.bindTooltip(String(f.properties.Comuna), { sticky: true }) }).addTo(m);
    neighbors.forEach((n) => L.polyline([[base.lat, base.lon], [n.lat, n.lon]], { color: themeColor('--muted', '#667085'), weight: 1.4, dashArray: '5 4', opacity: 0.8 }).addTo(m));
    neighbors.forEach((n) => {
      const mk = L.marker([n.lat, n.lon], { icon: pinIcon(n, false, n.lon < base.lon), zIndexOffset: 100, keyboard: true, title: n.name }).addTo(m);
      mk.bindTooltip(`<b>${escHtml(n.name)}</b><br>${escHtml(n.chain)}${n.km !== undefined ? ' · ' + n.km.toLocaleString('es-CL', { maximumFractionDigits: 1 }) + ' km' : ''}${n.info ? '<br>' + escHtml(n.info) : ''}<br><i>Toque para centrar el análisis aquí</i>`, { direction: 'top', offset: [0, -22] });
      mk.on('click', () => onPickRef.current(n.id));
    });
    const bm = L.marker([base.lat, base.lon], { icon: pinIcon(base, true), zIndexOffset: 1000, title: base.name }).addTo(m);
    bm.bindTooltip(`<b>${escHtml(base.name)}</b><br>${escHtml(base.chain)}${base.info ? '<br>' + escHtml(base.info) : ''}`, { direction: 'top', offset: [0, -30] });
    const t = setTimeout(() => m.invalidateSize(), 0);
    return () => { clearTimeout(t); m.remove(); };
  }, [geo, tiles, boundsKey]);   // eslint-disable-line react-hooks/exhaustive-deps
  return <div ref={el} className="so-map" style={{ height: 520 }} role="application" aria-label="Mapa del entorno de la sala" />;
}

/** Editor de ubicación: el punto se arrastra, o se toca el mapa para colocarlo. Los cambios salen por onChange con 6 decimales. */
export function PointPicker({ geo, tiles, lat, lon, onChange, height = 340 }: {
  geo: Geo | null; tiles: Tiles; lat: number | null; lon: number | null; onChange: (lat: number, lon: number) => void; height?: number;
}) {
  const el = useRef<HTMLDivElement>(null);
  const mapRef = useRef<L.Map | null>(null);
  const pin = useRef<L.Marker | null>(null);
  const onChangeRef = useRef(onChange); onChangeRef.current = onChange;
  const start = useRef({ lat, lon });
  const round = (v: number) => Math.round(v * 1e6) / 1e6;

  useEffect(() => {
    if (!el.current) return;
    const has = start.current.lat != null && start.current.lon != null;
    const m = L.map(el.current, { scrollWheelZoom: true, zoomSnap: 0.5, minZoom: 3 });
    m.attributionControl.setPrefix(false);
    m.setView(has ? [start.current.lat!, start.current.lon!] : [-33.45, -70.66], has ? 17 : 11);
    if (tiles) L.tileLayer(tiles.url, { maxZoom: 19, attribution: tiles.attribution }).addTo(m);
    if (geo) {
      L.geoJSON(geo.comunas, { style: () => ({ color: themeColor('--border', '#c3cddb'), weight: 1, opacity: 0.8, fillColor: themeColor('--surface', '#fff'), fillOpacity: tiles ? 0 : 1 }), onEachFeature: (f, l) => l.bindTooltip(String(f.properties.Comuna), { sticky: true }) }).addTo(m);
    }
    const icon = L.divIcon({ className: 'so-pin', html: '<span></span>', iconSize: [24, 24], iconAnchor: [12, 24] });
    const mk = L.marker(has ? [start.current.lat!, start.current.lon!] : m.getCenter(), { icon, draggable: true, autoPan: true, opacity: has ? 1 : 0.6 }).addTo(m);
    const emit = (ll: L.LatLng) => onChangeRef.current(round(ll.lat), round(ll.lng));
    mk.on('dragend', () => { mk.setOpacity(1); emit(mk.getLatLng()); });
    m.on('click', (e: L.LeafletMouseEvent) => { mk.setLatLng(e.latlng); mk.setOpacity(1); emit(e.latlng); });
    mapRef.current = m; pin.current = mk;
    const t = setTimeout(() => m.invalidateSize(), 50);
    return () => { clearTimeout(t); m.remove(); mapRef.current = null; pin.current = null; };
  }, [geo, tiles]);

  // Si se escriben las coordenadas a mano, el punto se mueve y el mapa lo sigue.
  useEffect(() => {
    const m = mapRef.current, mk = pin.current;
    if (!m || !mk || lat == null || lon == null || !Number.isFinite(lat) || !Number.isFinite(lon)) return;
    const cur = mk.getLatLng();
    if (Math.abs(cur.lat - lat) < 1e-7 && Math.abs(cur.lng - lon) < 1e-7) return;
    mk.setLatLng([lat, lon]); mk.setOpacity(1);
    if (!m.getBounds().contains([lat, lon])) m.setView([lat, lon], Math.max(m.getZoom(), 15));
  }, [lat, lon]);

  return <div ref={el} className="so-map picker" style={{ height }} role="application" aria-label="Mapa para ubicar la sala" />;
}
