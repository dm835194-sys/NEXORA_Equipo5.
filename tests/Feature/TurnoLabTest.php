<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\Kit;
use App\Models\Lab;
use App\Models\Loan;
use App\Models\Notice;
use App\Models\Practice;
use App\Models\QrToken;
use App\Models\Reservation;
use App\Models\User;
use App\Services\OperationService;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TurnoLabTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $other;

    private User $tech;

    private User $teacher;

    private Practice $practice;

    private Kit $kit;

    private Kit $second;

    protected function setUp(): void
    {
        parent::setUp();
        config(['turnolab.demo' => true]);
        $this->student = User::create(['name' => 'Demo', 'email' => 'student@example.test', 'password' => Hash::make('Demo1234!'), 'role' => 'student', 'matricula' => 'DEMO-001']);
        $this->other = User::create(['name' => 'Otro demo', 'email' => 'other@example.test', 'password' => Hash::make('Demo1234!'), 'role' => 'student', 'matricula' => 'DEMO-002']);
        $this->tech = User::create(['name' => 'Técnico', 'email' => 'tech@example.test', 'password' => Hash::make('Demo1234!'), 'role' => 'technician', 'matricula' => 'TEC-001']);
        $this->teacher = User::create(['name' => 'Docente', 'email' => 'teacher@example.test', 'password' => Hash::make('Demo1234!'), 'role' => 'teacher', 'matricula' => 'DOC-001']);
        $lab = Lab::create(['name' => 'Laboratorio demo', 'location' => 'A', 'capacity' => 20]);
        $this->practice = Practice::create(['title' => 'Práctica', 'lab_id' => $lab->id, 'teacher_id' => $this->teacher->id, 'kit_type' => 'medicion', 'starts_at' => now()->addDay()->startOfDay()->hour(9), 'ends_at' => now()->addDay()->startOfDay()->hour(10)->minute(40), 'capacity' => 20]);
        foreach (['KIT-001', 'KIT-002'] as $code) {
            $kit = Kit::create(['code' => $code, 'name' => $code, 'type' => 'medicion', 'location' => 'A-01', 'description' => 'Kit de prueba']);
            for ($i = 1; $i <= 2; $i++) {
                Asset::create(['code' => $code.'-A'.$i, 'name' => 'Componente '.$i, 'kit_id' => $kit->id, 'category' => 'medicion', 'location' => 'A-01', 'status' => 'available', 'diagnosed_at' => now()]);
            }
        }
        $this->kit = Kit::first();
        $this->second = Kit::orderByDesc('id')->first();
    }

    private function reserve(?User $user = null, ?Kit $kit = null, ?Practice $practice = null): Reservation
    {
        $p = $practice ?? $this->practice;

        return app(ReservationService::class)->reserve($user ?? $this->student, $p->id, ($kit ?? $this->kit)->id, $p->starts_at->copy()->subMinutes(45)->toDateTimeString());
    }

    private function ready(): Reservation
    {
        $r = $this->reserve();
        app(ReservationService::class)->prepare($this->tech, $r);

        return $r->fresh();
    }

    private function assistedData(Reservation $r): array
    {
        return ['matricula' => $r->user->matricula, 'kit_code' => $r->kit->code, 'components' => $r->assets->pluck('id')->all(), 'assisted' => true, 'reason' => 'Matrícula verificada por dispositivo perdido.'];
    }

    private function active(): Loan
    {
        $r = $this->ready();

        return app(OperationService::class)->deliver($this->tech, $r, $this->assistedData($r));
    }

    public function test_students_cannot_access_technical_operations(): void
    {
        $r = $this->ready();
        $this->actingAs($this->student)->get('/ventanilla')->assertForbidden();
        $this->post(route('operations.deliver', $r), $this->assistedData($r))->assertForbidden();
        $this->assertDatabaseCount('loans', 0);
    }

    public function test_reservations_assign_real_components_and_block_overlap(): void
    {
        $r = $this->reserve();
        $this->assertCount(2, $r->assets);
        $this->assertDatabaseCount('notices', 2);
        $this->expectException(ValidationException::class);
        $this->reserve($this->other);
    }

    public function test_same_student_cannot_book_overlapping_practices(): void
    {
        $this->reserve();
        $p = $this->practice->replicate();
        $p->save();
        $this->expectException(ValidationException::class);
        $this->reserve($this->student, $this->second, $p);
    }

    public function test_unavailable_component_blocks_reservation(): void
    {
        $this->kit->assets()->first()->update(['status' => 'pending_review']);
        $this->expectException(ValidationException::class);
        $this->reserve();
    }

    public function test_cancellation_releases_prepared_assets_and_notifications(): void
    {
        $r = $this->ready();
        app(ReservationService::class)->cancel($this->student, $r);
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertSame(2, $this->kit->assets()->where('status', 'available')->count());
        $this->reserve($this->other);
        $this->assertDatabaseCount('reservations', 2);
    }

    public function test_qr_is_private_short_lived_and_contains_no_personal_data(): void
    {
        $r = $this->ready();
        $this->actingAs($this->other)->postJson(route('reservations.qr', $r))->assertForbidden();
        $response = $this->actingAs($this->student)->postJson(route('reservations.qr', $r))->assertOk();
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $response->json('image'));
        $this->assertStringNotContainsString($this->student->matricula, $response->json('code'));
        $token = basename($response->json('code'));
        $this->assertDatabaseHas('qr_tokens', ['token_hash' => hash('sha256', $token)]);
    }

    public function test_delivery_consumes_token_and_records_responsibility(): void
    {
        $r = $this->ready();
        $token = 'valid-token';
        QrToken::create(['reservation_id' => $r->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinute()]);
        $data = $this->assistedData($r);
        $data['assisted'] = false;
        $data['token'] = $token;
        $loan = app(OperationService::class)->deliver($this->tech, $r, $data);
        $this->assertNotNull(QrToken::first()->used_at);
        $this->assertSame('active', $loan->status);
        $this->assertSame('active', $r->fresh()->status);
        $this->expectException(ValidationException::class);
        app(OperationService::class)->deliver($this->tech, $r, $data);
    }

    public function test_expired_token_does_not_create_loan(): void
    {
        $r = $this->ready();
        QrToken::create(['reservation_id' => $r->id, 'token_hash' => hash('sha256', 'expired'), 'expires_at' => now()->subMinute()]);
        $data = $this->assistedData($r);
        $data['assisted'] = false;
        $data['token'] = 'expired';
        $this->actingAs($this->tech)->post(route('operations.deliver', $r), $data)->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('loans', 0);
    }

    public function test_wrong_identity_and_wrong_kit_are_rejected(): void
    {
        $r = $this->ready();
        $data = $this->assistedData($r);
        $data['matricula'] = 'WRONG';
        $this->actingAs($this->tech)->post(route('operations.deliver', $r), $data)->assertSessionHasErrors('operation');
        $data['matricula'] = $r->user->matricula;
        $data['kit_code'] = 'KIT-999';
        $this->post(route('operations.deliver', $r), $data)->assertSessionHasErrors('operation');
        $this->assertDatabaseCount('loans', 0);
    }

    public function test_return_pending_review_does_not_release_assets(): void
    {
        $loan = $this->active();
        $r = $loan->reservation;
        app(OperationService::class)->receive($this->tech, $loan, ['components' => $r->assets->pluck('id')->all(), 'checked' => false]);
        $this->assertSame('review', $loan->fresh()->status);
        $this->assertSame(2, $r->assets()->where('status', 'pending_review')->count());
        app(OperationService::class)->inspect($this->tech, $loan, 'Prueba funcional completa, sin fallas.');
        $this->assertSame('completed', $loan->fresh()->status);
    }

    public function test_missing_component_creates_incident_and_blocks_release(): void
    {
        $loan = $this->active();
        $r = $loan->reservation;
        app(OperationService::class)->receive($this->tech, $loan, ['components' => [$r->assets->first()->id], 'checked' => true, 'notes' => 'Falta el segundo componente del kit.']);
        $this->assertDatabaseHas('incidents', ['type' => 'missing', 'status' => 'open']);
        $this->assertSame('review', $loan->fresh()->status);
        $this->expectException(ValidationException::class);
        app(OperationService::class)->inspect($this->tech, $loan, 'Se verificó el funcionamiento.');
    }

    public function test_verified_return_closes_loan_and_keeps_history(): void
    {
        $loan = $this->active();
        app(OperationService::class)->receive($this->tech, $loan, ['components' => $loan->reservation->assets->pluck('id')->all(), 'checked' => true, 'notes' => 'Kit completo y funcional.']);
        $this->assertSame('completed', $loan->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'loan.received']);
        $this->assertDatabaseCount('loans', 1);
    }

    public function test_scheduler_only_delivers_due_notices(): void
    {
        $this->reserve();
        $n = Notice::first();
        $n->update(['scheduled_at' => now()->subMinute()]);
        $this->artisan('turnolab:alerts')->assertSuccessful();
        $this->assertSame('delivered', $n->fresh()->status);
        $this->assertSame(1, Notice::where('status', 'pending')->count());
    }

    public function test_offline_import_is_idempotent_by_folio(): void
    {
        $r = $this->ready();
        $csv = "folio,operacion,reserva,matricula,kit,fecha,observaciones\nOFF-001,entrega,{$r->code},{$r->user->matricula},{$r->kit->code},".now()->subSeconds(5)->format('Y-m-d H:i:s').",Verificación completa\n";
        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->tech)->post(route('contingency.import'), ['file' => UploadedFile::fake()->createWithContent('backup.csv', $csv), 'authority' => 1])->assertSessionHasNoErrors();
        }
        $this->assertDatabaseCount('loans', 1);
        $this->assertSame(1, AuditEvent::where('offline_folio', 'OFF-001')->count());
    }

    public function test_offline_batch_rolls_back_all_new_rows_on_conflict(): void
    {
        $r = $this->ready();
        $csv = "folio,operacion,reserva,matricula,kit,fecha,observaciones\nOFF-002,entrega,{$r->code},{$r->user->matricula},{$r->kit->code},".now()->subSeconds(5)->format('Y-m-d H:i:s').",Verificación completa\nOFF-003,entrega,NO-EXISTE,WRONG,KIT-002,".now()->subSeconds(4)->format('Y-m-d H:i:s').",Prueba de conflicto\n";
        $this->actingAs($this->tech)->post(route('contingency.import'), ['file' => UploadedFile::fake()->createWithContent('backup.csv', $csv), 'authority' => 1])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('loans', 0);
    }

    public function test_teacher_cannot_double_book_lab(): void
    {
        $this->actingAs($this->teacher)->post(route('practices.store'), ['title' => 'Conflicto', 'lab_id' => $this->practice->lab_id, 'kit_type' => 'medicion', 'starts_at' => $this->practice->starts_at->toDateTimeString(), 'ends_at' => $this->practice->ends_at->toDateTimeString(), 'capacity' => 20])->assertSessionHasErrors('starts_at');
    }

    public function test_main_operator_pages_render(): void
    {
        $this->ready();
        $this->actingAs($this->tech);
        foreach (['/', '/inventario', '/practicas', '/avisos', '/reglamento', '/indicadores', '/simulacion', '/reservas', '/reservas/nueva', '/ventanilla', '/incidencias', '/contingencia'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_availability_marks_conflicting_kit_unavailable_without_exposing_personal_data(): void
    {
        $this->reserve();
        $response = $this->actingAs($this->other)->getJson(route('practices.availability',$this->practice));
        $response->assertOk()->assertJsonPath('kits.0.available',false)->assertJsonPath('kits.1.available',true);
        $response->assertJsonMissing(['matricula' => 'DEMO-001']);
    }
}
