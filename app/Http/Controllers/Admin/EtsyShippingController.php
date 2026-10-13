<?php

namespace App\Http\Controllers\Admin;

use App\Etsy\EtsyException;
use App\Etsy\EtsySettings;
use App\Etsy\EtsyShipping;
use App\Etsy\EtsyShop;
use App\Etsy\EtsyTokens;
use App\Http\Controllers\Admin\Concerns\AttemptsEtsy;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminActionRequest;
use App\Http\Requests\Etsy\AssignShippingProfileRequest;
use App\Http\Requests\Etsy\SaveEtsyDefaultsRequest;
use App\Http\Requests\Etsy\SaveProcessingProfileRequest;
use App\Http\Requests\Etsy\SaveReturnPolicyRequest;
use App\Http\Requests\Etsy\SaveShippingDestinationRequest;
use App\Http\Requests\Etsy\SaveShippingProfileRequest;
use App\Http\Requests\Etsy\SaveShippingUpgradeRequest;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Etsy -> Envios: perfis de envio (com destinos e upgrades), perfis de
 * processamento, politicas de devolucao e as predefinicoes.
 *
 * Tudo o que aqui se grava vai direto a Etsy, menos as predefinicoes, que
 * sao escolhas nossas. A pagina abre sempre: com a Etsy em baixo ou sem loja
 * ligada mostra porque, em vez de rebentar.
 */
class EtsyShippingController extends Controller
{
    use AttemptsEtsy;

    public function __construct(
        private EtsyTokens $tokens,
        private EtsyShipping $shipping,
        private EtsyShop $shop,
        private EtsySettings $settings,
    ) {}

    public function index(): Response
    {
        $connected = $this->tokens->isConnected();
        $error = null;
        $profiles = $processing = $policies = [];

        if ($connected) {
            try {
                $profiles = $this->shipping->profiles();
                $processing = $this->shipping->processingProfiles();
                $policies = $this->shop->returnPolicies();
            } catch (EtsyException $exception) {
                $error = $exception->getMessage();
                $profiles = $processing = $policies = [];
            }
        }

        return Inertia::render('admin/etsy/envios', [
            'connected' => $connected,
            'error' => $error,
            'profiles' => array_map(fn (array $profile): array => [
                'id' => $profile['shipping_profile_id'],
                'title' => $profile['title'],
                'originCountry' => $profile['origin_country_iso'],
                'originPostalCode' => $profile['origin_postal_code'],
                'listings' => $profile['listings'],
                'activeListings' => $profile['active_listings'],
                'isDefault' => $profile['is_default'],
                'destinations' => array_map(fn (array $destination): array => [
                    'id' => $destination['shipping_profile_destination_id'],
                    'country' => $destination['destination_country_iso'],
                    'region' => $destination['destination_region'],
                    'primaryCostCents' => $destination['primary_cost']['cents'] ?? null,
                    'secondaryCostCents' => $destination['secondary_cost']['cents'] ?? null,
                    'currency' => $destination['primary_cost']['currency'] ?? null,
                    'minDays' => $destination['min_delivery_days'],
                    'maxDays' => $destination['max_delivery_days'],
                ], $profile['destinations']),
                'upgrades' => array_map(fn (array $upgrade): array => [
                    'id' => $upgrade['upgrade_id'],
                    'name' => $upgrade['upgrade_name'],
                    'type' => $upgrade['type'],
                    'priceCents' => $upgrade['price']['cents'] ?? null,
                    'secondaryPriceCents' => $upgrade['secondary_price']['cents'] ?? null,
                    'currency' => $upgrade['price']['currency'] ?? null,
                    'minDays' => $upgrade['min_delivery_days'],
                    'maxDays' => $upgrade['max_delivery_days'],
                ], $profile['upgrades']),
            ], $profiles),
            'processingProfiles' => array_map(fn (array $profile): array => [
                'id' => $profile['readiness_state_id'],
                'state' => $profile['readiness_state'],
                'min' => $profile['min_processing_time'],
                'max' => $profile['max_processing_time'],
                'unit' => $profile['processing_time_unit'],
                'activeListings' => $profile['active_listings'],
                'defaultFor' => $profile['is_default_for'],
            ], $processing),
            'returnPolicies' => array_map(fn (array $policy): array => [
                'id' => $policy['return_policy_id'],
                'acceptsReturns' => $policy['accepts_returns'],
                'acceptsExchanges' => $policy['accepts_exchanges'],
                'deadline' => $policy['return_deadline'],
                'listings' => $policy['listings'],
                'isDefault' => $policy['is_default'],
            ], $policies),
            'defaults' => [
                'shippingProfileId' => $this->settings->defaultShippingProfileId(),
                'readyToShipProfileId' => $this->settings->readyToShipProfileId(),
                'madeToOrderProfileId' => $this->settings->madeToOrderProfileId(),
                'returnPolicyId' => $this->settings->defaultReturnPolicyId(),
            ],
            'deadlines' => SaveReturnPolicyRequest::DEADLINES,
            // Para "aplicar este perfil a...": todos os produtos, com o
            // perfil que cada um tem hoje.
            'products' => Product::query()
                ->with('etsyListing:id,product_id,etsy_listing_id,state,shipping_profile_id')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Product $product): array => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'shippingProfileId' => $product->etsyListing?->shipping_profile_id,
                    'state' => $product->etsyListing?->etsy_listing_id === null ? null : $product->etsyListing->state,
                ])
                ->all(),
        ]);
    }

    // ── Perfis de envio ────────────────────────────────────────────────────

    public function saveProfile(SaveShippingProfileRequest $request): RedirectResponse
    {
        $editing = filled($request->validated('shipping_profile_id'));

        return $this->attempt(function () use ($request): null {
            $this->shipping->saveProfile($request->validated());

            return null;
        }, $editing ? 'Perfil de envio atualizado na Etsy.' : 'Perfil de envio criado na Etsy.');
    }

    public function deleteProfile(AdminActionRequest $request, int $profile): RedirectResponse
    {
        return $this->attempt(function () use ($profile): null {
            $this->shipping->deleteProfile($profile);

            return null;
        }, 'Perfil de envio apagado da Etsy.');
    }

    public function saveDestination(SaveShippingDestinationRequest $request, int $profile): RedirectResponse
    {
        return $this->attempt(function () use ($request, $profile): null {
            $this->shipping->saveDestination($profile, $request->validated());

            return null;
        }, 'Destino guardado na Etsy.');
    }

    public function deleteDestination(AdminActionRequest $request, int $profile, int $destination): RedirectResponse
    {
        return $this->attempt(function () use ($profile, $destination): null {
            $this->shipping->deleteDestination($profile, $destination);

            return null;
        }, 'Destino apagado da Etsy.');
    }

    public function saveUpgrade(SaveShippingUpgradeRequest $request, int $profile): RedirectResponse
    {
        return $this->attempt(function () use ($request, $profile): null {
            $this->shipping->saveUpgrade($profile, $request->validated());

            return null;
        }, 'Upgrade guardado na Etsy.');
    }

    public function deleteUpgrade(AdminActionRequest $request, int $profile, int $upgrade): RedirectResponse
    {
        return $this->attempt(function () use ($profile, $upgrade): null {
            $this->shipping->deleteUpgrade($profile, $upgrade);

            return null;
        }, 'Upgrade apagado da Etsy.');
    }

    /**
     * O anuncio que ja existe na Etsy muda la; o produto que ainda nao tem
     * anuncio fica so com o perfil escolhido. Ver EtsyShipping::assign.
     */
    public function assign(AssignShippingProfileRequest $request, int $profile): RedirectResponse
    {
        try {
            if (! $this->shipping->profileExists($profile)) {
                throw new EtsyException("O perfil de envio {$profile} não existe na loja.");
            }

            /** @var array<int, int> $productIds */
            $productIds = array_map('intval', (array) $request->validated('product_ids'));
            $result = $this->shipping->assign($productIds, $profile);
        } catch (EtsyException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return back();
        }

        $done = count($result['updated']) + count($result['prepared']);

        if ($result['failed'] !== []) {
            $first = array_key_first($result['failed']);

            $this->toast(sprintf(
                '%s%d produto(s) ficaram por mudar. "%s": %s',
                $done > 0 ? "{$done} produto(s) mudados. " : '',
                count($result['failed']),
                $first,
                $result['failed'][$first],
            ), 'error');

            return back();
        }

        $this->toast($done === 1 ? 'Perfil aplicado a 1 produto.' : "Perfil aplicado a {$done} produtos.");

        return back();
    }

    // ── Processamento ──────────────────────────────────────────────────────

    public function saveProcessing(SaveProcessingProfileRequest $request): RedirectResponse
    {
        return $this->attempt(function () use ($request): null {
            $this->shipping->saveProcessingProfile($request->validated());

            return null;
        }, 'Perfil de processamento guardado na Etsy.');
    }

    public function deleteProcessing(AdminActionRequest $request, int $definition): RedirectResponse
    {
        return $this->attempt(function () use ($definition): null {
            $this->shipping->deleteProcessingProfile($definition);

            return null;
        }, 'Perfil de processamento apagado da Etsy.');
    }

    // ── Devolucoes ─────────────────────────────────────────────────────────

    public function saveReturnPolicy(SaveReturnPolicyRequest $request): RedirectResponse
    {
        return $this->attempt(function () use ($request): null {
            $this->shop->saveReturnPolicy($request->validated());

            return null;
        }, 'Política de devolução guardada na Etsy.');
    }

    public function deleteReturnPolicy(AdminActionRequest $request, int $policy): RedirectResponse
    {
        return $this->attempt(function () use ($policy): null {
            $this->shop->deleteReturnPolicy($policy);

            return null;
        }, 'Política de devolução apagada da Etsy.');
    }

    // ── Predefinicoes ──────────────────────────────────────────────────────

    /**
     * Escolhas nossas: gravam-se ca e nao escrevem nada na Etsy.
     */
    public function saveDefaults(SaveEtsyDefaultsRequest $request): RedirectResponse
    {
        $this->settings->save($request->validated());

        $this->toast('Predefinições guardadas. Valem para os anúncios enviados daqui em diante.');

        return back();
    }
}
