<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
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
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:255', Rule::unique(Tenant::class, 'id')],
            'name' => ['required', 'string', 'max:255'],
            'ip' => ['required', 'ip'],
            'rate_limit' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
