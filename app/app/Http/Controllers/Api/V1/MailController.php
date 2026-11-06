<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Access\AccessService;
use App\Support\Mail\MailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Estado del envío de correos, prueba y bitácora. Es configuración de la plataforma: exige alcance global. */
class MailController extends Controller
{
    public function __construct(private MailService $mail, private AccessService $access) {}

    public function status(Request $request): JsonResponse
    {
        $this->global($request, 'configuracion.ver');
        $t = $request->user()->tenant_id;
        $log = DB::table('audit.mail_log')->where('tenant_id', $t);

        return response()->json([
            'driver' => $this->mail->driver(),
            'live' => ! in_array($this->mail->driver(), ['log', 'array'], true),   // log = solo se escribe en el log del servidor
            'from_address' => config('mail.from.address'),
            'queued_mode' => (bool) config('hrx.mail_queue'),
            'counts' => (clone $log)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status'),
            'recent' => (clone $log)->orderByDesc('created_at')->limit(15)->get(['id', 'to_email', 'template', 'subject', 'status', 'error', 'created_at']),
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $this->global($request, 'configuracion.editar');
        $data = $request->validate(['to' => ['nullable', 'email']]);
        $to = $data['to'] ?? $request->user()->email;
        $this->mail->send($request->user()->tenant_id, $to, 'test');

        return response()->json(['message' => "Correo de prueba enviado a {$to}."], 202);
    }

    /** Envía al usuario un enlace para elegir su contraseña (invitación). */
    public function sendAccess(Request $request, string $id): JsonResponse
    {
        $this->global($request, 'usuarios.editar');
        $user = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        abort_unless($user->status === 'active', 422, 'El usuario no está activo.');
        PasswordResetController::issue($this->mail, $user, 'invitation');

        return response()->json(['message' => "Se envió el enlace de acceso a {$user->email}."], 202);
    }

    private function global(Request $request, string $permission): void
    {
        abort_unless($this->access->allowedOrganizationIds($request->user(), $permission, $request->header('X-Profile')) === null, 403, 'Requiere alcance global.');
    }
}
