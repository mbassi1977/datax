import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me, upload } from './api';
import { Avatar } from './Avatar';
import { can, Modal } from './ResourcePage';

type Item = { id: string; name: string; is_active: boolean; url: string };
type Collection = {
  id: string; name: string; description: string | null; is_global: boolean; is_active: boolean; is_system: boolean;
  organization_ids: string[]; avatars: Item[];
};
type Org = { id: string; legal_name: string; trade_name?: string | null };

export function AvatarGalleriesPage({ me }: { me: Me }) {
  const [items, setItems] = useState<Collection[]>([]);
  const [orgs, setOrgs] = useState<Org[]>([]);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState<Collection | 'new' | null>(null);
  const [managing, setManaging] = useState<string | null>(null);
  const canWrite = can(me, 'avatares', 'editar');

  const load = useCallback(() => { api<Collection[]>('GET', 'avatar-collections').then(setItems).catch((e) => setError(e.message)); }, []);
  useEffect(() => { load(); api<{ data: Org[] }>('GET', 'organizations').then((r) => setOrgs(r.data)).catch(() => {}); }, [load]);

  const orgName = (id: string) => orgs.find((o) => o.id === id)?.trade_name || orgs.find((o) => o.id === id)?.legal_name || '—';
  const remove = async (c: Collection) => {
    if (!confirm(`¿Eliminar la colección «${c.name}» y sus ${c.avatars.length} imágenes? Quienes las usan volverán al avatar por defecto.`)) return;
    try { await api('DELETE', `avatar-collections/${c.id}`); load(); } catch (e) { setError((e as Error).message); }
  };
  const managed = items.find((c) => c.id === managing) ?? null;

  return (
    <section>
      <header className="bar">
        <h1>Galerías de avatares</h1>
        {can(me, 'avatares', 'crear') && <button className="primary" onClick={() => setEditing('new')}>+ Nueva colección</button>}
      </header>
      <p className="muted">Los usuarios eligen su avatar entre las colecciones disponibles para su empresa. Una colección puede estar disponible para todas las empresas o asignarse solo a algunas.</p>
      {error && <p className="error">{error}</p>}
      <div className="collections">
        {items.map((c) => (
          <article key={c.id} className="card pad collection">
            <header>
              <h2>{c.name}</h2>
              <span>
                {c.is_system && <span className="badge">Sistema</span>} {!c.is_active && <span className="badge failed">Inactiva</span>}
              </span>
            </header>
            {c.description && <p className="muted">{c.description}</p>}
            <div className="thumbs">
              {c.avatars.slice(0, 8).map((a) => <img key={a.id} src={a.url} alt={a.name} className={a.is_active ? '' : 'off'} />)}
              {c.avatars.length === 0 && <span className="muted">Sin imágenes todavía.</span>}
              {c.avatars.length > 8 && <span className="more">+{c.avatars.length - 8}</span>}
            </div>
            <p className="assign">
              <strong>Disponible para:</strong>{' '}
              {c.is_global ? 'todas las empresas'
                : c.organization_ids.length ? c.organization_ids.map(orgName).join(', ') : <em className="muted">ninguna empresa (asígnela para que se use)</em>}
            </p>
            <footer className="actions">
              <button onClick={() => setManaging(c.id)}>{c.is_system || !canWrite ? 'Ver imágenes' : 'Imágenes'} ({c.avatars.length})</button>
              {!c.is_system && canWrite && <button onClick={() => setEditing(c)}>Editar y asignar</button>}
              {!c.is_system && can(me, 'avatares', 'eliminar') && <button className="danger" onClick={() => remove(c)}>Eliminar</button>}
            </footer>
          </article>
        ))}
        {items.length === 0 && <p className="muted">No hay colecciones.</p>}
      </div>
      {editing && <CollectionForm collection={editing === 'new' ? null : editing} orgs={orgs} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
      {managed && <ImagesModal collection={managed} editable={!managed.is_system && canWrite} onClose={() => setManaging(null)} onChanged={load} />}
    </section>
  );
}

function CollectionForm({ collection, orgs, onClose, onSaved }: { collection: Collection | null; orgs: Org[]; onClose: () => void; onSaved: () => void }) {
  const [name, setName] = useState(collection?.name ?? '');
  const [description, setDescription] = useState(collection?.description ?? '');
  const [active, setActive] = useState(collection?.is_active ?? true);
  const [global, setGlobal] = useState(collection?.is_global ?? false);
  const [sel, setSel] = useState<Set<string>>(new Set(collection?.organization_ids ?? []));
  const [q, setQ] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const shown = orgs.filter((o) => `${o.legal_name} ${o.trade_name ?? ''}`.toLowerCase().includes(q.toLowerCase()));
  const toggle = (id: string) => setSel((s) => { const n = new Set(s); n.has(id) ? n.delete(id) : n.add(id); return n; });

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setError('');
    const body = { name, description: description || null, is_active: active, is_global: global, organization_ids: [...sel] };
    try {
      await (collection ? api('PUT', `avatar-collections/${collection.id}`, body) : api('POST', 'avatar-collections', body));
      onSaved();
    } catch (e2) {
      const ae = e2 as ApiError;
      setError(Object.values(ae.errors).flat().join(' ') || ae.message);
    } finally { setBusy(false); }
  };

  return (
    <Modal title={collection ? `Editar · ${collection.name}` : 'Nueva colección'} onClose={onClose}>
      <form onSubmit={submit} className="form one">
        <label><span>Nombre *</span><input value={name} onChange={(e) => setName(e.target.value)} required maxLength={120} /></label>
        <label><span>Descripción</span><textarea rows={2} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} /></label>
        <label className="check"><input type="checkbox" checked={active} onChange={(e) => setActive(e.target.checked)} /> Colección activa (si se desactiva, nadie puede elegirla)</label>
        <fieldset>
          <legend>Disponibilidad</legend>
          <label className="check"><input type="checkbox" checked={global} onChange={(e) => setGlobal(e.target.checked)} /> Disponible para todas las empresas</label>
          <div className={global ? 'dim' : ''}>
            <input placeholder="Buscar empresa…" value={q} onChange={(e) => setQ(e.target.value)} disabled={global} />
            <div className="orglist">
              {shown.map((o) => (
                <label key={o.id} className="check"><input type="checkbox" checked={sel.has(o.id)} disabled={global} onChange={() => toggle(o.id)} />{o.trade_name || o.legal_name}</label>
              ))}
              {shown.length === 0 && <span className="muted">Sin empresas.</span>}
            </div>
            <small className="muted">{global ? 'Aplica a todas; la selección se conserva por si luego la restringe.' : `${sel.size} empresa(s) seleccionada(s).`}</small>
          </div>
        </fieldset>
        {error && <p className="error">{error}</p>}
        <div className="buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="primary" disabled={busy}>{busy ? 'Guardando…' : 'Guardar'}</button></div>
      </form>
    </Modal>
  );
}

function ImagesModal({ collection, editable, onClose, onChanged }: { collection: Collection; editable: boolean; onClose: () => void; onChanged: () => void }) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const add = async (files: FileList | null) => {
    if (!files?.length) return;
    setBusy(true); setError('');
    const form = new FormData();
    [...files].forEach((f) => form.append('files[]', f));
    try { await upload(`avatar-collections/${collection.id}/avatars`, form); onChanged(); } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  };
  const toggle = async (a: Item) => { try { await api('PUT', `avatars/${a.id}`, { is_active: !a.is_active }); onChanged(); } catch (e) { setError((e as Error).message); } };
  const remove = async (a: Item) => { if (confirm(`¿Eliminar «${a.name}»?`)) { try { await api('DELETE', `avatars/${a.id}`); onChanged(); } catch (e) { setError((e as Error).message); } } };

  return (
    <Modal title={`Imágenes · ${collection.name}`} onClose={onClose}>
      {editable && (
        <label className="upload">
          <span>{busy ? 'Subiendo…' : 'Agregar imágenes (JPG, PNG, WebP o SVG; hasta 30 a la vez, 1 MB c/u). Se recortan a cuadrado.'}</span>
          <input type="file" accept=".jpg,.jpeg,.png,.webp,.svg" multiple disabled={busy} onChange={(e) => { add(e.target.files); e.target.value = ''; }} />
        </label>
      )}
      {error && <p className="error">{error}</p>}
      <div className="gallery">
        {collection.avatars.map((a) => (
          <figure key={a.id} className={a.is_active ? '' : 'off'}>
            <img src={a.url} alt={a.name} />
            <figcaption>{a.name}</figcaption>
            {editable && (
              <div className="actions">
                <button onClick={() => toggle(a)}>{a.is_active ? 'Ocultar' : 'Mostrar'}</button>
                <button className="danger" onClick={() => remove(a)}>Quitar</button>
              </div>
            )}
          </figure>
        ))}
        {collection.avatars.length === 0 && <p className="muted">Esta colección aún no tiene imágenes.</p>}
      </div>
    </Modal>
  );
}
