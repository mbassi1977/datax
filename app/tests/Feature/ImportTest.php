<?php

namespace Tests\Feature;

use App\Imports\Rut;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\SecuritySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ImportTest extends TestCase
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
        $this->tenant = Tenant::create(['name' => 'T1', 'slug' => 't1']);
        $this->a = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Empresa A', 'rut' => '76.000.000-0']);
        $this->b = Organization::create(['tenant_id' => $this->tenant->id, 'legal_name' => 'Empresa B']);
    }

    private function user(string $profile = 'admin', string $scopeType = 'global', ?string $scopeId = null): User
    {
        $user = User::create(['tenant_id' => $this->tenant->id, 'email' => Str::random(6).'@t.cl', 'password' => 'Secreto-12345']);
        DB::table('security.user_profiles')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id,
            'profile_id' => DB::table('security.profiles')->where('code', $profile)->value('id'),
            'scope_type' => $scopeType, 'scope_id' => $scopeId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    /** CSV como lo guarda Excel en español: ';' y Windows-1252. */
    private function csv(string $content, string $name = 'personas.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, mb_convert_encoding($content, 'Windows-1252', 'UTF-8'));
    }

    private function upload(string $target, UploadedFile $file, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/imports', ['target' => $target, 'file' => $file] + $extra, ['Accept' => 'application/json']);
    }

    private function runImport(string $id, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/imports/{$id}/run", $body);
    }

    private const PEOPLE = "RUT Trabajador;Nombre;Apellido;Correo electrónico;Fecha nac.;Cargo;Empresa;Roles\n"
        ."12.345.678-5;Ana;Pérez;ANA@Empresa.cl;15/03/1990;Analista;76.000.000-0;trabajador, relator\n"
        ."11111111-1;Luis;Soto;luis@empresa.cl;1985-07-02;Jefe;Empresa A;Trabajador\n"
        ."9.999.999-9;Mala;Rut;mala@x.cl;;;Empresa A;\n"
        ."14.141.414-3;Sin;Correo;no-es-correo;32/13/2020;;Empresa A;astronauta\n"
        ."15.151.515-0;Org;Fantasma;;;;No existe SpA;\n"
        ."12.345.678-5;Dup;Licado;;;;Empresa A;\n"
        .";Sin;Rut;;;;Empresa A;consultor\n"
        ."\n";

    public function test_rut_helper(): void
    {
        $this->assertTrue(Rut::isValid('12.345.678-5'));
        $this->assertTrue(Rut::isValid('11111111-1'));
        $this->assertFalse(Rut::isValid('12.345.678-9'));
        $this->assertSame('12.345.678-5', Rut::format('123456785'));
        $this->assertTrue(Rut::isValid('6.000.000-k'));                          // dígito verificador K
        $this->assertSame('6.000.000-K', Rut::format('6000000k'));
    }

    public function test_upload_suggests_mapping_from_spanish_headers_and_reads_latin1(): void
    {
        $this->actingAs($this->user(), 'sanctum');
        $res = $this->upload('people', $this->csv(self::PEOPLE))->assertCreated();

        $this->assertSame(7, $res->json('total_rows'));
        $this->assertSame('Correo electrónico', $res->json('headers.3'));      // tildes bien leídas
        $m = $res->json('suggested_mapping');
        $this->assertSame([0, 1, 2, 3, 4, 5, 6, 7], [$m['rut'], $m['first_name'], $m['last_name'], $m['email'], $m['birth_date'], $m['job_title'], $m['organization'], $m['roles']]);
    }

    public function test_simulation_reports_row_errors_without_writing_then_commit_loads_valid_rows(): void
    {
        $admin = $this->user();
        $this->actingAs($admin, 'sanctum');
        $up = $this->upload('people', $this->csv(self::PEOPLE))->assertCreated();
        $id = $up->json('id');
        $map = $up->json('suggested_mapping');

        $sim = $this->runImport($id, ['mode' => 'simulate', 'mapping' => $map])->assertOk();
        $this->assertSame('simulated', $sim->json('status'));
        $this->assertSame([3, 0, 0, 4], [$sim->json('created_count'), $sim->json('updated_count'), $sim->json('skipped_count'), $sim->json('error_count')]);
        $this->assertSame(0, Person::count());                                     // la simulación no escribe

        $messages = collect($sim->json('errors'))->map(fn ($e) => "{$e['row']}:{$e['field']}")->all();
        foreach (['4:rut', '5:email', '5:birth_date', '5:roles', '6:organization'] as $expected) {
            $this->assertContains($expected, $messages);
        }
        $this->assertContains('7:', $messages);                                    // RUT repetido en el archivo (error de fila completa)

        $done = $this->runImport($id, ['mode' => 'commit', 'mapping' => $map])->assertOk();
        $this->assertSame('completed', $done->json('status'));
        $this->assertSame(3, Person::count());

        $ana = Person::where('rut', '12.345.678-5')->first();
        $this->assertSame('ana@empresa.cl', $ana->email);                          // correo normalizado
        $this->assertSame('1990-03-15', $ana->birth_date->toDateString());
        $this->assertSame(['trabajador', 'relator'], $ana->roles);
        $this->assertSame($this->a->id, $ana->organization_id);                    // por RUT de empresa
        $this->assertSame($this->a->id, Person::where('rut', '11.111.111-1')->value('organization_id')); // por nombre
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'import_completed']);
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'created', 'entity_id' => $ana->id]);
        $this->assertCount(0, Storage::disk('local')->allFiles());                 // el archivo se borra al terminar

        $this->runImport($id, ['mode' => 'commit', 'mapping' => $map])->assertStatus(409);
    }

    public function test_reimport_skips_existing_unless_update_is_requested_and_keeps_unmapped_data(): void
    {
        $this->actingAs($this->user(), 'sanctum');
        $csv = "RUT;Nombre;Cargo\n12.345.678-5;Ana;Analista\n";
        $first = $this->upload('people', $this->csv($csv))->assertCreated();
        $this->runImport($first->json('id'), ['mode' => 'commit', 'mapping' => $first->json('suggested_mapping')])->assertOk();
        Person::where('rut', '12.345.678-5')->update(['email' => 'conservar@x.cl']);

        $csv2 = "RUT;Nombre;Cargo\n12.345.678-5;Ana María;Gerente\n";
        $skip = $this->upload('people', $this->csv($csv2))->assertCreated();
        $r = $this->runImport($skip->json('id'), ['mode' => 'commit', 'mapping' => $skip->json('suggested_mapping')])->assertOk();
        $this->assertSame([0, 0, 1], [$r->json('created_count'), $r->json('updated_count'), $r->json('skipped_count')]);

        $upd = $this->upload('people', $this->csv($csv2))->assertCreated();
        $r = $this->runImport($upd->json('id'), ['mode' => 'commit', 'mapping' => $upd->json('suggested_mapping'), 'update_existing' => true])->assertOk();
        $this->assertSame([0, 1, 0], [$r->json('created_count'), $r->json('updated_count'), $r->json('skipped_count')]);
        $p = Person::where('rut', '12.345.678-5')->first();
        $this->assertSame(['Ana María', 'Gerente', 'conservar@x.cl'], [$p->first_name, $p->job_title, $p->email]);
        $this->assertSame(1, Person::count());
        $this->assertDatabaseHas('audit.audit_events', ['action' => 'updated', 'entity_id' => $p->id]);
    }

    public function test_abort_mode_writes_nothing_when_any_row_fails(): void
    {
        $this->actingAs($this->user(), 'sanctum');
        $csv = "RUT;Nombre\n12.345.678-5;Ana\n12.345.678-9;Rut malo\n";
        $up = $this->upload('people', $this->csv($csv))->assertCreated();
        $r = $this->runImport($up->json('id'), ['mode' => 'commit', 'on_error' => 'abort', 'mapping' => $up->json('suggested_mapping')])->assertOk();

        $this->assertSame('rejected', $r->json('status'));
        $this->assertSame(0, Person::count());
    }

    public function test_default_organization_and_scope_are_enforced(): void
    {
        $scoped = $this->user('admin', 'organization', $this->a->id);
        $this->actingAs($scoped, 'sanctum');
        $csv = "RUT;Nombre;Empresa\n12.345.678-5;Ana;Empresa A\n11.111.111-1;Luis;Empresa B\n15.151.515-0;Sin empresa;\n";
        $up = $this->upload('people', $this->csv($csv))->assertCreated();
        $r = $this->runImport($up->json('id'), ['mode' => 'commit', 'mapping' => $up->json('suggested_mapping')])->assertOk();

        $this->assertSame(1, $r->json('created_count'));                           // solo la de su organización
        $this->assertSame(2, $r->json('error_count'));                             // B ajena y sin organización (solo global)
        $this->assertSame(['12.345.678-5'], Person::pluck('rut')->all());

        // con organización por defecto dentro del alcance, la fila sin empresa entra a esa organización
        $up2 = $this->upload('people', $this->csv("RUT;Nombre\n15.151.515-0;Sin empresa\n"))->assertCreated();
        $this->runImport($up2->json('id'), ['mode' => 'commit', 'mapping' => $up2->json('suggested_mapping'), 'organization_id' => $this->a->id])->assertOk()->assertJsonPath('created_count', 1);
        $this->assertSame($this->a->id, Person::where('rut', '15.151.515-0')->value('organization_id'));
        $this->runImport($up2->json('id'), ['mode' => 'simulate', 'mapping' => $up2->json('suggested_mapping'), 'organization_id' => $this->b->id])->assertStatus(409);

        $up3 = $this->upload('people', $this->csv("RUT;Nombre\n16.161.616-8;X\n"))->assertCreated();
        $this->runImport($up3->json('id'), ['mode' => 'simulate', 'mapping' => $up3->json('suggested_mapping'), 'organization_id' => $this->b->id])->assertStatus(403);
    }

    public function test_organizations_import_from_xlsx_requires_global_scope(): void
    {
        $path = sys_get_temp_dir().'/orgs-'.Str::random(5).'.xlsx';
        $w = new Writer();
        $w->openToFile($path);
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['RUT', 'Razón social', 'Tipo', 'Correo', 'Rubro']));
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['77.777.777-7', 'Cliente Uno SpA', 'Cliente', 'uno@c.cl', 'Minería']));
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['', 'Proveedor Dos', 'Proveedor', '', '']));
        $w->addRow(\OpenSpout\Common\Entity\Row::fromValues(['76.000.000-0', 'Empresa A', 'Empresa', '', '']));   // ya existe
        $w->close();
        $file = new UploadedFile($path, 'orgs.xlsx', null, null, true);

        $this->actingAs($this->user('admin', 'organization', $this->a->id), 'sanctum');
        $up = $this->upload('organizations', $file)->assertCreated();
        $this->runImport($up->json('id'), ['mode' => 'simulate', 'mapping' => $up->json('suggested_mapping')])->assertOk()->assertJsonPath('error_count', 2);

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user(), 'sanctum');
        $up = $this->upload('organizations', new UploadedFile($path, 'orgs.xlsx', null, null, true))->assertCreated();
        $this->assertSame(3, $up->json('total_rows'));
        $this->assertSame(4, $up->json('suggested_mapping.industry'));
        $r = $this->runImport($up->json('id'), ['mode' => 'commit', 'mapping' => $up->json('suggested_mapping')])->assertOk();
        $this->assertSame([2, 0, 1], [$r->json('created_count'), $r->json('updated_count'), $r->json('skipped_count')]);
        $this->assertSame('client', Organization::where('rut', '77.777.777-7')->value('type'));
        $this->assertSame('provider', Organization::where('legal_name', 'Proveedor Dos')->value('type'));
        @unlink($path);
    }

    public function test_required_mapping_permissions_and_templates(): void
    {
        // sin permiso de crear personas no hay destino disponible
        $this->actingAs($this->user('colaborador'), 'sanctum');
        $this->getJson('/api/v1/imports/targets')->assertStatus(403); // ni siquiera tiene importaciones.ver

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user(), 'sanctum');
        $this->assertEqualsCanonicalizing(['organizations', 'people'], collect($this->getJson('/api/v1/imports/targets')->json())->pluck('key')->all());
        $this->get('/api/v1/imports/targets/people/template', ['Accept' => 'text/csv'])->assertOk()->assertHeader('content-disposition');
        $this->upload('people', UploadedFile::fake()->createWithContent('x.pdf', 'nada'))->assertStatus(422);
        $this->upload('people', $this->csv(''))->assertStatus(422);

        $up = $this->upload('people', $this->csv("Identificador;Nombre de pila\n12.345.678-5;Ana\n"))->assertCreated();
        $this->runImport($up->json('id'), ['mode' => 'simulate', 'mapping' => ['first_name' => null]])->assertStatus(422); // nombre obligatorio sin columna

        $map = ['rut' => 0, 'first_name' => 1];
        $this->runImport($up->json('id'), ['mode' => 'commit', 'mapping' => $map, 'save_template_as' => 'Mi planilla'])->assertOk();

        $next = $this->upload('people', $this->csv("Nombre de pila;Identificador\nLuis;11.111.111-1\n"), ['template_id' => DB::table('integrations.import_templates')->value('id')])->assertCreated();
        $this->assertSame([1, 0], [$next->json('suggested_mapping.rut'), $next->json('suggested_mapping.first_name')]); // la plantilla reconoce las columnas aunque cambien de orden
        $this->getJson('/api/v1/import-templates')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/v1/imports')->assertOk()->assertJsonPath('data.0.status', 'completed');
    }

    public function test_abandoned_uploads_are_pruned(): void
    {
        $this->actingAs($this->user(), 'sanctum');
        $up = $this->upload('people', $this->csv("RUT;Nombre\n12.345.678-5;Ana\n"))->assertCreated();
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->artisan('imports:prune')->assertSuccessful();
        $this->assertCount(1, Storage::disk('local')->allFiles());      // recién subido: se conserva

        DB::table('integrations.import_jobs')->update(['created_at' => now()->subDays(2)]);
        $this->artisan('imports:prune')->assertSuccessful();
        $this->assertCount(0, Storage::disk('local')->allFiles());
        $this->runImport($up->json('id'), ['mode' => 'simulate', 'mapping' => $up->json('suggested_mapping')])->assertStatus(422);
    }
}
