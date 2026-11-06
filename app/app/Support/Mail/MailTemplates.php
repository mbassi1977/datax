<?php

namespace App\Support\Mail;

/** Textos de los correos transaccionales (español). Cada plantilla devuelve asunto, saludo, párrafos y un botón opcional. */
class MailTemplates
{
    public const KEYS = ['password_reset', 'invitation', 'audit_assigned', 'audit_returned', 'audit_approved', 'finding_assigned', 'test'];

    /** @return array{subject:string, lines:array<string>, action:?array{label:string,url:string}, footnote:?string} */
    public static function render(string $key, array $d): array
    {
        $app = $d['app'] ?? 'HRX';

        return match ($key) {
            'password_reset' => [
                'subject' => "Restablecer su contraseña de {$app}",
                'lines' => ['Recibimos una solicitud para restablecer su contraseña.', "El enlace es válido por {$d['minutes']} minutos y solo puede usarse una vez."],
                'action' => ['label' => 'Elegir nueva contraseña', 'url' => $d['url']],
                'footnote' => 'Si usted no lo solicitó, ignore este correo: su contraseña no cambiará.',
            ],
            'invitation' => [
                'subject' => "Acceso a {$app}",
                'lines' => ["Se creó una cuenta para usted en {$app}.", 'Use el botón para elegir su contraseña y entrar. El enlace vence en '.$d['days'].' días.'],
                'action' => ['label' => 'Activar mi cuenta', 'url' => $d['url']],
                'footnote' => null,
            ],
            'audit_assigned' => [
                'subject' => "Nueva auditoría asignada: {$d['title']}",
                'lines' => ["Se le asignó la auditoría «{$d['title']}»."] + (isset($d['when']) ? [1 => "Fecha programada: {$d['when']}."] : []) + (isset($d['due']) ? [2 => "Plazo: {$d['due']}."] : []),
                'action' => ['label' => 'Abrir mis auditorías', 'url' => $d['url']],
                'footnote' => null,
            ],
            'audit_returned' => [
                'subject' => "Auditoría devuelta para corrección: {$d['title']}",
                'lines' => ["La revisión devolvió la auditoría «{$d['title']}»."] + (! empty($d['notes']) ? [1 => "Comentario: {$d['notes']}"] : []),
                'action' => ['label' => 'Corregir auditoría', 'url' => $d['url']],
                'footnote' => null,
            ],
            'audit_approved' => [
                'subject' => "Auditoría aprobada: {$d['title']}",
                'lines' => ["La auditoría «{$d['title']}» fue aprobada."],
                'action' => ['label' => 'Ver auditoría', 'url' => $d['url']],
                'footnote' => null,
            ],
            'finding_assigned' => [
                'subject' => "Hallazgo asignado: {$d['title']}",
                'lines' => ["Se le asignó el hallazgo «{$d['title']}»."] + (! empty($d['due']) ? [1 => "Plazo de resolución: {$d['due']}."] : []),
                'action' => ['label' => 'Ver hallazgos', 'url' => $d['url']],
                'footnote' => null,
            ],
            'test' => [
                'subject' => "Prueba de correo de {$app}",
                'lines' => ['Este es un correo de prueba.', 'Si lo está leyendo, el envío de correos está funcionando.'],
                'action' => null,
                'footnote' => null,
            ],
        };
    }
}
