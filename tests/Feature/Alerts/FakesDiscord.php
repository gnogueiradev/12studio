<?php

namespace Tests\Feature\Alerts;

use App\Alerts\AlertChannel;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Liga os quatro webhooks a URLs de teste e responde 204 como o Discord.
 */
trait FakesDiscord
{
    protected function fakeDiscord(?PromiseInterface $response = null): void
    {
        foreach (AlertChannel::ALL as $channel) {
            config(["alerts.webhooks.{$channel}" => "https://discord.test/{$channel}"]);
        }

        Http::fake(['discord.test/*' => $response ?? Http::response('', 204)]);
    }

    /**
     * Titulos das mensagens enviadas para um canal, por ordem.
     *
     * @return array<int, string>
     */
    protected function alertTitles(string $channel): array
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => $pair[0]->url() === "https://discord.test/{$channel}")
            ->map(fn (array $pair): string => (string) data_get($pair[0]->data(), 'embeds.0.title'))
            ->values()
            ->all();
    }

    /**
     * O embed da primeira mensagem do canal cujo titulo contem $needle.
     *
     * @return array<string, mixed>
     */
    protected function alertEmbed(string $channel, string $needle): array
    {
        /** @var Request|null $request */
        $request = Http::recorded()
            ->map(fn (array $pair): Request => $pair[0])
            ->first(fn (Request $request): bool => $request->url() === "https://discord.test/{$channel}"
                && str_contains((string) data_get($request->data(), 'embeds.0.title'), $needle));

        $this->assertNotNull($request, "Nenhum alerta em #{$channel} com \"{$needle}\". Enviados: ".implode(' | ', $this->alertTitles($channel)));

        return (array) data_get($request->data(), 'embeds.0');
    }

    protected function assertAlerted(string $channel, string $needle): void
    {
        $this->alertEmbed($channel, $needle);
    }

    protected function assertNotAlerted(string $channel, string $needle): void
    {
        foreach ($this->alertTitles($channel) as $title) {
            $this->assertStringNotContainsString($needle, $title, "Alerta inesperado em #{$channel}: {$title}");
        }
    }

    /**
     * Texto todo do embed (titulo, descricao e campos), para procurar valores.
     *
     * @param  array<string, mixed>  $embed
     */
    protected function embedText(array $embed): string
    {
        $fields = collect((array) ($embed['fields'] ?? []))
            ->map(fn (array $field): string => $field['name'].': '.$field['value'])
            ->implode("\n");

        return trim(($embed['title'] ?? '')."\n".($embed['description'] ?? '')."\n".$fields);
    }
}
