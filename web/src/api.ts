// Cliente de la API v1. El token y el perfil activo viven en localStorage.
const store = {
  get: (k: string) => { try { return localStorage.getItem(k); } catch { return null; } },
  set: (k: string, v: string | null) => { try { v === null ? localStorage.removeItem(k) : localStorage.setItem(k, v); } catch { /* sin storage */ } },
};

export const session = {
  token: () => store.get('hrx_token'),
  setToken: (t: string | null) => store.set('hrx_token', t),
  profile: () => store.get('hrx_profile'),
  setProfile: (p: string | null) => store.set('hrx_profile', p),
  tenant: () => store.get('hrx_tenant') ?? 'demo',
  setTenant: (t: string) => store.set('hrx_tenant', t),
};

export class ApiError extends Error {
  constructor(public status: number, message: string, public errors: Record<string, string[]> = {}) { super(message); }
}

let onUnauthorized: () => void = () => {};
export const setUnauthorizedHandler = (fn: () => void) => { onUnauthorized = fn; };

export async function api<T = any>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' };
  const token = session.token();
  if (token) headers.Authorization = `Bearer ${token}`;
  const profile = session.profile();
  if (profile) headers['X-Profile'] = profile;

  const res = await fetch(`/api/v1/${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  if (res.status === 204) return undefined as T;
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && token) onUnauthorized();
  if (!res.ok) throw new ApiError(res.status, data.message ?? `Error ${res.status}`, data.errors ?? {});
  return data as T;
}

export type Me = {
  id: string; email: string; avatar_url: string | null; is_superadmin: boolean; permissions: string[];
  mfa?: { enabled: boolean; recovery_remaining: number };
  settings?: { brand: { name: string | null; primary_color: string; logo_url: string | null }; locale: { timezone: string; date_format: string }; security: { mfa_required: boolean; password_min_length: number } };
  profiles: { code: string; name: string; scope_type: string; scope_id: string | null }[];
};

/** Subida de archivos (multipart). */
export async function upload<T = any>(path: string, form: FormData): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  const token = session.token();
  if (token) headers.Authorization = `Bearer ${token}`;
  const profile = session.profile();
  if (profile) headers['X-Profile'] = profile;
  const res = await fetch(`/api/v1/${path}`, { method: 'POST', headers, body: form });
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && token) onUnauthorized();
  if (!res.ok) throw new ApiError(res.status, data.message ?? `Error ${res.status}`, data.errors ?? {});
  return data as T;
}

/** Descarga un archivo autenticado (la API exige el token, no sirve un enlace directo). */
export async function download(path: string, filename: string): Promise<void> {
  const headers: Record<string, string> = { Accept: '*/*' };
  const token = session.token();
  if (token) headers.Authorization = `Bearer ${token}`;
  const profile = session.profile();
  if (profile) headers['X-Profile'] = profile;
  const res = await fetch(`/api/v1/${path}`, { headers });
  if (!res.ok) throw new ApiError(res.status, `Error ${res.status}`);
  const url = URL.createObjectURL(await res.blob());
  const a = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}
