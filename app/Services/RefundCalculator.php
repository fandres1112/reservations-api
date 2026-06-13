<?php

namespace App\Services;

use App\Models\Reservation;
use Carbon\Carbon;

class RefundCalculator
{
    /**
     * Calcula el monto a reembolsar para una cancelación de reserva.
     *
     * @param Reservation $reservation
     * @param Carbon $cancelledAt
     * @return float
     */
    public function calculate(Reservation $reservation, Carbon $cancelledAt): float
    {
        // 1. Si el servicio no es reembolsable, el reembolso es siempre 0%
        if ($reservation->service->non_refundable) {
            return 0.00;
        }

        // Asegurar que las fechas estén en la misma zona horaria para comparación
        $startTime = Carbon::parse($reservation->start_time)->setTimezone('America/Bogota');
        $cancelTime = Carbon::parse($cancelledAt)->setTimezone('America/Bogota');

        // Diferencia en segundos entre la hora de inicio y la hora de cancelación
        $secondsBefore = $cancelTime->diffInSeconds($startTime, false);

        // Si la cancelación es después de que la cita ya haya iniciado
        if ($secondsBefore <= 0) {
            return 0.00;
        }

        // Convertir la diferencia a horas (flotante para máxima precisión)
        $hoursBefore = $secondsBefore / 3600.0;

        $percentage = 0.0;

        if ($reservation->user->is_premium) {
            // Reglas Premium:
            // - Reembolso del 100% hasta 4 horas antes
            // - Reembolso del 50% entre 4 horas y 1 hora antes
            // - Sin reembolso después de ese tiempo (< 1 hora)
            if ($hoursBefore >= 4.0) {
                $percentage = 1.0;
            } elseif ($hoursBefore >= 1.0) {
                $percentage = 0.5;
            } else {
                $percentage = 0.0;
            }
        } else {
            // Reglas Estándar:
            // - Reembolso del 100% con más de 24 horas
            // - Reembolso del 50% entre 24 y 4 horas
            // - Sin reembolso con menos de 4 horas
            if ($hoursBefore >= 24.0) {
                $percentage = 1.0;
            } elseif ($hoursBefore >= 4.0) {
                $percentage = 0.5;
            } else {
                $percentage = 0.0;
            }
        }

        $price = (float) $reservation->service->price;

        return round($price * $percentage, 2);
    }
}
