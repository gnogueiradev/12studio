<?php

namespace App\Http\Requests\Store;

use App\Http\Requests\AdminFormRequest;

/**
 * Criar uma landing page ou mudar-lhe o nome, o endereco e as datas. O
 * endereco (formato, reservados, repetidos) confere-o o PageBuilderService.
 */
class LandingPageRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => 'nome', 'slug' => 'endereço', 'starts_at' => 'início', 'ends_at' => 'fim'];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return ['ends_at.after' => 'O fim tem de ser depois do início.'];
    }

    /**
     * @return array{name: string, slug: string, starts_at: string|null, ends_at: string|null}
     */
    public function landing(): array
    {
        return [
            'name' => (string) $this->validated('name'),
            'slug' => (string) $this->validated('slug'),
            'starts_at' => $this->validated('starts_at'),
            'ends_at' => $this->validated('ends_at'),
        ];
    }
}
