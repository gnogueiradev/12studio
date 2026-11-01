<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PromoCode\SavePromoCodeRequest;
use App\Models\PromoCode;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Os codigos de desconto. Sem `destroy`: um codigo desativa-se, porque as
 * encomendas e os usos continuam a apontar para ele.
 */
class PromoCodeController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
        ];

        $promoCodes = PromoCode::query()
            ->when($filters['search'] !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('code', 'like', "%{$filters['search']}%")
                ->orWhere('email', 'like', "%{$filters['search']}%")))
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (PromoCode $promoCode): array => [
                'id' => $promoCode->id,
                'code' => $promoCode->code,
                'type' => $promoCode->type,
                'value' => $promoCode->value,
                'channel' => $promoCode->channel,
                'email' => $promoCode->email,
                'usesCount' => $promoCode->uses_count,
                'maxUses' => $promoCode->max_uses,
                'expiresAt' => $promoCode->expires_at?->format('Y-m-d H:i'),
                'isActive' => $promoCode->is_active,
            ]);

        return Inertia::render('admin/codigos/index', [
            'promoCodes' => $promoCodes,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/codigos/create', [
            'channels' => PromoCode::MANUAL_CHANNELS,
        ]);
    }

    public function store(SavePromoCodeRequest $request): RedirectResponse
    {
        PromoCode::query()->create($request->attributesForModel());

        $this->toast('Código criado.');

        return to_route('admin.codigos.index');
    }

    public function edit(PromoCode $promoCode): Response
    {
        return Inertia::render('admin/codigos/edit', [
            'channels' => $promoCode->channel === PromoCode::CHANNEL_WELCOME
                ? [PromoCode::CHANNEL_WELCOME]
                : PromoCode::MANUAL_CHANNELS,
            'promoCode' => [
                'id' => $promoCode->id,
                'code' => $promoCode->code,
                'type' => $promoCode->type,
                'value' => $promoCode->type === PromoCode::TYPE_PERCENT
                    ? (string) $promoCode->value
                    : Money::toDecimal($promoCode->value),
                'minSubtotal' => Money::toDecimal($promoCode->min_subtotal_cents),
                'startsAt' => $promoCode->starts_at?->format('Y-m-d\TH:i'),
                'expiresAt' => $promoCode->expires_at?->format('Y-m-d\TH:i'),
                'maxUses' => $promoCode->max_uses,
                'usesCount' => $promoCode->uses_count,
                'singleUsePerEmail' => $promoCode->single_use_per_email,
                'channel' => $promoCode->channel,
                'email' => $promoCode->email,
                'isActive' => $promoCode->is_active,
            ],
        ]);
    }

    public function update(SavePromoCodeRequest $request, PromoCode $promoCode): RedirectResponse
    {
        $promoCode->update($request->attributesForModel());

        $this->toast('Código atualizado.');

        return to_route('admin.codigos.index');
    }
}
