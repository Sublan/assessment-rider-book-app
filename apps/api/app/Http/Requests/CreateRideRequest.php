<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\RideCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateRideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(RideCategory::values())],
            'pickup' => ['required', 'array'],
            'pickup.label' => ['required', 'string', 'min:2', 'max:160'],
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination' => ['required', 'array'],
            'destination.label' => ['required', 'string', 'min:2', 'max:160'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category.in' => 'Choose either economy or comfort.',
            'pickup.label.required' => 'Enter a pickup location.',
            'destination.label.required' => 'Enter a destination.',
            'destination.lat.between' => 'Destination coordinates are out of range.',
        ];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return [
            'Idempotency-Key' => 'Optional key that makes retries safe (8-200 characters).',
        ];
    }
}
