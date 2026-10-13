<?php

namespace App\Http\Requests\Etsy;

use App\Http\Requests\AdminFormRequest;

/**
 * O video de um produto para a Etsy. Fica guardado ca e vai no envio
 * seguinte; a Etsy aceita um por anuncio.
 */
class UploadEtsyVideoRequest extends AdminFormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'video' => [
                'required', 'file',
                'mimetypes:video/mp4,video/quicktime',
                'max:'.((int) config('etsy.listing.video_max_mb', 100) * 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'video.mimetypes' => 'O vídeo tem de ser MP4 ou MOV.',
            'video.max' => 'O vídeo pode ter até '.config('etsy.listing.video_max_mb', 100).' MB.',
            'video.uploaded' => 'O servidor não aceitou o ficheiro — é provável que seja maior do que o limite de envio.',
        ];
    }
}
