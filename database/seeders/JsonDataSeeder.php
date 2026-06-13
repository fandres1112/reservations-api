<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use App\Models\User;
use App\Models\Service;
use App\Models\Professional;
use App\Models\Reservation;
use Carbon\Carbon;

class JsonDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = base_path('data/seed.json');

        if (!File::exists($path)) {
            $this->command->error("El archivo seed.json no existe en: {$path}");
            return;
        }

        $data = json_decode(File::get($path), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error("Error decodificando JSON: " . json_last_error_msg());
            return;
        }

        // 1. Importar Usuarios
        $this->command->info("Importando usuarios...");
        $importedUsers = 0;
        foreach ($data['users'] as $u) {
            if (empty($u['email']) || empty($u['name'])) {
                $userId = $u['id'] ?? 'desconocido';
                $this->command->warn("Usuario ID {$userId} omitido por falta de email o nombre.");
                continue;
            }

            // Normalizar is_premium
            $isPremium = filter_var($u['is_premium'] ?? false, FILTER_VALIDATE_BOOLEAN);

            User::updateOrCreate(
                ['id' => $u['id']],
                [
                    'name' => $u['name'],
                    'email' => $u['email'],
                    'password' => bcrypt('password123'), // Contraseña genérica por defecto
                    'is_premium' => $isPremium,
                ]
            );
            $importedUsers++;
        }
        $this->command->info("{$importedUsers} usuarios importados exitosamente.");

        // 2. Importar Servicios
        $this->command->info("Importando servicios...");
        $importedServices = 0;
        foreach ($data['services'] as $s) {
            Service::updateOrCreate(
                ['id' => $s['id']],
                [
                    'name' => $s['name'],
                    'duration_minutes' => (int) $s['duration_minutes'],
                    'price' => (float) $s['price'],
                    'non_refundable' => filter_var($s['non_refundable'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ]
            );
            $importedServices++;
        }
        $this->command->info("{$importedServices} servicios importados exitosamente.");

        // 3. Importar Profesionales
        $this->command->info("Importando profesionales...");
        $importedProfessionals = 0;
        foreach ($data['professionals'] as $p) {
            Professional::updateOrCreate(
                ['id' => $p['id']],
                ['name' => $p['name']]
            );
            $importedProfessionals++;
        }
        $this->command->info("{$importedProfessionals} profesionales importados exitosamente.");

        // 4. Importar Reservas
        $this->command->info("Importando reservas...");
        $importedReservations = 0;
        foreach ($data['reservations'] as $r) {
            // Validar existencia de entidades referenciadas (Integridad Referencial)
            $userExists = User::where('id', $r['user_id'])->exists();
            $service = Service::find($r['service_id']);
            $professionalExists = Professional::where('id', $r['professional_id'])->exists();

            if (!$userExists || !$service || !$professionalExists) {
                $this->command->warn("Reserva ID {$r['id']} omitida: Llave foránea inexistente (User: " . ($userExists ? 'OK' : 'FAIL') . ", Service: " . ($service ? 'OK' : 'FAIL') . ", Professional: " . ($professionalExists ? 'OK' : 'FAIL') . ").");
                continue;
            }

            // Parsear e Inconsistencia de Fechas
            $startTime = $this->parseDateTime($r['start_time']);

            if (!$startTime) {
                $this->command->warn("Reserva ID {$r['id']} omitida por formato de fecha inválido: {$r['start_time']}");
                continue;
            }

            // Calcular end_time basado en la duración del servicio
            $endTime = (clone $startTime)->addMinutes($service->duration_minutes);

            // Cancelación
            $status = $r['status'] ?? 'active';
            $cancelledAt = null;
            $cancelledBy = null;
            $refundAmount = null;

            if ($status === 'cancelled') {
                $cancelledAt = isset($r['cancelled_at']) ? $this->parseDateTime($r['cancelled_at']) : null;
                $cancelledBy = $r['cancelled_by'] ?? null;
                $refundAmount = isset($r['refund_amount']) ? (float)$r['refund_amount'] : 0.00;
            }

            Reservation::updateOrCreate(
                ['id' => $r['id']],
                [
                    'user_id' => $r['user_id'],
                    'service_id' => $r['service_id'],
                    'professional_id' => $r['professional_id'],
                    'start_time' => (clone $startTime)->setTimezone('UTC'),
                    'end_time' => (clone $endTime)->setTimezone('UTC'),
                    'status' => $status,
                    'cancelled_at' => $cancelledAt ? (clone $cancelledAt)->setTimezone('UTC') : null,
                    'cancelled_by' => $cancelledBy,
                    'refund_amount' => $refundAmount,
                ]
            );
            $importedReservations++;
        }
        $this->command->info("{$importedReservations} reservas importadas exitosamente.");
    }

    /**
     * Intenta parsear la fecha soportando múltiples formatos comunes
     */
    private function parseDateTime(mixed $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            // Caso 1: Unix timestamp (numérico o string numérico)
            if (is_numeric($value)) {
                return Carbon::createFromTimestamp((int) $value, 'America/Bogota');
            }

            // Caso 2: Formato local d/m/Y H:i
            if (str_contains($value, '/') && !str_contains($value, '-')) {
                return Carbon::createFromFormat('d/m/Y H:i', $value, 'America/Bogota');
            }

            // Caso 3: Formato ISO-8601 u otros formatos estándar soportados por Carbon
            return Carbon::parse($value, 'America/Bogota');
        } catch (\Exception $e) {
            return null;
        }
    }
}
