<?php

namespace App\Mcp\Tools\Write;

use App\Http\Requests\Variant\UpdateVariantRequest;
use App\Mcp\FormRequestRunner;
use App\Mcp\Presenters\CatalogPresenter;
use App\Mcp\PriceGuard;
use App\Mcp\Tools\Write\Concerns\DescribesVariants;
use App\Mcp\Tools\WriteTool;
use App\Models\User;
use App\Models\Variant;
use App\Services\VariantService;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('variant_update')]
#[Title('Editar variante / preço')]
#[Description('Altera uma variante: preços (normal, promocional, revenda — em euros, IVA incluído), SKU, cor, material, tamanho, dados de produção, limite de stock baixo, se está ativa ou se é a predefinida. Manda só o que muda. O stock NÃO se muda aqui (usa stock_adjust). Mudanças de preço suspeitas — para 0 €, mais de 50% ou abaixo do custo — são recusadas até o utilizador confirmar e repetires com "confirm": true.')]
#[IsDestructive(false)]
#[IsIdempotent]
class VariantUpdateTool extends WriteTool
{
    use DescribesVariants;

    /** Campos cujo valor e dinheiro: comparam-se em centimos. */
    private const MONEY_FIELDS = ['normal_price', 'sale_price', 'wholesale_price', 'packaging_cost', 'components_cost'];

    public function __construct(
        private FormRequestRunner $runner,
        private VariantService $variants,
        private PriceGuard $guard,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'variant_id' => $schema->integer()->required()->description('Id da variante (ver product_get).'),
            ...$this->variantFieldsSchema($schema),
        ];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        $variant = Variant::query()->with(['product', 'material', 'color'])->find((int) $request->get('variant_id'));

        if ($variant === null) {
            return Response::error('Variante não encontrada.');
        }

        $sent = Arr::only($request->all(), self::VARIANT_FIELDS);

        if ($sent === []) {
            return Response::error('Não indicaste nenhum campo para mudar.');
        }

        // "" nos campos opcionais de dinheiro = tirar (null), como no formulario.
        foreach (['sale_price', 'wholesale_price', 'packaging_cost', 'components_cost'] as $field) {
            if (array_key_exists($field, $sent) && $sent[$field] === '') {
                $sent[$field] = null;
            }
        }

        $current = $this->variantAsForm($variant);

        // O pedido do backoffice quer a variante inteira (herda as regras do
        // store): o que nao veio e o que ja la esta.
        $data = $this->runner->validate(
            UpdateVariantRequest::class,
            [...$current, ...$sent],
            $user,
            ['product' => $variant->product, 'variant' => $variant],
        );

        $changed = $this->changedFields($current, $data);

        // Nada mudou = nada se grava. Nem sequer um updated_at novo.
        if ($changed === []) {
            return Response::structured([
                'message' => 'Nada mudou: os valores enviados já são os atuais.',
                'variant' => CatalogPresenter::variant($variant),
            ]);
        }

        // Desmarcar a predefinida deixava o produto sem nenhuma — e a montra sem
        // saber que preco mostrar. Muda-se escolhendo outra.
        if (in_array('is_default', $changed, true) && ! $data['is_default']) {
            return Response::error('Para mudar a variante predefinida, usa variant_set_default na variante que a deve passar a ser.');
        }

        $newEffective = $this->effectiveCents($data);

        if ($newEffective !== $variant->price_cents && ! $request->boolean('confirm')) {
            $concerns = $this->guard->concerns($variant, $newEffective, $this->costProbe($data));

            if ($concerns !== []) {
                return Response::error(PriceGuard::message($concerns));
            }
        }

        // A traducao normal/promocional -> price_cents/compare_at_cents
        // precisa dos dois: se um mudou, vao os dois.
        if (array_intersect($changed, ['normal_price', 'sale_price']) !== []) {
            $changed = array_values(array_unique([...$changed, 'normal_price', 'sale_price']));
        }

        $payload = Arr::only($data, $changed);
        $before = Arr::only($current, $changed);

        $updated = $this->variants->update($variant, $payload, $user);
        $updated->load(['color', 'material']);

        $this->changes = [
            'variant_id' => $variant->id,
            'before' => $before,
            'after' => Arr::only($this->variantAsForm($updated), $changed),
        ];

        return Response::structured([
            'message' => 'Variante atualizada.',
            'changed' => $changed,
            'variant' => CatalogPresenter::variant($updated),
        ]);
    }

    /**
     * Campos cujo valor validado difere do gravado. O dinheiro compara-se em
     * centimos ("24.9" e "24,90" sao o mesmo preco); o resto, pelo valor.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function changedFields(array $current, array $data): array
    {
        $changed = [];

        foreach (self::VARIANT_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $old = $current[$field] ?? null;
            $new = $data[$field];

            $same = in_array($field, self::MONEY_FIELDS, true)
                ? $this->cents($old) === $this->cents($new)
                : $this->scalar($old) === $this->scalar($new);

            if (! $same) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function cents(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : Money::fromDecimal((string) $value);
    }

    private function scalar(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
