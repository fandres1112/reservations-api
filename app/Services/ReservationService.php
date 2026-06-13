<?php

namespace App\Services;

use App\Models\User;
use App\Models\Service;
use App\Models\Professional;
use App\Models\Reservation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    protected RefundCalculator $refundCalculator;

    public function __construct(RefundCalculator $refundCalculator)
    {
        $this->refundCalculator = $refundCalculator;
    }

    /**
     * Registra una nueva reserva tras validar las reglas de negocio en una transacción segura.
     *
     * @param array $data
     * @return Reservation
     * @throws ValidationException
     */
    public function create(array $data): Reservation
    {
        Log::info("Attempting to create reservation", ['data' => $data]);

        // 1. Parsear fecha de inicio en la zona horaria America/Bogota para evaluar reglas de negocio
        $startTimeLocal = $this->parseDateTime($data['start_time']);

        // 2. Horarios de operación: Lunes a Sábado, ni domingos ni festivos
        if ($startTimeLocal->isSunday()) {
            Log::warning("Reservation validation failed: Sunday scheduling attempt", ['data' => $data]);
            throw ValidationException::withMessages([
                'start_time' => ['No se aceptan reservas los domingos.']
            ]);
        }

        // 3. Festivos de Colombia
        $holidays = config('holidays', []);
        $dateStr = $startTimeLocal->format('Y-m-d');
        if (in_array($dateStr, $holidays)) {
            Log::warning("Reservation validation failed: Holiday scheduling attempt", ['data' => $data, 'holiday' => $dateStr]);
            throw ValidationException::withMessages([
                'start_time' => ["No se aceptan reservas en festivos de Colombia ({$dateStr})."]
            ]);
        }

        // 4. Anticipación mínima de 2 horas respecto a la fecha y hora de inicio (basada en reloj local)
        $nowLocal = Carbon::now('America/Bogota');
        $secondsBefore = $nowLocal->diffInSeconds($startTimeLocal, false);
        $hoursAdvance = $secondsBefore / 3600.0;

        if ($hoursAdvance < 2.0) {
            Log::warning("Reservation validation failed: Insufficient advance time", ['data' => $data, 'hours_advance' => $hoursAdvance]);
            throw ValidationException::withMessages([
                'start_time' => ['Una reserva debe crearse con al menos 2 horas de anticipación.']
            ]);
        }

        // Ejecutar toda la lógica de base de datos dentro de una transacción para evitar condiciones de carrera (Race Conditions)
        try {
            return DB::transaction(function () use ($data, $startTimeLocal) {
                // Bloqueamos las filas del usuario y profesional para evitar lecturas sucias concurrentes
                $user = User::where('id', $data['user_id'])->lockForUpdate()->firstOrFail();
                $service = Service::findOrFail($data['service_id']);
                $professional = Professional::where('id', $data['professional_id'])->lockForUpdate()->firstOrFail();

                $endTimeLocal = (clone $startTimeLocal)->addMinutes($service->duration_minutes);

                // 5. Franja horaria de operación (07:00 a 19:00) - Se verifica inicio y fin en Bogotá
                $dayStart = (clone $startTimeLocal)->setTime(7, 0, 0);
                $dayEnd = (clone $startTimeLocal)->setTime(19, 0, 0);

                if ($startTimeLocal->lessThan($dayStart) || $endTimeLocal->greaterThan($dayEnd)) {
                    Log::warning("Reservation validation failed: Outside operating hours", [
                        'data' => $data,
                        'start_local' => $startTimeLocal->toDateTimeString(),
                        'end_local' => $endTimeLocal->toDateTimeString()
                    ]);
                    throw ValidationException::withMessages([
                        'start_time' => ['El servicio debe iniciar y finalizar dentro de la jornada de 07:00 a 19:00 (hora local de Bogotá).']
                    ]);
                }

                // Para persistencia y consultas, convertimos las fechas locales a UTC (estándar de base de datos)
                $startTimeUtc = (clone $startTimeLocal)->setTimezone('UTC');
                $endTimeUtc = (clone $endTimeLocal)->setTimezone('UTC');

                // 6. Límite de reservas por usuario: máx 3 activas futuras (comparado en UTC)
                $activeFutureCount = Reservation::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->where('start_time', '>', Carbon::now('UTC'))
                    ->lockForUpdate()
                    ->count();

                if ($activeFutureCount >= 3) {
                    Log::warning("Reservation validation failed: Active future count limit exceeded", [
                        'user_id' => $user->id,
                        'active_count' => $activeFutureCount
                    ]);
                    throw ValidationException::withMessages([
                        'user_id' => ['El usuario no puede tener más de 3 reservas activas futuras al mismo tiempo.']
                    ]);
                }

                // 7. Cruce de Horario del Profesional (comparado en UTC con pessimistic locking)
                $cruceProfesional = Reservation::where('professional_id', $professional->id)
                    ->where('status', 'active')
                    ->where('start_time', '<', $endTimeUtc)
                    ->where('end_time', '>', $startTimeUtc)
                    ->lockForUpdate()
                    ->first();

                if ($cruceProfesional) {
                    Log::warning("Reservation validation failed: Professional scheduling conflict", [
                        'professional_id' => $professional->id,
                        'conflict_reservation_id' => $cruceProfesional->id
                    ]);
                    throw ValidationException::withMessages([
                        'start_time' => ["El profesional ya tiene otra reserva activa que se cruza con este horario (Reserva ID: {$cruceProfesional->id})."]
                    ]);
                }

                // 8. Cruce de Horario del Usuario (un usuario no puede tener dos citas que se crucen en el mismo horario)
                $cruceUsuario = Reservation::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->where('start_time', '<', $endTimeUtc)
                    ->where('end_time', '>', $startTimeUtc)
                    ->lockForUpdate()
                    ->first();

                if ($cruceUsuario) {
                    Log::warning("Reservation validation failed: User scheduling conflict", [
                        'user_id' => $user->id,
                        'conflict_reservation_id' => $cruceUsuario->id
                    ]);
                    throw ValidationException::withMessages([
                        'start_time' => ["El usuario ya tiene otra reserva activa que se cruza con este horario (Reserva ID: {$cruceUsuario->id})."]
                    ]);
                }

                $reservation = Reservation::create([
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'professional_id' => $professional->id,
                    'start_time' => $startTimeUtc,
                    'end_time' => $endTimeUtc,
                    'status' => 'active',
                ]);

                Log::info("Reservation created successfully", [
                    'reservation_id' => $reservation->id,
                    'user_id' => $user->id,
                    'service_id' => $service->id,
                    'professional_id' => $professional->id,
                    'start_time_local' => $startTimeLocal->toDateTimeString()
                ]);

                return $reservation;
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error("Reservation creation failed: Associated model not found", [
                'data' => $data,
                'exception' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            if (!($e instanceof ValidationException)) {
                Log::error("Reservation creation failed: Unexpected error during transaction", [
                    'data' => $data,
                    'exception' => $e->getMessage()
                ]);
            }
            throw $e;
        }
    }

    /**
     * Cancela una reserva aplicando la política de reembolsos de manera atómica.
     *
     * @param int $id
     * @param int|null $cancelledBy
     * @return Reservation
     * @throws ValidationException
     */
    public function cancel(int $id, ?int $cancelledBy = null): Reservation
    {
        Log::info("Attempting to cancel reservation", ['reservation_id' => $id, 'cancelled_by' => $cancelledBy]);

        try {
            return DB::transaction(function () use ($id, $cancelledBy) {
                $reservation = Reservation::where('id', $id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($reservation->status === 'cancelled') {
                    Log::warning("Reservation cancellation failed: Already cancelled", ['reservation_id' => $id]);
                    throw ValidationException::withMessages([
                        'reservation' => ['La reserva ya se encuentra cancelada.']
                    ]);
                }

                $cancelledAt = Carbon::now('America/Bogota');

                // Calcular reembolso
                $refundAmount = $this->refundCalculator->calculate($reservation, $cancelledAt);

                // Guardamos la fecha de cancelación en UTC
                $reservation->update([
                    'status' => 'cancelled',
                    'cancelled_at' => $cancelledAt->setTimezone('UTC'),
                    'cancelled_by' => $cancelledBy,
                    'refund_amount' => $refundAmount,
                ]);

                Log::info("Reservation cancelled successfully", [
                    'reservation_id' => $reservation->id,
                    'refund_amount' => $refundAmount,
                    'cancelled_by' => $cancelledBy
                ]);

                return $reservation;
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error("Reservation cancellation failed: Reservation not found", [
                'reservation_id' => $id,
                'exception' => $e->getMessage()
            ]);
            throw $e;
        } catch (\Exception $e) {
            if (!($e instanceof ValidationException)) {
                Log::error("Reservation cancellation failed: Unexpected error during transaction", [
                    'reservation_id' => $id,
                    'exception' => $e->getMessage()
                ]);
            }
            throw $e;
        }
    }

    /**
     * Lista las reservas con filtros opcionales (usuario, estado y rango de fechas).
     *
     * @param int|null $userId
     * @param string|null $status
     * @param string|null $startDate
     * @param string|null $endDate
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function list(?int $userId = null, ?string $status = null, ?string $startDate = null, ?string $endDate = null)
    {
        $query = Reservation::query();

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($startDate) {
            // Filtro start_date local se interpreta al inicio del día y se pasa a UTC
            $startDateUtc = Carbon::parse($startDate, 'America/Bogota')->startOfDay()->setTimezone('UTC');
            $query->where('start_time', '>=', $startDateUtc);
        }

        if ($endDate) {
            // Filtro end_date local se interpreta al final del día y se pasa a UTC
            $endDateUtc = Carbon::parse($endDate, 'America/Bogota')->endOfDay()->setTimezone('UTC');
            $query->where('start_time', '<=', $endDateUtc);
        }

        return $query->with(['service', 'professional', 'canceller'])->get();
    }

    /**
     * Intenta parsear la fecha soportando múltiples formatos (Unix timestamp, ISO-8601, d/m/Y H:i, Y-m-d H:i:s)
     *
     * @param mixed $value
     * @return Carbon
     * @throws ValidationException
     */
    private function parseDateTime(mixed $value): Carbon
    {
        if (empty($value)) {
            throw ValidationException::withMessages([
                'start_time' => ['La fecha de inicio es requerida.']
            ]);
        }

        try {
            // Caso 1: Unix timestamp (numérico)
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((int) $value, 'America/Bogota');
            }

            // Caso 2: Formato local d/m/Y H:i
            if (str_contains($value, '/') && !str_contains($value, '-')) {
                return Carbon::createFromFormat('d/m/Y H:i', $value, 'America/Bogota');
            }

            // Caso 3: Formato estándar Y-m-d H:i:s o ISO-8601
            $parsed = Carbon::parse($value, 'America/Bogota');
            return $parsed->setTimezone('America/Bogota');
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'start_time' => ['El formato de la fecha de inicio es inválido. Debe ser una fecha válida (ej. Y-m-d H:i:s, timestamp o ISO-8601).']
            ]);
        }
    }
}
