<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Service;
use App\Models\Professional;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $premiumUser;
    protected Service $service;
    protected Service $nonRefundableService;
    protected Professional $professional;

    protected function setUp(): void
    {
        parent::setUp();

        // Configurar entidades base
        $this->user = User::create([
            'name' => 'Juan Regular',
            'email' => 'juan@example.com',
            'password' => bcrypt('password'),
            'is_premium' => false
        ]);

        $this->premiumUser = User::create([
            'name' => 'Maria Premium',
            'email' => 'maria@example.com',
            'password' => bcrypt('password'),
            'is_premium' => true
        ]);

        $this->service = Service::create([
            'name' => 'Corte de Cabello',
            'duration_minutes' => 60,
            'price' => 50000.00,
            'non_refundable' => false
        ]);

        $this->nonRefundableService = Service::create([
            'name' => 'Taller Exclusivo',
            'duration_minutes' => 120,
            'price' => 150000.00,
            'non_refundable' => true
        ]);

        $this->professional = Professional::create([
            'name' => 'Carlos Barbero'
        ]);
    }

    public function test_can_create_reservation_successfully(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-06-16 11:00:00', // 3 horas de anticipación
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.end_time', '2026-06-16 12:00:00');
    }

    public function test_cannot_create_reservation_on_sunday(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-06-21 10:00:00', // Domingo
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_time');
    }

    public function test_cannot_create_reservation_on_holiday(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-12-25 10:00:00', // Navidad (Festivo)
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_time');
    }

    public function test_cannot_create_reservation_outside_operating_hours(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // Intento 1: Inicia antes de las 07:00
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-06-16 06:30:00',
        ]);
        $response->assertStatus(422);

        // Intento 2: Termina después de las 19:00 (Inicia 18:30 + 60 min = 19:30)
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-06-16 18:30:00',
        ]);
        $response->assertStatus(422);
    }

    public function test_cannot_create_reservation_less_than_2_hours_in_advance(): void
    {
        // 10:00:00 America/Bogota = 15:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 15:00:00', 'UTC'));

        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-06-16 11:30:00', // 1.5 horas de anticipación
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_time');
    }

    public function test_cannot_exceed_3_active_future_reservations(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // Crear 3 reservas activas futuras en UTC
        for ($i = 0; $i < 3; $i++) {
            Reservation::create([
                'user_id' => $this->user->id,
                'service_id' => $this->service->id,
                'professional_id' => $this->professional->id,
                'start_time' => Carbon::parse("2026-06-16 " . (10 + $i) . ":00:00", 'America/Bogota')->setTimezone('UTC'),
                'end_time' => Carbon::parse("2026-06-16 " . (11 + $i) . ":00:00", 'America/Bogota')->setTimezone('UTC'),
                'status' => 'active'
            ]);
        }

        // Intentar crear la 4ta
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-06-17 10:00:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('user_id');
    }

    public function test_cannot_cross_professional_reservations(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // Crear reserva existente en UTC: 10:00 a 11:00
        $existing = Reservation::create([
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 11:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);

        // Intentar cruce: 10:30 a 11:30
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->premiumUser->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-06-16 10:30:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_time')
            ->assertJsonFragment([
                'start_time' => ["El profesional ya tiene otra reserva activa que se cruza con este horario (Reserva ID: {$existing->id})."]
            ]);
    }

    public function test_refund_policies_for_regular_user(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // 1. Cancelación con > 24 horas (Inicio: 2026-06-17 10:00:00, 26 horas antes)
        $r1 = Reservation::create([
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-17 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-17 11:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);
        $response = $this->postJson("/api/reservations/{$r1->id}/cancel", ['cancelled_by' => $this->user->id]);
        $response->assertStatus(200);
        $this->assertEquals(50000.00, $response->json('data.refund_amount'));

        // 2. Cancelación entre 24 y 4 horas (Inicio: 2026-06-16 18:00:00, 10 horas antes)
        $r2 = Reservation::create([
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 18:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 19:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);
        $response = $this->postJson("/api/reservations/{$r2->id}/cancel");
        $response->assertStatus(200);
        $this->assertEquals(25000.00, $response->json('data.refund_amount'));

        // 3. Cancelación con < 4 horas (Inicio: 2026-06-16 10:30:00, 2.5 horas antes)
        $r3 = Reservation::create([
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 10:30:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 11:30:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);
        $response = $this->postJson("/api/reservations/{$r3->id}/cancel");
        $response->assertStatus(200);
        $this->assertEquals(0.00, $response->json('data.refund_amount'));
    }

    public function test_refund_policies_for_premium_user(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // 1. Cancelación con > 4 horas (Inicio: 2026-06-16 14:00:00, 6 horas antes) -> 100% refund
        $r1 = Reservation::create([
            'user_id' => $this->premiumUser->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 14:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 15:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);
        $response = $this->postJson("/api/reservations/{$r1->id}/cancel");
        $response->assertStatus(200);
        $this->assertEquals(50000.00, $response->json('data.refund_amount'));

        // 2. Cancelación entre 4 y 1 horas (Inicio: 2026-06-16 10:00:00, 2 horas antes) -> 50% refund
        $r2 = Reservation::create([
            'user_id' => $this->premiumUser->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 11:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);
        $response = $this->postJson("/api/reservations/{$r2->id}/cancel");
        $response->assertStatus(200);
        $this->assertEquals(25000.00, $response->json('data.refund_amount'));

        // 3. Cancelación con < 1 hora (Inicio: 2026-06-16 08:30:00, 30 minutos antes) -> 0% refund
        $r3 = Reservation::create([
            'user_id' => $this->premiumUser->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 08:30:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 09:30:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);
        $response = $this->postJson("/api/reservations/{$r3->id}/cancel");
        $response->assertStatus(200);
        $this->assertEquals(0.00, $response->json('data.refund_amount'));
    }

    public function test_non_refundable_service_never_refunds(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // Cancelación con 48 horas de anticipación para usuario Premium en servicio no reembolsable
        $r = Reservation::create([
            'user_id' => $this->premiumUser->id,
            'service_id' => $this->nonRefundableService->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-18 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-18 12:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);

        $response = $this->postJson("/api/reservations/{$r->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertEquals(0.00, $response->json('data.refund_amount'));
    }

    public function test_can_list_user_reservations_filtered_by_dates(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // Cita 1: 16 de Junio
        Reservation::create([
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 11:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);

        // Cita 2: 20 de Junio
        Reservation::create([
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-20 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-20 11:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);

        // Listar sin filtros
        $response = $this->getJson("/api/reservations?user_id={$this->user->id}");
        $response->assertStatus(200)->assertJsonCount(2, 'data');

        // Filtrar rango: 15 al 17 de Junio
        $response = $this->getJson("/api/reservations?user_id={$this->user->id}&start_date=2026-06-15&end_date=2026-06-17");
        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_cannot_cross_user_reservations(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // Crear una reserva existente para el usuario 1: 10:00 a 11:00
        $existing = Reservation::create([
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 11:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);

        // Crear otro profesional para evitar fallar por la regla del profesional
        $anotherProfessional = Professional::create([
            'name' => 'Dr. Gomez'
        ]);

        // Intentar crear otra reserva para el mismo usuario 1 cruzada: 10:30 a 11:30 con otro profesional
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $anotherProfessional->id,
            'start_time' => '2026-06-16 10:30:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_time')
            ->assertJsonFragment([
                'start_time' => ["El usuario ya tiene otra reserva activa que se cruza con este horario (Reserva ID: {$existing->id})."]
            ]);
    }

    public function test_can_create_reservation_with_alternative_date_formats(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // Caso 1: Formato ISO 8601 (2026-06-16T15:00:00.000Z en UTC = 10:00:00 America/Bogota)
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '2026-06-16T15:00:00.000Z',
        ]);
        $response->assertStatus(201)
            ->assertJsonPath('data.start_time', '2026-06-16 10:00:00');

        // Limpiar base de datos temporalmente para evitar cruces
        Reservation::query()->delete();

        // Caso 2: Formato local con barras 'd/m/Y H:i'
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => '16/06/2026 11:00',
        ]);
        $response->assertStatus(201)
            ->assertJsonPath('data.start_time', '2026-06-16 11:00:00');

        // Limpiar base de datos temporalmente
        Reservation::query()->delete();

        // Caso 3: Unix Timestamp (1781622000 = 2026-06-16 15:00:00 UTC = 10:00:00 America/Bogota)
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => 1781622000,
        ]);
        $response->assertStatus(201)
            ->assertJsonPath('data.start_time', '2026-06-16 10:00:00');

        // Caso 4: Formato completamente inválido (debe fallar)
        $response = $this->postJson('/api/reservations', [
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => 'fecha-totalmente-invalida',
        ]);
        $response->assertStatus(422)
            ->assertJsonValidationErrors('start_time');
    }

    public function test_can_list_all_reservations_and_filter_by_status(): void
    {
        // 08:00:00 America/Bogota = 13:00:00 UTC
        Carbon::setTestNow(Carbon::parse('2026-06-16 13:00:00', 'UTC'));

        // Cita 1: Activa
        Reservation::create([
            'user_id' => $this->user->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-16 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-16 11:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'active'
        ]);

        // Cita 2: Cancelada
        Reservation::create([
            'user_id' => $this->premiumUser->id,
            'service_id' => $this->service->id,
            'professional_id' => $this->professional->id,
            'start_time' => Carbon::parse('2026-06-20 10:00:00', 'America/Bogota')->setTimezone('UTC'),
            'end_time' => Carbon::parse('2026-06-20 11:00:00', 'America/Bogota')->setTimezone('UTC'),
            'status' => 'cancelled',
            'cancelled_at' => Carbon::parse('2026-06-16 12:00:00', 'America/Bogota')->setTimezone('UTC'),
            'cancelled_by' => $this->premiumUser->id,
            'refund_amount' => 100.00
        ]);

        // 1. Listar todas las reservas del sistema
        $response = $this->getJson('/api/reservations');
        $response->assertStatus(200)->assertJsonCount(2, 'data');

        // 2. Listar filtrando por status = active
        $response = $this->getJson('/api/reservations?status=active');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'active');

        // 3. Listar filtrando por status = cancelled
        $response = $this->getJson('/api/reservations?status=cancelled');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'cancelled');
    }

    public function test_non_existent_route_returns_structured_404(): void
    {
        $response = $this->getJson('/api/non-existent-route');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'La ruta solicitada no existe.'
            ]);
    }

    public function test_non_existent_model_returns_structured_404(): void
    {
        // Intento de cancelación de reserva inexistente
        $response = $this->postJson('/api/reservations/999999/cancel');

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'El recurso solicitado no fue encontrado.'
            ]);
    }

    public function test_validation_errors_use_structured_envelope(): void
    {
        // Enviar petición vacía a creación de reserva
        $response = $this->postJson('/api/reservations', []);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Los datos proporcionados no son válidos.'
            ])
            ->assertJsonStructure(['errors']);
    }

    public function test_rate_limiting_is_applied(): void
    {
        $this->mock(\Illuminate\Cache\RateLimiter::class, function ($mock) {
            $mock->shouldReceive('limiter')->with('api')->andReturn(function () {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(60);
            });
            $mock->shouldReceive('tooManyAttempts')->andReturn(true);
            $mock->shouldReceive('availableIn')->andReturn(60);
        });

        $response = $this->getJson('/api/reservations');
        $response->assertStatus(429);
    }
}
