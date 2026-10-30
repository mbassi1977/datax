import { FormEvent, useCallback, useEffect, useState } from 'react';
import { api, ApiError, Me } from './api';
import { can, Modal } from './ResourcePage';

type CType = { key: string; label: string; description: string | null; is_system: boolean; allow_tenant_items: boolean; items_count: number };
type Item = { id: string; code: string; label: string; sort: number; active: boolean; is_system: boolean };

export function CatalogsPage({ me }: { me: Me }) {
  const [types, setTypes] = useState<CType[]>([]);
  const [sel, setSel] = useState<string>('');
  const [items, setItems] = useState<Item[]>([]);
  const [error, setError] = useState('');
  const [typeForm, setTypeForm] = useState<CType | 'new' | null>(null);
  const [itemForm, setItemForm] = useState<Item | 'new' | null>(null);

  const loadTypes = useCallback(async () => {
    try { const t = await api<CType[]>('GET', 'catalogs'); setTypes(t); setSel((s) => (t.some((x) => x.key === s) ? s : t[0]?.key ?? '')); } catch (e) { setError((e as Error).message); }
  }, []);
  const loadItems = useCallback(async () => {
    if (!sel) return setItems([]);
    try { setItems(await api<Item[]>('GET', `catalogs/${sel}/items`)); } catch (e) { setError((e as Error).message); }
  }, [sel]);
  useEffect(() => { loadTypes(); }, [loadTypes]);
  useEffect(() => { loadItems(); }, [loadItems]);

  const type = types.find((t) => t.key === sel);
  const refresh = () => { loadTypes(); loadItems(); };
  const removeType = async () => {
    if (!type || !confirm(`¿Eliminar el catálogo «${type.label}» y todas sus entradas?`)) return;
    try { await api('DELETE', `catalogs/${type.key}`); refresh(); } catch (e) { setError((e as Error).message); }
  };
  const removeItem = async (i: Item) => {
    if (!confirm(`¿Eliminar «${i.label}»?`)) return;
    try { await api('DELETE', `catalogs/${sel}/items/${i.id}`); refresh(); } catch (e) { setError((e as Error).message); }
  };
  const toggle = async (i: Item) => { try { await api('PUT', `catalogs/${sel}/items/${i.id}`, { active: !i.active }); loadItems(); } catch (e) { setError((e as Error).message); } };

  return (
    <section>
      <header className="bar">
        <h1>Catálogos</h1>
        {can(me, 'catalogos', 'crear') && <button className="primary" onClick={() => setTypeForm('new')}>+ Nuevo catálogo</button>}
      </header>
      <p className="muted">Listas maestras que alimentan los formularios (regiones, cargos, industrias…). Las entradas del sistema no se modifican, pero puede agregar las suyas donde el catálogo lo permite, o crear catálogos nuevos.</p>
      {error && <p className="error">{error}</p>}
      <div className="split">
        <div className="card typelist" role="listbox" aria-label="Catálogos">
          {types.map((t) => (
            <button key={t.key} role="option" aria-selected={t.key === sel} className={t.key === sel ? 'on' : ''} onClick={() => setSel(t.key)}>
              <span>{t.label}</span><span className="muted">{t.items_count}</span>
            </button>
          ))}
        </div>
        <div>
          {type && (
            <>
              <div className="bar">
                <h2>{type.label} {type.is_system && <span className="badge">Sistema</span>}</h2>
                {!type.is_system && can(me, 'catalogos', 'editar') && <button onClick={() => setTypeForm(type)}>Renombrar</button>}
                {!type.is_system && can(me, 'catalogos', 'eliminar') && <button className="danger" onClick={removeType}>Eliminar catálogo</button>}
                {type.allow_tenant_items && can(me, 'catalogos', 'crear') && <button className="primary" onClick={() => setItemForm('new')}>+ Nueva entrada</button>}
              </div>
              {type.description && <p className="muted">{type.description}</p>}
              {!type.allow_tenant_items && <p className="muted">Este catálogo del sistema es cerrado: no admite entradas propias.</p>}
              <div className="card table-wrap">
                <table>
                  <thead><tr><th>Código</th><th>Nombre</th><th>Orden</th><th>Estado</th><th /></tr></thead>
                  <tbody>
                    {items.map((i) => (
                      <tr key={i.id} className={i.active ? '' : 'inactive'}>
                        <td><code>{i.code}</code></td>
                        <td>{i.label} {i.is_system && <span className="badge">Sistema</span>}</td>
                        <td>{i.sort}</td><td>{i.active ? 'Activa' : 'Oculta'}</td>
                        <td className="actions">
                          {!i.is_system && can(me, 'catalogos', 'editar') && <><button onClick={() => toggle(i)}>{i.active ? 'Ocultar' : 'Mostrar'}</button><button onClick={() => setItemForm(i)}>Editar</button></>}
                          {!i.is_system && can(me, 'catalogos', 'eliminar') && <button className="danger" onClick={() => removeItem(i)}>Eliminar</button>}
                        </td>
                      </tr>
                    ))}
                    {items.length === 0 && <tr><td colSpan={5} className="muted">Sin entradas todavía.</td></tr>}
                  </tbody>
                </table>
              </div>
            </>
          )}
        </div>
      </div>
      {typeForm && <TypeForm type={typeForm === 'new' ? null : typeForm} onClose={() => setTypeForm(null)} onSaved={(key) => { setTypeForm(null); if (key) setSel(key); refresh(); }} />}
      {itemForm && <ItemForm catalog={sel} item={itemForm === 'new' ? null : itemForm} onClose={() => setItemForm(null)} onSaved={() => { setItemForm(null); refresh(); }} />}
    </section>
  );
}

function useSubmit(action: () => Promise<any>, done: (result: any) => void) {
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setBusy(true); setError('');
    try { done(await action()); } catch (e2) { const ae = e2 as ApiError; setError(Object.values(ae.errors).flat().join(' ') || ae.message); } finally { setBusy(false); }
  };
  return { error, busy, submit };
}

function TypeForm({ type, onClose, onSaved }: { type: CType | null; onClose: () => void; onSaved: (key?: string) => void }) {
  const [label, setLabel] = useState(type?.label ?? '');
  const [description, setDescription] = useState(type?.description ?? '');
  const { error, busy, submit } = useSubmit(
    () => (type ? api('PUT', `catalogs/${type.key}`, { label, description: description || null }) : api('POST', 'catalogs', { label, description: description || null })),
    (r: CType) => onSaved(r?.key),
  );
  return (
    <Modal title={type ? 'Renombrar catálogo' : 'Nuevo catálogo'} onClose={onClose}>
      <form onSubmit={submit} className="form one">
        <label><span>Nombre *</span><input value={label} onChange={(e) => setLabel(e.target.value)} required maxLength={120} autoFocus /></label>
        <label><span>Descripción</span><textarea rows={2} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={500} /></label>
        {error && <p className="error">{error}</p>}
        <div className="buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="primary" disabled={busy}>Guardar</button></div>
      </form>
    </Modal>
  );
}

function ItemForm({ catalog, item, onClose, onSaved }: { catalog: string; item: Item | null; onClose: () => void; onSaved: () => void }) {
  const [label, setLabel] = useState(item?.label ?? '');
  const [code, setCode] = useState('');
  const [sort, setSort] = useState(String(item?.sort ?? ''));
  const { error, busy, submit } = useSubmit(
    () => (item
      ? api('PUT', `catalogs/${catalog}/items/${item.id}`, { label, ...(sort !== '' && { sort: Number(sort) }) })
      : api('POST', `catalogs/${catalog}/items`, { label, ...(code && { code }), ...(sort !== '' && { sort: Number(sort) }) })),
    () => onSaved(),
  );
  return (
    <Modal title={item ? 'Editar entrada' : 'Nueva entrada'} onClose={onClose}>
      <form onSubmit={submit} className="form one">
        <label><span>Nombre *</span><input value={label} onChange={(e) => setLabel(e.target.value)} required maxLength={255} autoFocus /></label>
        {!item && <label><span>Código (opcional)</span><input value={code} onChange={(e) => setCode(e.target.value)} placeholder="Se genera a partir del nombre" maxLength={50} /></label>}
        <label><span>Orden (opcional)</span><input type="number" min={0} value={sort} onChange={(e) => setSort(e.target.value)} /></label>
        {error && <p className="error">{error}</p>}
        <div className="buttons"><button type="button" onClick={onClose}>Cancelar</button><button className="primary" disabled={busy}>Guardar</button></div>
      </form>
    </Modal>
  );
}
