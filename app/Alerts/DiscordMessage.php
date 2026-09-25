<?php

namespace App\Alerts;

use Illuminate\Support\Str;

/**
 * Uma mensagem para o Discord: um embed com titulo, cor, campos e link.
 *
 * Os limites do Discord (titulo 256, descricao 4096, campo 1024, 25 campos)
 * aplicam-se aqui: uma mensagem que os passe e recusada inteira com 400, e o
 * alerta perdia-se por causa de uma morada comprida.
 */
final class DiscordMessage
{
    public const INFO = 'info';

    public const SUCCESS = 'success';

    public const WARNING = 'warning';

    public const DANGER = 'danger';

    private const COLORS = [
        self::INFO => 0x3B82F6,
        self::SUCCESS => 0x22C55E,
        self::WARNING => 0xF59E0B,
        self::DANGER => 0xEF4444,
    ];

    /** @var array<int, array{name: string, value: string, inline: bool}> */
    private array $fields = [];

    private ?string $description = null;

    private ?string $url = null;

    public function __construct(
        private string $title,
        private string $level = self::INFO,
    ) {}

    public static function make(string $title, string $level = self::INFO): self
    {
        return new self($title, $level);
    }

    public function description(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Campo vazio ou nulo nao entra: "Telefone: —" em cada mensagem e ruido.
     */
    public function field(string $name, string|int|null $value, bool $inline = true): self
    {
        if ($value === null || trim((string) $value) === '') {
            return $this;
        }

        $this->fields[] = ['name' => $name, 'value' => (string) $value, 'inline' => $inline];

        return $this;
    }

    public function url(?string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function title(): string
    {
        return $this->title;
    }

    /**
     * @return array<int, array{name: string, value: string, inline: bool}>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * O corpo do POST ao webhook.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $embed = [
            'title' => Str::limit($this->title, 250),
            'color' => self::COLORS[$this->level] ?? self::COLORS[self::INFO],
            'timestamp' => now()->toIso8601String(),
        ];

        if ($this->description !== null && $this->description !== '') {
            $embed['description'] = Str::limit($this->description, 4000);
        }

        if ($this->url !== null) {
            $embed['url'] = $this->url;
        }

        if ($this->fields !== []) {
            $embed['fields'] = array_map(fn (array $field): array => [
                'name' => Str::limit($field['name'], 250),
                'value' => Str::limit($field['value'], 1000),
                'inline' => $field['inline'],
            ], array_slice($this->fields, 0, 25));
        }

        // Ninguem e mencionado sem querer: um nome de cliente com "@everyone"
        // nao pode acordar o servidor inteiro.
        return ['embeds' => [$embed], 'allowed_mentions' => ['parse' => []]];
    }
}
