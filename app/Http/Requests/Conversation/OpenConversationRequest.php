<?php

namespace App\Http\Requests\Conversation;

use App\Models\Conversation;
use App\Models\Product;
use App\Models\Variant;
use App\Rules\ConversationFile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Abrir uma conversa a partir da montra (contacto, "Tenho interesse" ou peca
 * personalizada). Rota publica: o travao e o throttle da rota e o anti-spam.
 *
 * Anti-spam sem captcha (o CSP nao deixa scripts de terceiros):
 *   - `website` e um campo escondido: so um robo o preenche;
 *   - `started` e a hora a que a pagina abriu, encriptada pelo servidor: um
 *     envio em menos de 3 segundos e de um robo;
 *   - mais de 3 links na mensagem e publicidade.
 * O spam recebe um sucesso falso (isSpam()) e nao fica nada gravado. Um
 * formulario aberto ha mais de 2 horas pede so para enviar outra vez.
 */
class OpenConversationRequest extends FormRequest
{
    public const MIN_SECONDS = 3;

    public const MAX_SECONDS = 2 * 60 * 60;

    public const MAX_LINKS = 3;

    private ?bool $spam = null;

    public function authorize(): bool
    {
        return true;
    }

    public function isSpam(): bool
    {
        if ($this->spam !== null) {
            return $this->spam;
        }

        $started = $this->startedAt();
        $elapsed = $started === null ? null : now()->getTimestamp() - $started;

        return $this->spam = trim((string) $this->input('website', '')) !== ''
            || $elapsed === null
            || $elapsed < self::MIN_SECONDS
            || preg_match_all('#https?://|www\.#i', (string) $this->input('message', '')) > self::MAX_LINKS;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Spam: nada a validar, o controlador responde com o sucesso falso.
        if ($this->isSpam()) {
            return [];
        }

        return [
            'type' => ['required', Rule::in(Conversation::TYPES)],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'message' => ['required', 'string', 'max:5000'],
            'product_id' => ['nullable', 'required_if:type,interest', 'integer', Rule::exists(Product::class, 'id')],
            'variant_id' => ['nullable', 'integer', Rule::exists(Variant::class, 'id')],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'personalization' => ['nullable', 'array', 'max:10'],
            'personalization.*' => ['nullable', 'string', 'max:100'],
            'details' => ['nullable', 'array'],
            'details.purpose' => ['nullable', 'string', 'max:300'],
            'details.dimensions' => ['nullable', 'string', 'max:200'],
            'details.quantity' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'details.color' => ['nullable', 'string', 'max:100'],
            'details.material' => ['nullable', 'string', 'max:100'],
            'details.deadline' => ['nullable', 'date', 'after:today'],
            'consent' => ['exclude_unless:type,quote', 'accepted'],
            'files' => ['nullable', 'array', 'max:'.ConversationFile::MAX_FILES],
            'files.*' => ['file', 'max:'.ConversationFile::MAX_KB, new ConversationFile],
            'started' => ['required', 'string'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        if ($this->isSpam()) {
            return [];
        }

        return [function (Validator $validator): void {
            $started = $this->startedAt();

            if ($started !== null && now()->getTimestamp() - $started > self::MAX_SECONDS) {
                $validator->errors()->add('started', trans('store.conversation.expired'));
            }

            $variant = $this->integer('variant_id') ?: null;
            if ($variant !== null && Variant::query()->whereKey($variant)->value('product_id') !== $this->integer('product_id')) {
                $validator->errors()->add('variant_id', trans('store.conversation.variant_mismatch'));
            }

            $total = array_sum(array_map(
                fn (mixed $file): int => $file instanceof UploadedFile ? (int) $file->getSize() : 0,
                (array) $this->file('files', []),
            ));

            if ($total > ConversationFile::MAX_TOTAL_KB * 1024) {
                $validator->errors()->add('files', trans('store.conversation.files_too_big'));
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => trans('store.attributes.name'),
            'email' => trans('store.attributes.email'),
            'phone' => trans('store.attributes.phone'),
            'message' => trans('store.attributes.message'),
            'quantity' => trans('store.attributes.quantity'),
            'consent' => trans('store.attributes.consent_short'),
            'files' => trans('store.attributes.files'),
            'files.*' => trans('store.attributes.file'),
            'details.deadline' => trans('store.attributes.deadline'),
        ];
    }

    /**
     * Os dados para o ConversationService::open().
     *
     * @return array{type: string, name: string, email: string, message: string, locale: string, phone: string|null, product_id: int|null, variant_id: int|null, quantity: int|null, personalization: array<string, string>|null, details: array<string, mixed>|null}
     */
    public function conversation(): array
    {
        $type = (string) $this->validated('type');
        $details = $type === 'quote' ? array_filter((array) $this->validated('details', []), fn (mixed $value): bool => $value !== null && $value !== '') : [];

        return [
            'type' => $type,
            'name' => (string) $this->validated('name'),
            'email' => (string) $this->validated('email'),
            'message' => (string) $this->validated('message'),
            'locale' => app()->getLocale(),
            'phone' => $this->validated('phone'),
            'product_id' => $this->validated('product_id') === null ? null : (int) $this->validated('product_id'),
            'variant_id' => $this->validated('variant_id') === null ? null : (int) $this->validated('variant_id'),
            'quantity' => $this->validated('quantity') === null ? null : (int) $this->validated('quantity'),
            'personalization' => $this->validated('personalization'),
            'details' => $details === [] ? null : $details,
        ];
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function attachments(): array
    {
        return array_values(array_filter((array) $this->file('files', []), fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    private function startedAt(): ?int
    {
        try {
            $value = Crypt::decryptString((string) $this->input('started', ''));
        } catch (DecryptException) {
            return null;
        }

        return ctype_digit($value) ? (int) $value : null;
    }
}
