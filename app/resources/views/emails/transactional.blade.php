<!doctype html>
<html lang="es">
<body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1c2330">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:8px;overflow:hidden">
        <tr><td style="background:{{ $color }};color:#ffffff;padding:18px 24px;font-size:18px;font-weight:bold">{{ $appName }}</td></tr>
        <tr><td style="padding:24px;font-size:15px;line-height:1.55">
          @foreach ($c['lines'] as $line)
            <p style="margin:0 0 14px">{{ $line }}</p>
          @endforeach
          @if (! empty($c['action']))
            <p style="margin:22px 0"><a href="{{ $c['action']['url'] }}" style="background:{{ $color }};color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:6px;display:inline-block;font-weight:bold">{{ $c['action']['label'] }}</a></p>
            <p style="margin:0 0 14px;font-size:12px;color:#6b7280">Si el botón no funciona, copie este enlace en su navegador:<br><span style="word-break:break-all">{{ $c['action']['url'] }}</span></p>
          @endif
          @if (! empty($c['footnote']))
            <p style="margin:14px 0 0;font-size:13px;color:#6b7280">{{ $c['footnote'] }}</p>
          @endif
        </td></tr>
        <tr><td style="padding:14px 24px;font-size:12px;color:#9ca3af;border-top:1px solid #eceef2">Mensaje automático de {{ $appName }}. No responda a este correo si no se indicó lo contrario.</td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
