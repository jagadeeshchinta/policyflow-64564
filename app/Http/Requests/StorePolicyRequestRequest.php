<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePolicyRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_request_id' => 'required|string|max:100',
            'agent_id' => 'required|string|max:100',
            'customer_name' => 'required|string|max:255',
            'date_of_birth' => 'required|date|before:today',
            'destination' => 'required|string|in:DOMESTIC,INTERNATIONAL',
            'trip_start' => 'required|date|after_or_equal:today',
            'trip_end' => 'required|date|after_or_equal:trip_start',
            'plan_code' => 'required|string|in:BASIC,PLUS,PREMIUM',
            'sum_insured' => 'required|numeric|in:100000,300000,500000',
        ];
    }

    public function messages(): array
    {
        return [
            'destination.in' => 'The destination must be one of: DOMESTIC, INTERNATIONAL.',
            'plan_code.in' => 'The plan_code must be one of: BASIC, PLUS, PREMIUM.',
            'sum_insured.in' => 'The sum_insured must be one of: 100000, 300000, 500000.',
            'trip_end.after_or_equal' => 'The trip_end must be on or after trip_start.',
            'date_of_birth.before' => 'The date_of_birth must be a date before today.',
        ];
    }

    /**
     * Return JSON validation errors with 422 status.
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            response()->json([
                'status' => 'error',
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
