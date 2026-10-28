<?php

namespace App\Services;

/**
 * A identidade da loja: o nome que aparece no cabecalho, nos titulos das
 * paginas, nos emails e no servidor MCP, e a frase curta do rodape.
 *
 * config/shop.php por omissao, com a tabela `settings` por cima assim que o
 * dono lhes mexer — o mesmo esquema do PricingSettings. Vive na BD, e nao no
 * .env, porque a mesma instalacao vai servir mais do que uma loja: o nome tem
 * de vir de onde vem o resto dos dados de cada uma.
 */
class BrandSettings
{
    public const KEY_NAME = 'brand.name';

    public const KEY_TAGLINE = 'brand.tagline';

    public function __construct(
        private SettingService $settings,
    ) {}

    public function name(): string
    {
        return $this->string(self::KEY_NAME, (string) config('shop.name', '12studio'));
    }

    /** A frase do rodape da montra, a seguir ao nome. Pode ficar vazia. */
    public function tagline(): string
    {
        return $this->string(self::KEY_TAGLINE, (string) config('shop.tagline', ''), allowEmpty: true);
    }

    /**
     * O nome em forma de identificador: o `claude mcp add <nome>` da pagina das
     * chaves de API nao aceita espacos nem acentos.
     */
    public function slug(): string
    {
        $slug = str($this->name())->slug()->toString();

        return $slug !== '' ? $slug : 'loja';
    }

    /**
     * @return array{name: string, tagline: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'tagline' => $this->tagline(),
        ];
    }

    /**
     * O payload ja validado do UpdateBrandSettingsRequest -> o mapa
     * chave => valor pronto para o SettingService::setMany().
     *
     * @param  array<string, mixed>  $form
     * @return array<string, string>
     */
    public function fromForm(array $form): array
    {
        return [
            self::KEY_NAME => trim((string) $form['name']),
            self::KEY_TAGLINE => trim((string) ($form['tagline'] ?? '')),
        ];
    }

    private function string(string $key, string $default, bool $allowEmpty = false): string
    {
        $value = $this->settings->get($key);

        if (! is_string($value)) {
            return $default;
        }

        // Um nome vazio deixava o cabecalho e o <title> em branco: volta ao do
        // config. A frase do rodape pode, essa sim, ficar vazia de proposito.
        return $value === '' && ! $allowEmpty ? $default : $value;
    }
}
