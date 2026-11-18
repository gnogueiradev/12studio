<?php

namespace App\Jobs;

use App\Mail\WelcomePromoCodeMail;
use App\Mcp\McpFormat;
use App\Models\PromoCode;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Gera o codigo de boas-vindas e manda o email.
 *
 * Um por email, para sempre, mesmo com a conta apagada e recriada: quem garante
 * e o indice unico parcial da tabela. O `notified_at` e o que deixa repetir o
 * job depois de um email falhado sem gerar outro codigo nem mandar dois.
 */
class IssueWelcomePromoCode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public int $userId,
        public string $email,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(): void
    {
        $email = (string) PromoCode::normalizeEmail($this->email);
        $promoCode = $this->promoCodeFor($email);

        if ($promoCode->notified_at !== null) {
            $this->log('info', 'Boas-vindas: este email ja recebeu o codigo.', $promoCode->getKey());

            return;
        }

        try {
            Mail::to($email)->send(new WelcomePromoCodeMail($promoCode));
        } catch (Throwable $exception) {
            // As recusas de SMTP trazem o destinatario, e o que falha num job
            // acaba no log. Sem `previous` de proposito: levava o email com ela.
            throw new RuntimeException('O email de boas-vindas falhou: '.$this->withoutEmails($exception->getMessage()));
        }

        $promoCode->forceFill(['notified_at' => now()])->save();

        $this->log('info', 'Boas-vindas: codigo enviado.', $promoCode->getKey());
    }

    public function failed(?Throwable $exception): void
    {
        $this->log('error', 'Boas-vindas: o codigo nao chegou a sair.', null, [
            'error' => $exception === null ? null : Str::limit($this->withoutEmails($exception->getMessage()), 200),
        ]);
    }

    private function withoutEmails(string $text): string
    {
        return (string) preg_replace('/[^\s<>"\']+@[^\s<>"\']+/', '***', $text);
    }

    private function promoCodeFor(string $email): PromoCode
    {
        $existing = $this->existing($email);

        if ($existing !== null) {
            return $existing;
        }

        try {
            return PromoCode::query()->create([
                'code' => $this->newCode(),
                'type' => PromoCode::TYPE_FIXED,
                'value' => (int) config('shop.welcome_code.value_cents'),
                'min_subtotal_cents' => (int) config('shop.welcome_code.min_subtotal_cents'),
                'expires_at' => now()->addDays((int) config('shop.welcome_code.valid_days')),
                'max_uses' => 1,
                'single_use_per_email' => true,
                'channel' => PromoCode::CHANNEL_WELCOME,
                'email' => $email,
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Outro job chegou primeiro. Se nao foi isso, deixa repetir.
            return $this->existing($email) ?? throw $exception;
        }
    }

    private function existing(string $email): ?PromoCode
    {
        return PromoCode::query()
            ->where('channel', PromoCode::CHANNEL_WELCOME)
            ->where('email', $email)
            ->first();
    }

    private function newCode(): string
    {
        return 'BEMVINDO-'.Str::upper(Str::random(8));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $level, string $message, ?int $promoCodeId, array $extra = []): void
    {
        Log::channel('queue')->log($level, $message, [
            'user_id' => $this->userId,
            'email' => McpFormat::mask($this->email),
            'promo_code_id' => $promoCodeId,
            'job_id' => $this->job?->getJobId(),
            'attempt' => $this->job?->attempts(),
            ...$extra,
        ]);
    }
}
