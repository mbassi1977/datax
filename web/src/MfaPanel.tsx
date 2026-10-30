import { FormEvent, useEffect, useState } from 'react';
import QRCode from 'qrcode';
import { api, ApiError, Me } from './api';

type Setup = { secret: string; otpauth_uri: string };

const msg = (e: unknown) => { const ae = e as ApiError; return Object.values(ae.errors ?? {}).flat().join(' ') || ae.message; };

/** Activar, administrar o desactivar la verificación en dos pasos (TOTP). `forced`: la empresa la exige. */
export function MfaPanel({ me, reload, forced = false }: { me: Me; reload: () => void; forced?: boolean }) {
  const enabled = Boolean(me.mfa?.enabled);
  const [setup, setSetup] = useState<Setup | null>(null);
  const [qr, setQr] = useState('');
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [saved, setSaved] = useState(false);
  const [mode, setMode] = useState<'' | 'disable' | 'regen'>('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => { setQr(''); if (setup) QRCode.toDataURL(setup.otpauth_uri, { margin: 1, width: 192 }).then(setQr).catch(() => {}); }, [setup]);

  const run = async (fn: () => Promise<void>) => { setBusy(true); setError(''); try { await fn(); } catch (e) { setError(msg(e)); } finally { setBusy(false); } };

  const start = () => run(async () => setSetup(await api<Setup>('POST', 'me/mfa/setup')));
  const confirm = (e: FormEvent) => { e.preventDefault(); return run(async () => { const r = await api<{ recovery_codes: string[] }>('POST', 'me/mfa/confirm', { code }); setCodes(r.recovery_codes); setSetup(null); setCode(''); }); };
  const finish = () => { setCodes(null); setSaved(false); setMode(''); reload(); };

  if (codes) {
    return (
      <div>
        <h3>Guarde sus códigos de recuperación</h3>
        <p className="muted">Si pierde su teléfono, cada código le permite entrar <strong>una sola vez</strong>. No se vuelven a mostrar. Guárdelos en un lugar seguro (por ejemplo, un gestor de contraseñas).</p>
        <ul className="codes">{codes.map((c) => <li key={c}><code>{c}</code></li>)}</ul>
        <div className="buttons left">
          <button onClick={() => navigator.clipboard?.writeText(codes.join('\n'))}>Copiar</button>
          <button onClick={() => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([`Códigos de recuperación HRX (${me.email})\n\n${codes.join('\n')}\n`], { type: 'text/plain' })); a.download = 'codigos-recuperacion-hrx.txt'; a.click(); }}>Descargar .txt</button>
        </div>
        <label className="check"><input type="checkbox" checked={saved} onChange={(e) => setSaved(e.target.checked)} /> Ya guardé mis códigos</label>
        <div className="buttons"><button className="primary" disabled={!saved} onClick={finish}>Continuar</button></div>
      </div>
    );
  }

  if (setup) {
    return (
      <form onSubmit={confirm} className="form one">
        <p>1. Instale una aplicación autenticadora (Google Authenticator, Microsoft Authenticator, Authy, 1Password…).</p>
        <p>2. Escanee este código QR o ingrese la clave manualmente:</p>
        <div className="qr">{qr ? <img src={qr} alt="Código QR para la aplicación autenticadora" width={192} height={192} /> : <span className="muted">Generando…</span>}</div>
        <p className="muted">Clave manual: <code className="secret">{setup.secret.match(/.{1,4}/g)?.join(' ')}</code></p>
        <label><span>3. Ingrese el código de 6 dígitos que muestra la aplicación</span>
          <input value={code} onChange={(e) => setCode(e.target.value)} inputMode="numeric" autoComplete="one-time-code" maxLength={7} pattern="[0-9 ]*" required autoFocus />
        </label>
        {error && <p className="error">{error}</p>}
        <div className="buttons"><button type="button" onClick={() => { setSetup(null); setError(''); }}>Cancelar</button><button className="primary" disabled={busy}>Activar</button></div>
      </form>
    );
  }

  if (enabled) {
    return (
      <div>
        <p><span className="badge completed">Activa</span> Su cuenta pide un código de la aplicación autenticadora al iniciar sesión. Le quedan <strong>{me.mfa?.recovery_remaining ?? 0}</strong> códigos de recuperación.</p>
        {mode ? <ReauthForm mode={mode} onCancel={() => setMode('')} onDone={(newCodes) => { newCodes ? setCodes(newCodes) : finish(); }} />
          : (
            <div className="buttons left">
              <button onClick={() => setMode('regen')}>Generar nuevos códigos de recuperación</button>
              {!me.settings?.security.mfa_required && <button className="danger" onClick={() => setMode('disable')}>Desactivar</button>}
            </div>
          )}
        {me.settings?.security.mfa_required && <small className="muted">La política de su empresa exige esta protección, por eso no se puede desactivar.</small>}
      </div>
    );
  }

  return (
    <div>
      {forced
        ? <p>Su empresa exige la <strong>verificación en dos pasos</strong>. Actívela para continuar.</p>
        : <p className="muted">Agrega una segunda barrera: además de la contraseña, se pedirá un código que cambia cada 30 segundos desde su teléfono.</p>}
      {error && <p className="error">{error}</p>}
      <div className="buttons left"><button className="primary" onClick={start} disabled={busy}>Activar verificación en dos pasos</button></div>
    </div>
  );
}

function ReauthForm({ mode, onCancel, onDone }: { mode: 'disable' | 'regen'; onCancel: () => void; onDone: (codes?: string[]) => void }) {
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [useRecovery, setUseRecovery] = useState(false);
  const [error, setError] = useState('');
  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError('');
    const body = { password, ...(useRecovery ? { recovery_code: code } : { code }) };
    try {
      if (mode === 'disable') { await api('DELETE', 'me/mfa', body); onDone(); }
      else onDone((await api<{ recovery_codes: string[] }>('POST', 'me/mfa/recovery-codes', body)).recovery_codes);
    } catch (e2) { setError(msg(e2)); }
  };
  return (
    <form onSubmit={submit} className="form one">
      <label><span>Su contraseña</span><input type="password" value={password} onChange={(e) => setPassword(e.target.value)} required autoFocus /></label>
      <label><span>{useRecovery ? 'Un código de recuperación' : 'Código de 6 dígitos de la aplicación'}</span>
        <input value={code} onChange={(e) => setCode(e.target.value)} required autoComplete="one-time-code" inputMode={useRecovery ? 'text' : 'numeric'} />
      </label>
      <button type="button" className="link" onClick={() => { setUseRecovery(!useRecovery); setCode(''); }}>{useRecovery ? 'Usar el código de la aplicación' : 'Usar un código de recuperación'}</button>
      {error && <p className="error">{error}</p>}
      <div className="buttons"><button type="button" onClick={onCancel}>Cancelar</button><button className={mode === 'disable' ? 'danger' : 'primary'}>{mode === 'disable' ? 'Desactivar' : 'Generar'}</button></div>
    </form>
  );
}
