<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListReservationsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => 'nullable|integer|exists:users,id',
            'status' => 'nullable|string|in:active,cancelled',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ];
    }
}
