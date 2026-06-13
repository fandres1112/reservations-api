<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReservationRequest;
use App\Http\Requests\ListReservationsRequest;
use App\Http\Resources\ReservationResource;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    protected ReservationService $reservationService;

    public function __construct(ReservationService $reservationService)
    {
        $this->reservationService = $reservationService;
    }

    /**
     * Listar reservas con filtros opcionales (usuario, estado y rango de fechas).
     */
    public function index(ListReservationsRequest $request): JsonResponse
    {
        $userId = $request->input('user_id') ? (int) $request->input('user_id') : null;

        $reservations = $this->reservationService->list(
            $userId,
            $request->input('status'),
            $request->input('start_date'),
            $request->input('end_date')
        );

        return response()->json([
            'data' => ReservationResource::collection($reservations)->resolve()
        ]);
    }

    /**
     * Crear una nueva reserva.
     */
    public function store(CreateReservationRequest $request): JsonResponse
    {
        $reservation = $this->reservationService->create($request->validated());

        return response()->json([
            'message' => 'Reserva creada exitosamente.',
            'data' => (new ReservationResource($reservation))->resolve()
        ], 201);
    }

    /**
     * Cancelar una reserva.
     */
    public function cancel(int $id, Request $request): JsonResponse
    {
        $cancelledBy = $request->input('cancelled_by') ? (int) $request->input('cancelled_by') : null;

        $reservation = $this->reservationService->cancel($id, $cancelledBy);

        return response()->json([
            'message' => 'Reserva cancelada exitosamente.',
            'data' => (new ReservationResource($reservation))->resolve()
        ]);
    }
}
