<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\OwnerFormRequest;
use App\Http\Requests\Staff\ResetStaffPasswordRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Models\User;
use App\Services\StaffService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A equipa: contas de admin e de producao, geridas so pelo dono. Os clientes
 * vivem em /admin/clientes e nunca aparecem aqui (nem o contrario).
 */
class StaffController extends Controller
{
    public function __construct(
        private StaffService $staffService,
    ) {}

    public function index(OwnerFormRequest $request): Response
    {
        $staff = User::query()
            ->staff()
            ->with('createdBy:id,name')
            ->orderByDesc('is_owner')
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->get();

        return Inertia::render('admin/utilizadores/index', [
            'staff' => $staff->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role(),
                'disabled' => $user->isDisabled(),
                'mustChangePassword' => $user->must_change_password,
                'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
                'createdBy' => $user->createdBy?->name,
                'createdAt' => $user->created_at?->format('Y-m-d'),
                'isSelf' => $user->is($request->user()),
            ]),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        /** @var array{name: string, email: string, role: string, password: string} $data */
        $data = $request->validated();

        $staff = $this->staffService->create($data, $this->owner($request));

        $this->toast("Conta de {$staff->name} criada. Na primeira entrada vai ter de mudar a password.");

        return to_route('admin.utilizadores.index');
    }

    public function update(UpdateStaffRequest $request, User $staff): RedirectResponse
    {
        $this->ensureIsStaff($staff);

        /** @var array{name: string, email: string, role: string} $data */
        $data = $request->validated();

        $this->staffService->update($staff, $data, $this->owner($request));

        $this->toast('Conta atualizada.');

        return to_route('admin.utilizadores.index');
    }

    public function resetPassword(ResetStaffPasswordRequest $request, User $staff): RedirectResponse
    {
        $this->ensureIsStaff($staff);

        $this->staffService->resetPassword($staff, $request->string('password')->value(), $this->owner($request));

        $this->toast("Password de {$staff->name} redefinida. Vai ter de a mudar na próxima entrada.");

        return to_route('admin.utilizadores.index');
    }

    public function disable(OwnerFormRequest $request, User $staff): RedirectResponse
    {
        $this->ensureIsStaff($staff);

        $this->staffService->disable($staff, $this->owner($request));

        $this->toast("Conta de {$staff->name} desativada.");

        return back();
    }

    public function enable(OwnerFormRequest $request, User $staff): RedirectResponse
    {
        $this->ensureIsStaff($staff);

        $this->staffService->enable($staff, $this->owner($request));

        $this->toast("Conta de {$staff->name} reativada.");

        return back();
    }

    /**
     * A rota resolve qualquer User; clientes nao se gerem por aqui.
     */
    private function ensureIsStaff(User $staff): void
    {
        abort_unless($staff->isStaff(), 404);
    }

    private function owner(OwnerFormRequest $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
