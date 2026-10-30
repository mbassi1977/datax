<?php

namespace Tests\Feature;

use App\Models\Avatar;
use App\Models\AvatarCollection;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AvatarSeeder;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AvatarsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Organization $a;
    private Organization $b;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(SecuritySeeder::class);
        $this->seed(AvatarSeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
        $this->a = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'A']);
        $this->b = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'B']);
    }

    private function user(string $profile = 'colaborador', ?Organization $org = null, string $scope = 'global', ?string $scopeId = null, ?Tenant $tenant = null): User
    {
        $user = User::create(['tenant_id' => ($tenant ?? $this->tenant)->id, 'organization_id' => $org?->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        DB::table('security.user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'),
            'scope_type' => $scope, 'scope_id' => $scopeId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    private function png(int $w = 400, int $h = 300): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagepng($im);

        return UploadedFile::fake()->createWithContent('foto.png', ob_get_clean());
    }

    private function svg(string $body = '<circle cx="5" cy="5" r="4"/>'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('logo.svg', "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 10 10'>{$body}</svg>");
    }

    private function asUser(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    public function test_user_sees_global_collections_and_signed_urls_are_enforced(): void
    {
        $this->asUser($this->user());
        $res = $this->getJson('/api/v1/me/avatar')->assertOk();
        $this->assertSame('Clásicos', $res->json('collections.0.name'));
        $this->assertCount(12, $res->json('collections.0.avatars'));

        $url = $res->json('collections.0.avatars.0.url');
        $ok = $this->get($url)->assertOk();
        $this->assertSame('image/svg+xml', $ok->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox', $ok->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $ok->headers->get('X-Content-Type-Options'));

        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url))->assertStatus(403);
        $this->get(strtok($url, '?'))->assertStatus(403);                       // sin firma
    }

    public function test_choose_from_gallery_respects_assignment_to_the_users_organization(): void
    {
        $admin = $this->user('admin');
        $mine = AvatarCollection::create(['tenant_id' => $this->tenant->id, 'name' => 'Solo B']);
        $avatar = app(\App\Support\Avatars\AvatarService::class)->storeGalleryFile($mine, $this->png());
        $mine->organizations()->sync([$this->b->id]);

        $userA = $this->user('colaborador', $this->a);
        $userB = $this->user('colaborador', $this->b);

        $this->asUser($userA)->putJson('/api/v1/me/avatar', ['avatar_id' => $avatar->id])->assertStatus(403);
        $names = collect($this->getJson('/api/v1/me/avatar')->json('collections'))->pluck('name');
        $this->assertNotContains('Solo B', $names->all());

        $this->asUser($userB);
        $this->assertContains('Solo B', collect($this->getJson('/api/v1/me/avatar')->json('collections'))->pluck('name')->all());
        $this->putJson('/api/v1/me/avatar', ['avatar_id' => $avatar->id])->assertOk()->assertJsonStructure(['avatar_url']);
        $this->assertSame($avatar->id, $userB->fresh()->avatar_id);
        $this->assertSame($userB->fresh()->avatar_url, $this->getJson('/api/v1/me')->json('avatar_url'));
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'avatar_changed', 'user_id' => $userB->id]);

        // si la colección se desactiva ya no es elegible
        $mine->update(['is_active' => false]);
        $this->putJson('/api/v1/me/avatar', ['avatar_id' => $avatar->id])->assertStatus(403);
    }

    public function test_user_uploads_a_photo_that_is_cropped_reencoded_and_replaceable(): void
    {
        $u = $this->user('colaborador', $this->a);
        $this->asUser($u);

        $url = $this->postJson('/api/v1/me/avatar', ['file' => $this->png(400, 300)])->assertCreated()->json('avatar_url');
        $fresh = $u->fresh();
        $this->assertNotNull($fresh->avatar_path);
        $info = getimagesizefromstring(Storage::disk('local')->get($fresh->avatar_path));
        $this->assertSame([256, 256, 'image/webp'], [$info[0], $info[1], $info['mime']]);   // cuadrada, 256 px, WebP

        $img = $this->get($url)->assertOk();
        $this->assertSame('image/webp', $img->headers->get('Content-Type'));

        $old = $fresh->avatar_path;
        $this->postJson('/api/v1/me/avatar', ['file' => $this->png(100, 100)])->assertCreated();
        $this->assertFalse(Storage::disk('local')->exists($old));                           // la foto anterior se borra

        $this->deleteJson('/api/v1/me/avatar')->assertNoContent();
        $this->assertNull($u->fresh()->avatar_path);
        $this->assertNull($this->getJson('/api/v1/me')->json('avatar_url'));
        $this->assertCount(0, Storage::disk('local')->allFiles('avatars/users'));
    }

    public function test_invalid_uploads_are_rejected(): void
    {
        $this->asUser($this->user());
        $this->postJson('/api/v1/me/avatar', ['file' => UploadedFile::fake()->createWithContent('falsa.png', 'esto no es una imagen')])->assertStatus(422);
        $this->postJson('/api/v1/me/avatar', ['file' => $this->svg('<script>alert(1)</script>')])->assertStatus(422);   // los usuarios no suben SVG
        $this->postJson('/api/v1/me/avatar', [])->assertStatus(422);
    }

    public function test_admin_manages_collections_and_assigns_them_to_companies(): void
    {
        $this->asUser($this->user('admin'));
        $cid = $this->postJson('/api/v1/avatar-collections', ['name' => 'Marca', 'description' => 'Avatares corporativos', 'organization_ids' => [$this->a->id]])->assertCreated()->json('id');

        $up = $this->post("/api/v1/avatar-collections/{$cid}/avatars", ['files' => [$this->png(), $this->svg()]], ['Accept' => 'application/json'])->assertCreated();
        $this->assertCount(2, $up->json());
        $this->assertSame(1, collect(Storage::disk('local')->allFiles("avatars/gallery/{$cid}"))->filter(fn ($f) => str_ends_with($f, '.svg'))->count());

        $list = collect($this->getJson('/api/v1/avatar-collections')->assertOk()->json());
        $mine = $list->firstWhere('id', $cid);
        $this->assertSame([$this->a->id], $mine['organization_ids']);
        $this->assertCount(2, $mine['avatars']);
        $this->assertTrue($list->firstWhere('name', 'Clásicos')['is_system']);

        // la empresa A la ve; la B no, hasta que se marque como global
        $this->asUser($this->user('colaborador', $this->a));
        $this->assertContains('Marca', collect($this->getJson('/api/v1/me/avatar')->json('collections'))->pluck('name')->all());
        $this->asUser($this->user('colaborador', $this->b));
        $this->assertNotContains('Marca', collect($this->getJson('/api/v1/me/avatar')->json('collections'))->pluck('name')->all());

        $this->asUser($this->user('admin'))->putJson("/api/v1/avatar-collections/{$cid}", ['is_global' => true])->assertOk();
        $this->asUser($this->user('colaborador', $this->b));
        $this->assertContains('Marca', collect($this->getJson('/api/v1/me/avatar')->json('collections'))->pluck('name')->all());
    }

    public function test_removing_avatars_or_collections_resets_the_users_who_used_them(): void
    {
        $admin = $this->user('admin');
        $c = AvatarCollection::create(['tenant_id' => $this->tenant->id, 'name' => 'X', 'is_global' => true]);
        $svc = app(\App\Support\Avatars\AvatarService::class);
        $one = $svc->storeGalleryFile($c, $this->png());
        $two = $svc->storeGalleryFile($c, $this->png());
        $u1 = $this->user('colaborador', $this->a);
        $u2 = $this->user('colaborador', $this->a);
        $svc->chooseFromGallery($u1, $one);
        $svc->chooseFromGallery($u2, $two);

        $this->asUser($admin);
        $this->putJson("/api/v1/avatars/{$one->id}", ['is_active' => false])->assertOk();
        $this->assertNull($u1->fresh()->avatar_id);
        $this->assertNotNull($u2->fresh()->avatar_id);

        $this->deleteJson("/api/v1/avatar-collections/{$c->id}")->assertNoContent();
        $this->assertNull($u2->fresh()->avatar_id);
        $this->assertNull(AvatarCollection::find($c->id));
    }

    public function test_permissions_scope_and_isolation_for_administration(): void
    {
        $system = AvatarCollection::whereNull('tenant_id')->first();
        $this->asUser($this->user('admin'));
        $this->putJson("/api/v1/avatar-collections/{$system->id}", ['name' => 'Hackeada'])->assertStatus(403);   // del sistema
        $this->deleteJson("/api/v1/avatar-collections/{$system->id}")->assertStatus(403);
        $this->postJson('/api/v1/avatar-collections', ['name' => 'Mala', 'organization_ids' => [(string) Str::uuid()]])->assertStatus(422);

        $other = Tenant::create(['name' => 'T2', 'slug' => 't2']);
        $foreignOrg = Organization::create(['tenant_id' => $other->id, 'legal_name' => 'Ajena']);
        $this->postJson('/api/v1/avatar-collections', ['name' => 'Cruce', 'organization_ids' => [$foreignOrg->id]])->assertStatus(422);   // empresa de otro tenant

        $foreign = AvatarCollection::create(['tenant_id' => $other->id, 'name' => 'De otro tenant', 'is_global' => true]);
        $this->assertNotContains('De otro tenant', collect($this->getJson('/api/v1/avatar-collections')->json())->pluck('name')->all());
        $this->putJson("/api/v1/avatar-collections/{$foreign->id}", ['name' => 'x'])->assertStatus(404);

        $this->asUser($this->user('colaborador'))->getJson('/api/v1/avatar-collections')->assertStatus(403);       // sin permiso
        $this->asUser($this->user('admin', null, 'organization', $this->a->id))->postJson('/api/v1/avatar-collections', ['name' => 'Acotada'])->assertStatus(403);   // exige alcance global
    }

    public function test_users_list_exposes_avatar_url_but_never_the_storage_path(): void
    {
        $admin = $this->user('admin');
        $u = $this->user('colaborador', $this->a);
        app(\App\Support\Avatars\AvatarService::class)->upload($u, $this->png());

        $this->asUser($admin);
        $row = collect($this->getJson('/api/v1/users')->json('data'))->firstWhere('id', $u->id);
        $this->assertStringContainsString('/media/users/', $row['avatar_url']);
        $this->assertArrayNotHasKey('avatar_path', $row);
    }
}
