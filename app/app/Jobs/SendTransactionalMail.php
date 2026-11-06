<?php

namespace App\Jobs;

use App\Mail\TransactionalMail;
use App\Support\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Envía un correo ya preparado y actualiza su fila en la bitácora (queued → sent | failed). */
class SendTransactionalMail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function backoff(): array { return [30, 300]; }

    public function __construct(public string $logId, public string $tenantId, public string $to, public string $subject, public array $content) {}

    public function handle(SettingsService $settings): void
    {
        $s = $settings->effective($this->tenantId, null);
        $app = $s['brand.name'] ?: config('app.name');
        DB::table('audit.mail_log')->where('id', $this->logId)->increment('attempts');

        Mail::to($this->to)->send(new TransactionalMail($this->subject, $this->content, $app, $s['mail.from_name'] ?: $app, $s['mail.reply_to'] ?: null, $s['brand.primary_color'] ?? null));

        DB::table('audit.mail_log')->where('id', $this->logId)->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        DB::table('audit.mail_log')->where('id', $this->logId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
    }
}
