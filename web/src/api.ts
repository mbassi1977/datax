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
  id: string; email: string; is_superadmin: boolean; permissions: string[];
  profiles: { code: string; name: string; scope_type: string; scope_id: string | null }[];
};
