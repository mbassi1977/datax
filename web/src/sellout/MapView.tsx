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

export type MapPoint = { id: string; lat: number; lon: number; v: number; color?: string; tip: string; approx?: boolean; label?: string; r?: number };
export type Tiles = { url: string; attribution: string } | null;

const css = (n: string) => getComputedStyle(document.documentElement).getPropertyValue(n).trim() || undefined;
const hex = (h: string) => { h = h.replace('#', ''); if (h.length === 3) h = h.split('').map((c) => c + c).join(''); return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)); };
export const mixHex = (a: string, b: string, t: number) => { const A = hex(a), B = hex(b); return '#' + A.map((v, i) => Math.round(v + (B[i] - v) * t).toString(16).padStart(2, '0')).join(''); };
const themeColor = (name: string, fallback: string) => { const v = css(name); return v && v.startsWith('#') ? v : fallback; };

type Bounds = [[number, number], [number, number]];
const views = new Map<string, { key: string; c: L.LatLng; z: number }>();   // vista recordada por mapa

/** Mapa de zona: comunas, calor por indicador y salas clicables. */
export function ZoneMap({ geo, tiles, points, heat, bounds, focusKey, highlight, onPick, onMove, height = 520 }: {
  geo: Geo | null; tiles: Tiles; points: MapPoint[]; heat: boolean; bounds: Bounds; focusKey: string; highlight?: string | null;
  onPick: (id: string) => void; onMove?: (b: L.LatLngBounds) => void; height?: number;
}) {
  const el = useRef<HTMLDivElement>(null);
  const onPickRef = useRef(onPick); onPickRef.current = onPick;
  const onMoveRef = useRef(onMove); onMoveRef.current = onMove;

  useEffect(() => {
    if (!el.current || !geo) return;
    const m = L.map(el.current, { preferCanvas: true, scrollWheelZoom: false, dragging: !L.Browser.mobile, zoomSnap: 0.25, zoomDelta: 0.5, minZoom: 3 });
    m.attributionControl.setPrefix(false);
    const saved = views.get('zone');
    if (saved && saved.key === focusKey) m.setView(saved.c, saved.z, { animate: false }); else m.fitBounds(bounds, { padding: [14, 14], animate: false, maxZoom: 15 });
    m.on('moveend', () => { views.set('zone', { key: focusKey, c: m.getCenter(), z: m.getZoom() }); onMoveRef.current?.(m.getBounds()); });
    m.on('click focus', () => m.scrollWheelZoom.enable()); m.on('mouseout blur', () => m.scrollWheelZoom.disable());

    if (tiles) L.tileLayer(tiles.url, { maxZoom: 19, attribution: tiles.attribution }).addTo(m);
    const coast = themeColor('--border', '#c3cddb'), land = themeColor('--surface', '#ffffff');
    L.geoJSON(geo.comunas, {
      style: () => ({ color: coast, weight: tiles ? 1 : 0.8, opacity: tiles ? 0.7 : 1, fillColor: land, fillOpacity: tiles ? 0 : 1 }),
      onEachFeature: (f, l) => l.bindTooltip(String(f.properties.Comuna), { sticky: true }),
    }).addTo(m);

    const heatColor = themeColor('--primary', '#2a78d6');
    if (heat && points.length) {
      const mx = Math.max(...points.map((p) => p.v), 1e-9), [r, g, b] = hex(heatColor);
      L.heatLayer(points.map((p) => [p.lat, p.lon, 0.15 + 0.85 * Math.sqrt(p.v / mx)]), {
        radius: 26, blur: 22, maxZoom: 13, max: 1, minOpacity: 0.25, gradient: { 0.25: `rgba(${r},${g},${b},.35)`, 0.6: `rgba(${r},${g},${b},.7)`, 1: `rgba(${r},${g},${b},1)` },
      }).addTo(m);
    }
    points.forEach((p) => {
      const hi = highlight === p.id, c = p.color ?? heatColor;
      const mk = L.circleMarker([p.lat, p.lon], { radius: hi ? 10 : p.r ?? 5, fillColor: c, fillOpacity: p.approx && !hi ? 0.55 : 1, color: hi ? themeColor('--primary', '#2a78d6') : '#ffffff', weight: hi ? 3 : 1.2 }).addTo(m);
      mk.bindTooltip(p.tip, { direction: 'top', offset: [0, -4] });
      mk.on('click', () => onPickRef.current(p.id));
    });
    onMoveRef.current?.(m.getBounds());
    const t = setTimeout(() => m.invalidateSize(), 0);
    return () => { clearTimeout(t); m.remove(); };
  }, [geo, tiles, points, heat, focusKey, highlight, bounds]);

  return <div ref={el} className="so-map" style={{ height }} role="application" aria-label="Mapa interactivo" />;
}

/** Mapa de Chile por regiones (colores por valor). Tocar una región la agrega o quita del filtro. */
export function ChileMap({ geo, values, selected, format, onPick, view }: {
  geo: Geo | null; values: Record<string, number>; selected: Set<string>; format: (v: number) => string; onPick: (region: string) => void; view: L.LatLngBounds | null;
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
  return <div ref={el} className="so-map chile" role="application" aria-label="Mapa político de Chile por región" />;
}

const REGION_KEYS: [string, string][] = [['arica y parinacota', 'Arica y Parinacota'], ['tarapaca', 'Tarapacá'], ['antofagasta', 'Antofagasta'], ['atacama', 'Atacama'], ['coquimbo', 'Coquimbo'], ['valparaiso', 'Valparaíso'], ['metropolitana', 'Metropolitana'], ['o higgins', "O'Higgins"], ['maule', 'Maule'], ['nuble', 'Ñuble'], ['bio', 'Biobío'], ['araucania', 'Araucanía'], ['los rios', 'Los Ríos'], ['los lagos', 'Los Lagos'], ['aysen', 'Aysén'], ['magallanes', 'Magallanes']];
const nrm = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim().replace('bio bio', 'bio');
export function regionKey(name: string): string | null { const n = nrm(name); return REGION_KEYS.find(([k]) => n.includes(k))?.[1] ?? null; }

/** Mapa de entorno: una sala elegida y las más cercanas, con distancia. */
export function NearMap({ geo, tiles, base, neighbors, onPick, boundsKey }: {
  geo: Geo | null; tiles: Tiles; base: { id: string; lat: number; lon: number; name: string; color: string };
  neighbors: { id: string; lat: number; lon: number; name: string; km: number; color: string }[]; onPick: (id: string) => void; boundsKey: string;
}) {
  const el = useRef<HTMLDivElement>(null);
  const onPickRef = useRef(onPick); onPickRef.current = onPick;
  useEffect(() => {
    if (!el.current || !geo) return;
    const m = L.map(el.current, { preferCanvas: true, scrollWheelZoom: false, dragging: !L.Browser.mobile, zoomSnap: 0.25, zoomDelta: 0.5, minZoom: 3 });
    m.attributionControl.setPrefix(false);
    const pts: [number, number][] = [[base.lat, base.lon], ...neighbors.map((n) => [n.lat, n.lon] as [number, number])];
    m.fitBounds(L.latLngBounds(pts).pad(0.35), { animate: false, maxZoom: 16 });
    m.on('click focus', () => m.scrollWheelZoom.enable()); m.on('mouseout blur', () => m.scrollWheelZoom.disable());
    if (tiles) L.tileLayer(tiles.url, { maxZoom: 19, attribution: tiles.attribution }).addTo(m);
    L.geoJSON(geo.comunas, { style: () => ({ color: themeColor('--border', '#c3cddb'), weight: tiles ? 1 : 0.8, opacity: tiles ? 0.7 : 1, fillColor: themeColor('--surface', '#fff'), fillOpacity: tiles ? 0 : 1 }), onEachFeature: (f, l) => l.bindTooltip(String(f.properties.Comuna), { sticky: true }) }).addTo(m);
    const accent = themeColor('--primary', '#2a78d6');
    neighbors.forEach((n) => L.polyline([[base.lat, base.lon], [n.lat, n.lon]], { color: themeColor('--muted', '#667085'), weight: 1.2, dashArray: '5 4', opacity: 0.8 }).addTo(m));
    neighbors.forEach((n) => {
      const mk = L.circleMarker([n.lat, n.lon], { radius: 7, fillColor: n.color, fillOpacity: 1, color: '#fff', weight: 2 }).addTo(m);
      mk.bindTooltip(`${n.name} · ${n.km.toLocaleString('es-CL', { maximumFractionDigits: 1 })} km`, { permanent: true, direction: 'right', offset: [10, 0], className: 'so-lbl' });
      mk.on('click', () => onPickRef.current(n.id));
    });
    L.circleMarker([base.lat, base.lon], { radius: 11, fillColor: accent, fillOpacity: 1, color: '#fff', weight: 2 }).addTo(m).bindTooltip(`<b>${base.name}</b>`, { permanent: true, direction: 'right', offset: [12, 0], className: 'so-lbl' });
    const t = setTimeout(() => m.invalidateSize(), 0);
    return () => { clearTimeout(t); m.remove(); };
  }, [geo, tiles, boundsKey]);   // eslint-disable-line react-hooks/exhaustive-deps
  return <div ref={el} className="so-map" style={{ height: 460 }} role="application" aria-label="Mapa del entorno de la sala" />;
}
