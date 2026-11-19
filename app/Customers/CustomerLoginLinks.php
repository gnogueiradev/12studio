<?php

namespace App\Customers;

use App\Mail\CustomerLoginLinkMail;
use App\Models\CustomerLoginToken;
use App\Models\Order;
use App\Models\PromoCode;
use App\Models\User;
use App\Support\LocalizedUrl;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Entrar na conta de cliente com um link no email, sem password.
 *
 * O link e de uso unico e vale 20 minutos. Do segredo so se guarda o sha256:
 * quem ler a base de dados nao entra como ninguem. A equipa e as contas
 * desativadas nunca entram por aqui, e quem pede nunca fica a saber se o email
 * tem conta (quem chama responde sempre o mesmo).
 */
class CustomerLoginLinks
{
    private const TOKEN_LENGTH = 64;

    private const VALID_MINUTES = 20;

    public function __construct(
        private CustomerDirectory $directory,
    ) {}

    /**
     * Manda o link, no idioma em que foi pedido. Para a equipa, uma conta
     * desativada ou um email ambiguo nao sai nada.
     */
    public function send(string $email): void
    {
        $email = PromoCode::normalizeEmail($email);

        if ($email === null || $this->owner($email) === false) {
            return;
        }

        $token = Str::random(self::TOKEN_LENGTH);

        CustomerLoginToken::query()->create([
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(self::VALID_MINUTES),
        ]);

        // Do APP_URL e nao do pedido: com um Host forjado, o link do email
        // levava o segredo a outro dominio.
        $url = rtrim((string) config('app.url'), '/').LocalizedUrl::path('/entrar/'.$token);

        Mail::to($email)->queue(new CustomerLoginLinkMail($url));
    }

    /**
     * O email de um link que ainda serve, sem o gastar: a pagina do link so
     * mostra o botao, porque os leitores de email abrem os links sozinhos.
     */
    public function pendingEmail(string $token): ?string
    {
        $email = $this->valid($token)->value('email');

        return is_string($email) ? $email : null;
    }

    /**
     * Gasta o link e devolve o cliente, criado se ainda nao existir. Null se o
     * link ja foi usado, expirou, ou e de alguem que nao entra por aqui.
     *
     * A primeira prova do email (o `email_verified_at` passa de vazio a
     * preenchido) traz as encomendas antigas desse email que ainda nao tem
     * conta, e e o registo: dispara o `Registered` (o codigo de boas-vindas).
     */
    public function enter(string $token): ?User
    {
        [$user, $firstProof] = DB::transaction(function () use ($token): array {
            // Atomico: de dois pedidos com o mesmo link, so um muda a linha.
            $claimed = $this->valid($token)->update(['used_at' => now()]);

            if ($claimed === 0) {
                return [null, false];
            }

            $email = (string) CustomerLoginToken::query()->where('token_hash', hash('sha256', $token))->value('email');
            $user = $this->owner($email);

            if ($user === false) {
                return [null, false];
            }

            $user ??= User::query()->create([
                'name' => Str::before($email, '@'),
                'email' => $email,
                'password' => Hash::make(Str::password(32)),
            ]);

            if ($user->email_verified_at !== null) {
                return [$user, false];
            }

            $user->forceFill(['email_verified_at' => now()])->save();

            // Nunca se tira uma encomenda ja ligada a outro cliente.
            Order::query()
                ->whereNull('user_id')
                ->whereRaw('lower(email) = ?', [$email])
                ->update(['user_id' => $user->getKey()]);

            return [$user, true];
        });

        if ($user !== null && $firstProof) {
            event(new Registered($user));
        }

        return $user;
    }

    /** @return Builder<CustomerLoginToken> */
    private function valid(string $token): Builder
    {
        return CustomerLoginToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now());
    }

    /**
     * O cliente deste email, null se ainda nao ha conta, false se este email
     * nao entra por aqui: equipa, conta desativada, ou duas contas que so
     * diferem nas maiusculas (o findByEmail nao adivinha, e criar uma terceira
     * piorava).
     */
    private function owner(string $email): User|false|null
    {
        $user = $this->directory->findByEmail($email);

        if ($user === null) {
            return User::query()->whereRaw('lower(email) = ?', [$email])->exists() ? false : null;
        }

        return $user->isStaff() || $user->isDisabled() ? false : $user;
    }
}
