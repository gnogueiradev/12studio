<?php

namespace App\Http\Requests\Product;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    /**
     * Os tres precos do molde, na lingua do VariantService: `normal_price` e
     * `sale_price` trocam de lugar em promocao, `wholesale_price` fica so no
     * backoffice.
     */
    private const PRICE_FIELDS = ['wholesale_price', 'normal_price', 'sale_price'];

    /**
     * Segunda camada de defesa por cima do middleware 'admin'.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * O input `type=number` envia sempre ponto, mas o admin tambem cola
     * "24,90" vindo de outro lado — a mesma normalizacao do
     * StoreVariantRequest, para o `numeric` passar e o Money::fromDecimal
     * receber sempre a mesma forma.
     *
     * Um unico merge no fim: `variants` e um array, e tres merges seguidos
     * sobre a mesma chave davam tres copias a competir pela ultima palavra.
     */
    protected function prepareForValidation(): void
    {
        $variants = (array) $this->input('variants');
        $touched = false;

        foreach (self::PRICE_FIELDS as $field) {
            $value = $variants[$field] ?? null;

            if (is_string($value) && $value !== '') {
                $variants[$field] = str_replace(',', '.', $value);
                $touched = true;
            }
        }

        if ($touched) {
            $this->merge(['variants' => $variants]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Vazio = gerado a partir do nome (ProductService). Quando vem
            // preenchido, a unicidade e garantida la com sufixo numerico —
            // aqui so se guarda a forma.
            'slug' => ['nullable', 'string', 'max:140', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['array', 'max:20'],
            'tags.*' => ['string', 'max:60'],
            'status' => ['required', Rule::in(Product::STATUSES)],
            'featured' => ['boolean'],
            'vat_rate' => ['required', 'integer', 'min:0', 'max:100'],
            'fulfillment_mode' => ['required', Rule::in(Product::FULFILLMENT_MODES)],
            'production_time_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'allow_backorder' => ['boolean'],
            'max_open_production_qty' => ['nullable', 'integer', 'min:1', 'max:65535'],
            ...$this->variantRules(),
            ...$this->imageRules(),
        ];
    }

    /**
     * Fotografias do modal de novo produto, com as mesmas restricoes do
     * StoreProductImageRequest — menos o `required`: nascer sem foto continua
     * a ser o caso normal, e quem quiser adiciona-as depois pela galeria.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function imageRules(): array
    {
        return [
            'images' => ['array', 'max:10'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    /**
     * Matriz de variantes do modal de novo produto: as cores, os materiais e os
     * tamanhos escolhidos, mais o molde (os tres precos) aplicado a todas as
     * combinacoes. Ausente = produto sem variantes, que e o que um rascunho e.
     *
     * A gramagem e o tempo de impressao NAO estao aqui: sao dados de producao e
     * editam-se variante a variante, na ficha onde o painel de custo os usa.
     *
     * O `required_with` nos dois precos obrigatorios e o que impede uma matriz
     * sem preco: as variantes nasceriam todas a zero euros e vendaveis. Olha
     * para os dois eixos obrigatorios — basta um deles vir preenchido para os
     * precos passarem a ser exigidos. Um array vazio nao conta como preenchido,
     * por isso um rascunho sem matriz atravessa isto sem preco nenhum.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function variantRules(): array
    {
        $required = 'required_with:variants.color_ids,variants.material_ids';
        $money = ['nullable', 'numeric', 'min:0', 'max:99999.99'];

        return [
            'variants' => ['nullable', 'array'],
            'variants.color_ids' => ['array', 'max:60'],
            'variants.color_ids.*' => ['integer', Rule::exists('colors', 'id')],
            'variants.material_ids' => ['array', 'max:10'],
            'variants.material_ids.*' => ['integer', Rule::exists('materials', 'id')],
            'variants.sizes' => ['array', 'max:10'],
            'variants.sizes.*' => ['string', 'max:60'],
            'variants.normal_price' => [$required, ...$money],
            // Obrigatorio ao contrario da ficha da variante: um produto que
            // nasce sem preco de revenda chega as encomendas manuais sem nada
            // que se lhe aplique, e ninguem volta atras para o preencher.
            'variants.wholesale_price' => [$required, ...$money],
            'variants.sale_price' => $money,
        ];
    }

    /**
     * As mesmas duas regras cruzadas do StoreVariantRequest, com as mesmas
     * mensagens: o molde nao pode gerar variantes que a ficha delas recusaria.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $normal = $this->price('normal_price');

                if ($normal === null) {
                    return;
                }

                $sale = $this->price('sale_price');

                // Uma "promocao" igual ou acima do preco normal riscaria na
                // montra um valor mais baixo do que o pedido — anuncio de
                // aumento em vez de desconto.
                if ($sale !== null && $sale >= $normal) {
                    $validator->errors()->add(
                        'variants.sale_price',
                        'O preço promocional tem de ser inferior ao preço normal.',
                    );
                }

                $wholesale = $this->price('wholesale_price');

                // O preco de revenda existe para vender mais barato a quem
                // revende; acima do que o cliente final paga nao e revenda.
                if ($wholesale !== null && $wholesale > ($sale ?? $normal)) {
                    $validator->errors()->add(
                        'variants.wholesale_price',
                        'O preço de revenda não pode ser superior ao preço de venda.',
                    );
                }
            },
        ];
    }

    /**
     * Um preco do molde em euros, ou null quando nao veio — a comparacao so
     * faz sentido entre valores que existem.
     */
    private function price(string $field): ?float
    {
        $value = $this->input("variants.{$field}");

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'O endereço só pode ter minúsculas, números e hífens (ex.: vaso-ondulado).',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'slug' => 'endereço',
            'category_id' => 'categoria',
            'vat_rate' => 'IVA',
            'tags' => 'etiquetas',
            'images' => 'fotografias',
            'images.*' => 'fotografia',
            'variants.color_ids' => 'cores',
            'variants.material_ids' => 'materiais',
            'variants.sizes' => 'tamanhos',
            'variants.normal_price' => 'preço de venda',
            'variants.wholesale_price' => 'preço de revenda',
            'variants.sale_price' => 'preço em promoção',
        ];
    }
}
