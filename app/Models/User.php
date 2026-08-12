<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_admin
 * @property string $customer_type
 * @property string|null $phone
 * @property string|null $nif
 * @property string|null $admin_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'customer_type', 'phone', 'nif', 'admin_note'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * is_admin fica FORA do #[Fillable] de proposito: promover alguem a admin
     * nunca pode acontecer por mass-assignment de um request — so o seeder ou
     * atribuicao direta e deliberada.
     */
    public function isAdmin(): bool
    {
        // Cast explicito: um modelo acabado de criar pela factory pode nem
        // ter o atributo carregado (default aplicado so na BD) — null nunca
        // pode passar por "e admin".
        return (bool) $this->is_admin;
    }

    /**
     * Cliente empresa. O default da coluna e 'particular', mas um modelo
     * acabado de criar pela factory pode nao ter o atributo carregado — por
     * isso a pergunta e "e empresa?" e nunca "nao e particular?".
     */
    public function isCompany(): bool
    {
        return $this->customer_type === 'empresa';
    }
}
