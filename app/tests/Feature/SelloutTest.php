<?php

namespace Tests\Feature;

use App\Mail\TransactionalMail;
use App\Models\Organization;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SelloutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Organization $loa;
    private Organization $other;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        config(['hrx.mail_queue' => true, 'queue.default' => 'sync']);
        $this->seed(SecuritySeeder::class);
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
        $this->loa = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'LOA Chile SpA', 'type' => 'client']);
        $this->other = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Otra Marca', 'type' => 'client']);
    }

    private function user(string $profile, string $scope = 'global', ?string $scopeId = null): User
    {
        $u = User::create(['tenant_id' => $this->tenant->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        DB::table('security.user_profiles')->insert(['id' => (string) Str::uuid(), 'user_id' => $u->id, 'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'),
            'scope_type' => $scope, 'scope_id' => $scopeId, 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    private function as(User $u): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($u, 'sanctum');
    }

    private function csv(string $content, string $name = 'ventas.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, mb_convert_encoding($content, 'Windows-1252', 'UTF-8'));
    }

    /** Sube, simula y carga; devuelve la respuesta de la carga. */
    private function load(string $target, string $csv, array $body = []): array
    {
        $up = $this->post('/api/v1/imports', ['target' => $target, 'file' => $this->csv($csv)], ['Accept' => 'application/json'])->assertCreated();
        $payload = ['mapping' => $up->json('suggested_mapping'), 'organization_id' => $this->loa->id] + $body;
        $sim = $this->postJson('/api/v1/imports/'.$up->json('id').'/run', $payload + ['mode' => 'simulate'])->assertOk();
        $done = $this->postJson('/api/v1/imports/'.$up->json('id').'/run', $payload + ['mode' => 'commit'])->assertOk();

        return [$sim->json(), $done->json(), $up->json()];
    }

    private function catalog(User $admin): void
    {
        $this->as($admin);
        foreach ([['Entrenubes', ['entren']], ['Minga Loca', ['minga']]] as [$n, $a]) {
            $this->postJson('/api/v1/sellout/products?organization_id='.$this->loa->id, ['name' => $n, 'aliases' => $a, 'color' => '#2a78d6'])->assertCreated();
        }
    }

    public function test_sales_import_aggregates_by_month_creates_points_and_feeds_the_dataset(): void
    {
        $admin = $this->user('admin');
        $this->catalog($admin);
        $chain = $this->postJson('/api/v1/sellout/chains?organization_id='.$this->loa->id, ['name' => 'Cencosud'])->assertCreated()->json('id');

        // Formato Cencosud: ventas diarias, columnas propias de la cadena.
        $csv = "Fecha;Local ID;Local DESC;Artículo DESC;Venta Neta ($);Venta Unidades;Costo Venta ($)\n"
            ."02/01/2025;J501;Jumbo Bilbao;Cerveza Entrenubes Loa 4.8 Lata;3.512,60;2;2510\n"
            ."15/01/2025;J501;Jumbo Bilbao;Cerveza Entrenubes Loa 4.8 Lata;1000;1;800\n"
            ."03/02/2025;J501;Jumbo Bilbao;Cerveza Loa Minga Loca Ipa;6815;4;5020\n"
            ."04/02/2025;J502;Jumbo Kennedy;Cerveza Entrenubes Loa 4.8 Lata;500;1;400\n"
            ."05/02/2025;J502;Jumbo Kennedy;Producto desconocido;10;1;5\n"
            ."fecha mala;J502;Jumbo Kennedy;Cerveza Entrenubes Loa 4.8 Lata;10;1;5\n";
        [$sim, $done] = $this->load('sellout_sales', $csv, ['extra' => ['chain_id' => $chain]]);

        $this->assertSame(4, $sim['created_count']);                                        // 4 filas buenas
        $this->assertSame(2, $sim['error_count']);                                          // producto sin equivalencia + fecha inválida
        $this->assertSame(3, DB::table('sellout.sales')->count());                          // J501×Entrenubes ene, J501×Minga feb, J502×Entrenubes feb
        $this->assertSame(2, DB::table('sellout.points')->count());                         // salas creadas al cargar
        $row = DB::table('sellout.sales')->where('period_start', '2025-01-01')->first();
        $this->assertEqualsWithDelta(3.0, (float) $row->units, 0.001);                      // días sumados al mes
        $this->assertEqualsWithDelta(4512.6, (float) $row->sales, 0.001);

        // Volver a cargar el mismo período lo reemplaza, no lo duplica.
        $this->load('sellout_sales', $csv, ['extra' => ['chain_id' => $chain]]);
        $this->assertSame(3, DB::table('sellout.sales')->count());
        $this->assertEqualsWithDelta(3.0, (float) DB::table('sellout.sales')->where('period_start', '2025-01-01')->value('units'), 0.001);

        $ds = $this->getJson('/api/v1/sellout/dataset?organization_id='.$this->loa->id)->assertOk()->json();
        $this->assertSame(['2025-01', '2025-02'], $ds['months']);
        $this->assertCount(2, $ds['stores']);
        $bilbao = collect($ds['stores'])->firstWhere('code', 'J501');
        $ent = collect($ds['products'])->firstWhere('name', 'Entrenubes')['id'];
        $this->assertSame(4513, $bilbao['d'][$ent][0][0]);                                  // venta enero
        $this->assertEqualsWithDelta(3.0, $bilbao['d'][$ent][1][0], 0.001);                 // unidades enero
    }

    public function test_points_import_locates_by_address_commune_and_never_downgrades_exact_locations(): void
    {
        $admin = $this->user('admin');
        $this->as($admin);
        $chain = $this->postJson('/api/v1/sellout/chains?organization_id='.$this->loa->id, ['name' => 'Cencosud'])->assertCreated()->json('id');
        $csv = "Cliente;Codigo;Local;Direccion\nCencosud;J992;Jumbo Chamisero;Av. Chicureo 130, Colina\nCencosud;J501;Jumbo Bilbao;Av. Bilbao 100, Las Condes\n";
        $this->load('sellout_points', $csv."Cencosud;J777;Plaza EgaÃ±a;Av. Larrain 1, Las Condes\n");
        $this->assertSame('Plaza Egaña', DB::table('sellout.points')->where('code', 'J777')->value('name'));   // texto con doble codificación
        $p = DB::table('sellout.points')->where('code', 'J992')->first();
        $this->assertSame('commune', $p->geo_quality);
        $this->assertSame('Metropolitana', $p->region);
        $this->assertEqualsWithDelta(-33.2, (float) $p->latitude, 0.35);

        // Corrección manual: pasa a exacta y una nueva carga aproximada no la pisa.
        $this->putJson("/api/v1/sellout/points/{$p->id}", ['latitude' => -33.25, 'longitude' => -70.66])->assertOk();
        $this->load('sellout_points', $csv, ['update_existing' => true]);
        $after = DB::table('sellout.points')->where('code', 'J992')->first();
        $this->assertSame('exact', $after->geo_quality);
        $this->assertEqualsWithDelta(-33.25, (float) $after->latitude, 0.0001);
    }

    public function test_goals_import_and_dataset_targets(): void
    {
        $admin = $this->user('admin');
        $this->as($admin);
        $this->postJson('/api/v1/sellout/chains?organization_id='.$this->loa->id, ['name' => 'Cencosud']);
        $this->load('sellout_points', "Cliente;Codigo;Local;Direccion\nCencosud;J501;Jumbo Bilbao;Av. Bilbao 100, Las Condes\n");
        [, $done] = $this->load('sellout_goals', "Mes asignado;Cliente;Codigo;Meta MES (cajas)\nOctubre;Cencosud;J501;5,73\nOctubre;Cencosud;J999;3\n", ['extra' => ['year' => 2026]]);
        $this->assertSame([1, 1], [$done['created_count'], $done['error_count']]);
        $this->assertEqualsWithDelta(5.73, (float) DB::table('sellout.targets')->value('boxes'), 0.001);
        $this->assertSame('2026-10-01', DB::table('sellout.targets')->value('period_start'));
        $ds = $this->getJson('/api/v1/sellout/dataset?organization_id='.$this->loa->id)->assertOk()->json();
        $this->assertSame(['2026-10'], $ds['months']);
        $this->assertEqualsWithDelta(5.73, $ds['stores'][0]['t'][0], 0.001);
    }

    public function test_request_lifecycle_requires_a_response_notifies_the_client_and_can_be_closed_or_reopened(): void
    {
        $admin = $this->user('admin');
        $this->as($admin);
        $this->postJson('/api/v1/sellout/chains?organization_id='.$this->loa->id, ['name' => 'Cencosud']);
        $this->load('sellout_points', "Cliente;Codigo;Local;Direccion\nCencosud;J501;Jumbo Bilbao;Av. Bilbao 100, Las Condes\n");
        $point = DB::table('sellout.points')->value('id');
        $cliente = $this->user('cliente_sellout', 'organization', $this->loa->id);
        $sup = $this->user('supervisor_sellout', 'organization', $this->loa->id);

        $id = $this->as($cliente)->postJson('/api/v1/sellout/requests', ['scope' => 'point', 'point_id' => $point, 'body' => 'Reforzar exhibición'])->assertCreated()->json('id');
        $this->postJson('/api/v1/sellout/requests', ['scope' => 'zone', 'zone_label' => 'Zona Norte', 'body' => 'Revisar quiebres'])->assertCreated();
        $this->postJson("/api/v1/sellout/requests/{$id}/respond", ['response' => 'x'])->assertForbidden();   // el cliente no atiende

        $this->as($sup)->postJson("/api/v1/sellout/requests/{$id}/respond", [])->assertStatus(422);        // la respuesta es obligatoria
        $photo = UploadedFile::fake()->image('e.png', 400, 300);
        $res = $this->post("/api/v1/sellout/requests/{$id}/respond", ['response' => 'Se rearmó la góndola', 'file' => $photo], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame('attended', $res['status']);
        $this->assertNotNull($res['response_photo']);
        Mail::assertSent(TransactionalMail::class, fn ($m) => $m->hasTo($cliente->email));
        $this->postJson("/api/v1/sellout/requests/{$id}/respond", ['response' => 'otra vez'])->assertStatus(409);

        $this->as($cliente)->postJson("/api/v1/sellout/requests/{$id}/reopen", ['reason' => 'Sigue igual'])->assertOk()->assertJsonPath('status', 'pending')->assertJsonPath('response', null);
        $this->as($sup)->postJson("/api/v1/sellout/requests/{$id}/respond", ['response' => 'Se repuso producto'])->assertOk();
        $closed = $this->as($cliente)->postJson("/api/v1/sellout/requests/{$id}/close")->assertOk()->json();
        $this->assertSame('closed', $closed['status']);
        $this->assertGreaterThanOrEqual(4, count($closed['log']));                                          // enviada, atendida, reabierta, atendida, cerrada

        $this->getJson('/api/v1/sellout/requests')->assertOk()->assertJsonCount(2);
    }

    public function test_field_media_is_supervisor_only_and_everything_is_scoped_to_the_client(): void
    {
        $admin = $this->user('admin');
        $this->as($admin);
        $this->postJson('/api/v1/sellout/chains?organization_id='.$this->loa->id, ['name' => 'Cencosud']);
        $this->load('sellout_points', "Cliente;Codigo;Local;Direccion\nCencosud;J501;Jumbo Bilbao;Av. Bilbao 100, Las Condes\n");
        $point = DB::table('sellout.points')->value('id');
        $cliente = $this->user('cliente_sellout', 'organization', $this->loa->id);
        $sup = $this->user('supervisor_sellout', 'organization', $this->loa->id);
        $foreign = $this->user('supervisor_sellout', 'organization', $this->other->id);

        $this->as($cliente)->postJson("/api/v1/sellout/points/{$point}/notes", ['body' => 'hola'])->assertForbidden();
        $this->post("/api/v1/sellout/points/{$point}/photos", ['file' => UploadedFile::fake()->image('f.jpg', 300, 300)], ['Accept' => 'application/json'])->assertForbidden();

        $this->as($sup)->postJson("/api/v1/sellout/points/{$point}/notes", ['body' => 'Góndola completa'])->assertCreated();
        $ph = $this->post("/api/v1/sellout/points/{$point}/photos", ['file' => UploadedFile::fake()->image('f.jpg', 3000, 2000), 'caption' => 'Cabecera'], ['Accept' => 'application/json'])->assertCreated()->json();
        $this->assertStringContainsString('signature=', $ph['url']);
        $this->post("/api/v1/sellout/points/{$point}/photos", ['file' => UploadedFile::fake()->createWithContent('x.png', 'no es imagen')], ['Accept' => 'application/json'])->assertStatus(422);

        $detail = $this->as($cliente)->getJson("/api/v1/sellout/points/{$point}/detail")->assertOk()->json();                // el cliente ve lo que sube el supervisor
        $this->assertCount(1, $detail['notes']);
        $this->assertCount(1, $detail['photos']);

        // otro cliente (alcance de otra organización) no ve ni toca nada
        $this->as($foreign)->getJson("/api/v1/sellout/points/{$point}/detail")->assertForbidden();
        $this->getJson('/api/v1/sellout/dataset?organization_id='.$this->loa->id)->assertForbidden();
        $this->postJson("/api/v1/sellout/points/{$point}/notes", ['body' => 'intruso'])->assertForbidden();
        $this->as($cliente)->getJson('/api/v1/sellout/clients')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $this->loa->id);
    }

    public function test_product_catalog_with_image_and_protected_deletion(): void
    {
        $admin = $this->user('admin');
        $this->as($admin);
        $p = $this->postJson('/api/v1/sellout/products?organization_id='.$this->loa->id, ['name' => 'Flor de Truco', 'aliases' => ['flor', ' flor ', 'truco'], 'color' => '#eda100'])->assertCreated()->json();
        $this->assertSame(['flor', 'truco'], $p['aliases']);
        $this->postJson('/api/v1/sellout/products?organization_id='.$this->loa->id, ['name' => 'flor de truco'])->assertStatus(422);       // nombre repetido
        $img = $this->post("/api/v1/sellout/products/{$p['id']}/image", ['file' => UploadedFile::fake()->image('lata.png', 600, 1500)], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertStringContainsString('signature=', $img['image']);
        $this->get($img['image'])->assertOk()->assertHeader('content-type', 'image/png');

        $chain = $this->postJson('/api/v1/sellout/chains?organization_id='.$this->loa->id, ['name' => 'Tottus'])->json('id');
        $this->load('sellout_sales', "Fecha;Local ID;Local DESC;Producto;Venta Neta;Unidades\n01/03/2025;T1;Tottus Uno;Flor de Truco;1000;2\n", ['extra' => ['chain_id' => $chain]]);
        $this->deleteJson("/api/v1/sellout/products/{$p['id']}")->assertStatus(409);                                                          // tiene ventas
    }

    public function test_multi_sheet_workbook_lets_you_pick_the_sheet_and_keeps_it_through_simulate_and_commit(): void
    {
        $admin = $this->user('admin');
        $this->as($admin);
        $this->postJson('/api/v1/sellout/chains?organization_id='.$this->loa->id, ['name' => 'Cencosud']);
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        $w = new \OpenSpout\Writer\XLSX\Writer();
        $w->openToFile($path);
        $w->getCurrentSheet()->setName('Resumen');
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Texto suelto', 'otro']));
        $w->addNewSheetAndMakeItCurrent()->setName('Salas');
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Cliente', 'Codigo', 'Local', 'Direccion']));
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['Cencosud', 'J501', 'Jumbo Bilbao', 'Av. Bilbao 100, Las Condes']));
        $w->close();
        $file = new UploadedFile($path, 'libro.xlsx', null, null, true);

        $up = $this->post('/api/v1/imports', ['target' => 'sellout_points', 'file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(['Resumen', 'Salas'], $up->json('sheets'));
        $sw = $this->postJson('/api/v1/imports/'.$up->json('id').'/sheet', ['sheet' => 'Salas'])->assertOk();
        $this->assertSame(['Cliente', 'Codigo', 'Local', 'Direccion'], $sw->json('headers'));
        $payload = ['mapping' => $sw->json('suggested_mapping'), 'organization_id' => $this->loa->id];
        $this->postJson('/api/v1/imports/'.$up->json('id').'/run', $payload + ['mode' => 'simulate'])->assertOk()->assertJsonPath('created_count', 1);
        $this->postJson('/api/v1/imports/'.$up->json('id').'/run', $payload + ['mode' => 'commit'])->assertOk()->assertJsonPath('created_count', 1)->assertJsonPath('error_count', 0);
        $this->assertSame(1, DB::table('sellout.points')->count());
    }
}
