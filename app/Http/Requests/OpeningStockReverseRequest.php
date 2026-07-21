<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OpeningStockReverseRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isSuperAdmin() || $user->isAdmin());
    }

    public function rules(): array
    {
        return [
            'remarks' => ['required', 'string', 'max:1000'],
        ];
    }
}
