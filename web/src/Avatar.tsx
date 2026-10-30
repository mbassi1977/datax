import { useEffect, useState } from 'react';

const palette = ['#1f5eff', '#0e9f6e', '#d97706', '#dc2626', '#7c3aed', '#db2777', '#0891b2', '#4d7c0f'];

/** Foto de perfil o, si no hay (o falla la carga), un círculo con las iniciales. */
export function Avatar({ url, label, size = 32 }: { url?: string | null; label: string; size?: number }) {
  const [broken, setBroken] = useState(false);
  useEffect(() => setBroken(false), [url]);

  const text = (label || '?').trim();
  const initials = text.split(/[\s@._-]+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
  const color = palette[[...text].reduce((a, c) => a + c.charCodeAt(0), 0) % palette.length];
  const style = { width: size, height: size, fontSize: size * 0.4 };

  return url && !broken
    ? <img className="avatar" src={url} alt="" style={style} onError={() => setBroken(true)} />
    : <span className="avatar initials" style={{ ...style, background: color }} aria-hidden="true">{initials}</span>;
}
