<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Avatar;
use App\Models\AuditEvent;
use App\Support\Avatars\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Avatar del propio usuario: elegir de las galerías de su empresa, subir una foto o quitarlo. */
class AvatarController extends Controller
{
    public function __construct(private AvatarService $avatars) {}

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'avatar_url' => $this->avatars->userUrl($user),
            'selected_id' => $user->avatar_id,
            'has_upload' => (bool) $user->avatar_path,
            'collections' => $this->avatars->collectionsFor($user)->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'description' => $c->description,
                'avatars' => $c->avatars->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'url' => $this->avatars->galleryUrl($a)])->values(),
            ])->filter(fn ($c) => $c['avatars']->isNotEmpty())->values(),
        ]);
    }

    public function select(Request $request): JsonResponse
    {
        $data = $request->validate(['avatar_id' => ['required', 'uuid']]);
        $avatar = Avatar::find($data['avatar_id']);
        abort_unless($avatar && $this->avatars->canUse($request->user(), $avatar), 403, 'Ese avatar no está disponible para su empresa.');

        $this->avatars->chooseFromGallery($request->user(), $avatar);
        AuditEvent::record('avatar_changed', $request->user(), null, ['source' => 'gallery', 'avatar_id' => $avatar->id]);

        return response()->json(['avatar_url' => $this->avatars->userUrl($request->user()->refresh())]);
    }

    public function upload(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.AvatarService::USER_MAX_KB]]);
        try {
            $this->avatars->upload($request->user(), $request->file('file'));
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        AuditEvent::record('avatar_changed', $request->user(), null, ['source' => 'upload']);

        return response()->json(['avatar_url' => $this->avatars->userUrl($request->user()->refresh())], 201);
    }

    public function remove(Request $request): JsonResponse
    {
        $this->avatars->remove($request->user());
        AuditEvent::record('avatar_changed', $request->user(), null, ['source' => 'removed']);

        return response()->json(null, 204);
    }
}
