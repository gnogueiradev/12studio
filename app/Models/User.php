<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Concerns\HasTags;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_admin
 * @property bool $is_owner
 * @property string|null $staff_role
 * @property bool $must_change_password
 * @property CarbonImmutable|null $disabled_at
 * @property int|null $created_by_user_id
 * @property string $customer_type
 * @property string|null $phone
 * @property string|null $nif
 * @property string|null $admin_note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'customer_type', 'phone', 'nif', 'admin_note'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTags, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_PRODUCTION = 'production';

    /**
     * Papeis que o dono pode dar a uma conta de equipa. O `owner` nao esta
     * aqui de proposito: so o comando `users:make-owner` o atribui.
     */
    public const STAFF_ROLES = [self::ROLE_ADMIN, self::ROLE_PRODUCTION];

    /**
     * Quem tem etiquetas e o cliente, nao a conta. Um admin herda a relacao por
     * ser o mesmo modelo, mas nada no backoffice lha oferece.
     */
    public function tagScope(): string
    {
        return Tag::SCOPE_CUSTOMER;
    }

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
            'is_owner' => 'boolean',
            'must_change_password' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Cliente = nem admin nem equipa. E o UNICO sitio que o define: antes era
     * `where('is_admin', false)` espalhado, e uma conta de producao (que nao e
     * admin) aparecia na lista de clientes e no seletor das encomendas.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('is_admin', false)->whereNull('staff_role');
    }

    /**
     * Equipa = quem entra no backoffice, admin ou producao.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeStaff(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner
            ->where('is_admin', true)
            ->orWhereNotNull('staff_role'));
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Quem criou a conta (so contas da equipa criadas pelo dono).
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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
        return (bool) $this->is_admin && ! $this->isDisabled();
    }

    /**
     * O dono gere a equipa. So conta se tambem for admin: um dono despromovido
     * por engano na BD nao fica com a pagina de utilizadores.
     */
    public function isOwner(): bool
    {
        return (bool) $this->is_owner && $this->isAdmin();
    }

    public function isProductionStaff(): bool
    {
        return $this->staff_role === self::ROLE_PRODUCTION && ! $this->isDisabled();
    }

    /**
     * Quadro de producao: admins e a equipa de producao.
     */
    public function canAccessProduction(): bool
    {
        return $this->isAdmin() || $this->isProductionStaff();
    }

    public function isStaff(): bool
    {
        return (bool) $this->is_admin || $this->staff_role !== null;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Papel legivel, para a pagina de utilizadores.
     */
    public function role(): string
    {
        return match (true) {
            (bool) $this->is_owner && (bool) $this->is_admin => 'owner',
            (bool) $this->is_admin => self::ROLE_ADMIN,
            $this->staff_role === self::ROLE_PRODUCTION => self::ROLE_PRODUCTION,
            default => 'customer',
        };
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
