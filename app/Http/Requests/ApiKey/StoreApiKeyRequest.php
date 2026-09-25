<?php

namespace App\Http\Requests\ApiKey;

use App\Http\Requests\AdminFormRequest;
use App\Services\ApiKeyService;
use Illuminate\Validation\Rule;

class StoreApiKeyRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'access' => ['required', Rule::in([ApiKeyService::ACCESS_READ, ApiKeyService::ACCESS_WRITE])],
            'days' => ['required', 'integer', Rule::in(ApiKeyService::LIFETIMES)],
        ];
    }
}
