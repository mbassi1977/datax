<?php

namespace App\Support\Mail;

use App\Jobs\SendTransactionalMail;
use App\Models\User;
use App\Support\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Punto único de envío de correo. Prepara el contenido, deja constancia en la bitácora y despacha el envío.
 * HRX_MAIL_QUEUE=false (por defecto): se envía justo después de responder la solicitud, sin necesitar un worker.
 * HRX_MAIL_QUEUE=true: va a la cola (queue:work); recomendado en producción por reintentos y para no frenar la API.
 */
class MailService
{
    public function __construct(private SettingsService $settings) {}

    /** El proveedor está configurado (si no, MAIL_MAILER=log solo escribe los correos en el log). */
    public function driver(): string { return (string) config('mail.default'); }

    public function sendToUser(User $user, string $template, array $data = []): string
    {
        return $this->send($user->tenant_id, $user->email, $template, $data);
    }

    public function send(string $tenantId, string $to, string $template, array $data = []): string
    {
        $app = $this->settings->effective($tenantId, null)['brand.name'] ?: config('app.name');
        $content = MailTemplates::render($template, $data + ['app' => $app]);
        $id = (string) Str::uuid();
        DB::table('audit.mail_log')->insert(['id' => $id, 'tenant_id' => $tenantId, 'to_email' => $to, 'template' => $template, 'subject' => $content['subject'], 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

        $job = new SendTransactionalMail($id, $tenantId, $to, $content['subject'], $content);
        config('hrx.mail_queue') ? dispatch($job) : dispatch($job)->afterResponse();

        return $id;
    }

    /** Enlace de la interfaz web (ruta con hash). */
    public static function link(string $path): string
    {
        return rtrim((string) config('app.url'), '/').'/#/'.ltrim($path, '/');
    }
}
