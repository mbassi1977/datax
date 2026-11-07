<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesSelloutClient;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SelloutPoint;
use App\Models\SelloutRequest;
use App\Models\User;
use App\Support\Access\AccessService;
use App\Support\Mail\MailService;
use App\Support\Media\ImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/** Solicitudes del cliente (a una sala o zona) y su ciclo: pendiente → atendida (con respuesta) → cerrada (conforme) o reabierta. */
class SelloutRequestController extends Controller
{
    use ResolvesSelloutClient;

    public function __construct(private AccessService $access, private MailService $mail) {}

    public function index(Request $request): JsonResponse
    {
        $org = $this->client($request, 'sellout_solicitudes.ver');
        $rows = SelloutRequest::where('organization_id', $org)->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByRaw("case status when 'pending' then 0 when 'attended' then 1 else 2 end")->latest()->get();
        $points = DB::table('sellout.points')->whereIn('id', $rows->pluck('point_id')->filter())->pluck('name', 'id');
        $users = DB::table('security.users')->whereIn('id', $rows->pluck('created_by')->merge($rows->pluck('responded_by'))->filter()->unique())->pluck('email', 'id');

        return response()->json($rows->map(fn ($r) => $this->present($r, $points, $users)));
    }

    public function store(Request $request): JsonResponse
    {
        $org = $this->client($request, 'sellout_solicitudes.crear');
        $data = $request->validate(['scope' => ['required', 'in:point,zone'], 'point_id' => ['required_if:scope,point', 'nullable', 'uuid'], 'zone_label' => ['required_if:scope,zone', 'nullable', 'string', 'max:200'], 'body' => ['required', 'string', 'max:2000']]);
        if ($data['scope'] === 'point') {
            abort_unless(SelloutPoint::where('organization_id', $org)->where('id', $data['point_id'])->exists(), 422, 'La sala no existe.');
        }
        $r = SelloutRequest::create(['tenant_id' => $request->user()->tenant_id, 'organization_id' => $org, 'scope' => $data['scope'], 'point_id' => $data['scope'] === 'point' ? $data['point_id'] : null,
            'zone_label' => $data['scope'] === 'zone' ? $data['zone_label'] : null, 'body' => $data['body'], 'created_by' => $request->user()->id, 'log' => [$this->entry('Solicitud enviada', $request)]]);
        AuditEvent::record('sellout_request_created', $r, null, ['scope' => $r->scope]);

        return response()->json($this->present($r, collect(), collect([$request->user()->id => $request->user()->email])), 201);
    }

    /** El supervisor explica qué se hizo (obligatorio) y puede adjuntar una foto. */
    public function respond(Request $request, string $id): JsonResponse
    {
        $r = $this->find($request, $id, 'sellout_solicitudes.atender');
        abort_unless($r->status === 'pending', 409, 'La solicitud ya fue atendida.');
        $data = $request->validate(['response' => ['required', 'string', 'min:3', 'max:2000'], 'file' => ['nullable', 'file', 'max:15360']]);
        $photo = null;
        if ($request->hasFile('file')) {
            try {
                $photo = ImageStore::store($request->file('file'), "sellout/{$r->tenant_id}/requests", 1600);
            } catch (RuntimeException $e) {
                abort(422, $e->getMessage());
            }
        }
        $r->update(['status' => 'attended', 'response' => $data['response'], 'response_photo' => $photo['path'] ?? null, 'response_mime' => $photo['mime'] ?? null, 'responded_by' => $request->user()->id, 'responded_at' => now(),
            'log' => array_merge($r->log ?? [], [$this->entry('Atendida por el supervisor'.($photo ? ' · con foto de evidencia' : ''), $request)])]);
        AuditEvent::record('sellout_request_attended', $r, ['status' => 'pending'], ['status' => 'attended']);

        // El cliente que la envió recibe la respuesta por correo; un fallo de correo no debe romper la operación.
        try {
            $creator = User::find($r->created_by);
            $creator && $this->mail->sendToUser($creator, 'sellout_request_attended', ['url' => MailService::link('sellout/solicitudes'), 'where' => $this->label($r), 'response' => $data['response']]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json($this->present($r->refresh(), collect(), collect()));
    }

    /** El cliente confirma conformidad. */
    public function close(Request $request, string $id): JsonResponse
    {
        $r = $this->find($request, $id, 'sellout_solicitudes.crear');
        abort_unless($r->status === 'attended', 409, 'Solo se puede cerrar una solicitud atendida.');
        $r->update(['status' => 'closed', 'closed_at' => now(), 'log' => array_merge($r->log ?? [], [$this->entry('Cerrada: el cliente confirmó conformidad', $request)])]);

        return response()->json($this->present($r->refresh(), collect(), collect()));
    }

    /** El cliente no queda conforme: vuelve a pendiente y la respuesta anterior queda en el historial. */
    public function reopen(Request $request, string $id): JsonResponse
    {
        $r = $this->find($request, $id, 'sellout_solicitudes.crear');
        abort_unless($r->status === 'attended', 409, 'Solo se puede reabrir una solicitud atendida.');
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null;
        $r->update(['status' => 'pending', 'response' => null, 'response_photo' => null, 'response_mime' => null, 'responded_by' => null, 'responded_at' => null,
            'log' => array_merge($r->log ?? [], [$this->entry('Reabierta por el cliente'.($reason ? ': '.$reason : '').'. Respuesta anterior: '.$r->response, $request)])]);

        return response()->json($this->present($r->refresh(), collect(), collect()));
    }

    private function find(Request $request, string $id, string $permission): SelloutRequest
    {
        $r = SelloutRequest::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
        $this->authorizeOn($request, $permission, $r->organization_id);

        return $r;
    }

    private function entry(string $text, Request $request): array
    {
        return ['at' => now()->toIso8601String(), 'by' => $request->user()->email, 'text' => $text];
    }

    private function label(SelloutRequest $r): string
    {
        return $r->scope === 'point' ? (string) DB::table('sellout.points')->where('id', $r->point_id)->value('name') : (string) $r->zone_label;
    }

    private function present(SelloutRequest $r, $points, $users): array
    {
        return ['id' => $r->id, 'scope' => $r->scope, 'point_id' => $r->point_id, 'label' => $r->scope === 'point' ? ($points[$r->point_id] ?? $this->label($r)) : $r->zone_label, 'body' => $r->body, 'status' => $r->status,
            'response' => $r->response, 'response_photo' => $r->response_photo ? URL::temporarySignedRoute('media.sellout-response', now()->startOfHour()->addHours(12), ['req' => $r->id], absolute: false) : null,
            'created_by' => $users[$r->created_by] ?? null, 'created_at' => $r->created_at, 'responded_at' => $r->responded_at, 'closed_at' => $r->closed_at, 'log' => $r->log ?? []];
    }
}
