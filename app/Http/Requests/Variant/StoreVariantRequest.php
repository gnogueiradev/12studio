<?php

namespace App\Http\Requests\Variant;

use App\Models\Color;
use App\Models\Material;
use App\Models\Variant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreVariantRequest extends FormRequest
{
    /**
     * Segunda camada de defesa por cima do middleware 'admin'.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * O input `type=number` envia sempre ponto, mas o admin tambem cola
     * "24,90" vindo de outro lado. Normalizar aqui deixa o `numeric` passar
     * e o Money::fromDecimal receber sempre a mesma forma.
     */
    protected function prepareForValidation(): void
    {
        foreach (['normal_price', 'sale_price', 'wholesale_price', 'packaging_cost', 'components_cost'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && $value !== '') {
                $this->merge([$field => str_replace(',', '.', $value)]);
            }
        }
    }

    /**
     * Precos chegam em euros ("12,50") e sao convertidos para centimos no
     * VariantService — nunca ha floats a atravessar a aplicacao.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:60', Rule::unique('variants', 'sku')->ignore($this->variantId())],
            'color_id' => ['nullable', 'integer', Rule::exists('colors', 'id')],
            'material_id' => ['nullable', 'integer', Rule::exists('materials', 'id')],
            'size_label' => ['nullable', 'string', 'max:60'],
            'normal_price' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'wholesale_price' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'filament_weight_grams' => ['nullable', 'integer', 'min:0', 'max:99999'],
            /*
             * Os tres campos que alimentam a calculadora de precos. Nullable e
             * nao required: torna-los obrigatorios partia todas as variantes que
             * ja existem e a criacao rapida de produto. Sem tempo de impressao a
             * variante simplesmente nao tem preco sugerido — e o painel di-lo.
             *
             * Tecto de 999h59: acima disso e engano, nao uma peca.
             */
            'printing_time_minutes' => ['nullable', 'integer', 'min:0', 'max:59999'],
            'printer_profile_id' => ['nullable', 'integer', Rule::exists('printer_profiles', 'id')],
            'packaging_cost' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'components_cost' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            // Vazio = usa a definicao global; zero = esta peca nao leva
            // trabalho nenhum. Sao coisas diferentes, e por isso e nullable.
            'active_labor_minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'stock' => ['required', 'integer', 'min:0', 'max:99999'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_default' => ['boolean'],
            'active' => ['boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $normal = (float) $this->input('normal_price');
                $sale = $this->filled('sale_price') ? (float) $this->input('sale_price') : null;

                // Uma "promocao" igual ou acima do preco normal riscaria na
                // montra um valor mais baixo do que o pedido — anuncio de
                // aumento em vez de desconto.
                if ($sale !== null && $sale >= $normal) {
                    $validator->errors()->add(
                        'sale_price',
                        'O preço promocional tem de ser inferior ao preço normal.',
                    );
                }

                // O preco de revenda existe para vender mais barato a quem
                // revende; acima do que o cliente final paga nao e revenda.
                if ($this->filled('wholesale_price') && (float) $this->input('wholesale_price') > ($sale ?? $normal)) {
                    $validator->errors()->add(
                        'wholesale_price',
                        'O preço de revenda não pode ser superior ao preço de venda.',
                    );
                }
            },
            $this->pairExists(...),
        ];
    }

    /**
     * Esta cor existe neste filamento?
     *
     * Aqui o par e unico e explicito — alguem escolheu "rosa" e escolheu
     * "silk" —, por isso e erro duro e nao filtro silencioso. E o contrario da
     * matriz do modal, onde o dono escolhe EIXOS e recebe a interseccao: la,
     * deixar cair um par e a funcionalidade; aqui era engolir a escolha.
     *
     * Uma cor sem filamentos declarados nao recusa nada. A mesma regra do
     * ColorService::syncMaterials: nao ter declarado nao e ter declarado que
     * nao existe, e enquanto o catalogo estiver por preencher ninguem pode
     * ficar sem conseguir editar as variantes que ja tem.
     */
    private function pairExists(Validator $validator): void
    {
        $colorId = $this->integerOrNull('color_id');
        $materialId = $this->integerOrNull('material_id');

        if ($colorId === null || $materialId === null) {
            return;
        }

        if ($validator->errors()->hasAny(['color_id', 'material_id'])) {
            return;
        }

        $color = Color::query()->with('materials:id,name')->find($colorId);

        if ($color === null || $color->materials->isEmpty()) {
            return;
        }

        if ($color->materials->contains('id', $materialId)) {
            return;
        }

        $material = Material::query()->find($materialId);
        $has = $color->materials->pluck('name')->implode(', ');

        $validator->errors()->add(
            'material_id',
            "Não tens {$color->name} em {$material?->name}. Só o tens em: {$has}.",
        );
    }

    private function integerOrNull(string $field): ?int
    {
        $value = $this->input($field);

        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'sku' => 'SKU',
            'color_id' => 'cor',
            'material_id' => 'material',
            'normal_price' => 'preço normal',
            'sale_price' => 'preço promocional',
            'wholesale_price' => 'preço de revenda',
            'filament_weight_grams' => 'gramagem',
            'printing_time_minutes' => 'tempo de impressão',
            'printer_profile_id' => 'impressora',
            'packaging_cost' => 'embalagem',
            'components_cost' => 'componentes',
            'active_labor_minutes' => 'trabalho ativo',
            'size_label' => 'tamanho',
            'low_stock_threshold' => 'limiar de stock baixo',
        ];
    }

    /**
     * Null no store; o id da variante em edicao no update (rota shallow).
     */
    protected function variantId(): ?int
    {
        $variant = $this->route('variant');

        return $variant instanceof Variant ? $variant->id : null;
    }
}
