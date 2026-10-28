<?php

namespace App\Http\Requests\Conversation;

use App\Rules\ConversationFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Uma resposta numa conversa: do cliente (pelo link) ou da loja (backoffice,
 * onde tambem pode ser uma nota interna). Quem pode responder decide a rota.
 */
class ReplyConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:5000'],
            'internal' => ['sometimes', 'boolean'],
            'files' => ['nullable', 'array', 'max:'.ConversationFile::MAX_FILES],
            'files.*' => ['file', 'max:'.ConversationFile::MAX_KB, new ConversationFile],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $total = array_sum(array_map(fn (UploadedFile $file): int => (int) $file->getSize(), $this->attachments()));

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
            'message' => trans('store.attributes.message'),
            'files' => trans('store.attributes.files'),
            'files.*' => trans('store.attributes.file'),
        ];
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function attachments(): array
    {
        return array_values(array_filter((array) $this->file('files', []), fn (mixed $file): bool => $file instanceof UploadedFile));
    }
}
