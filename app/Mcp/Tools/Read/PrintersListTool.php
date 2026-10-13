<?php

namespace App\Mcp\Tools\Read;

use App\Mcp\McpFormat;
use App\Mcp\Tools\StudioTool;
use App\Models\User;
use App\Services\PricingSettings;
use App\Support\PrinterOptions;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('printers_list')]
#[Title('Listar impressoras')]
#[Description('Impressoras ativas e o custo por hora de cada uma (energia + depreciação + manutenção, sem IVA) — o seletor "Impressora" da calculadora. A predefinida vem primeiro e é a que se usa quando não se escolhe nenhuma. O id serve para o printer_profile_id de pricing_calculate e das variantes.')]
#[IsReadOnly]
#[IsIdempotent]
class PrintersListTool extends StudioTool
{
    public function __construct(
        private PricingSettings $settings,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, User $user): Response|ResponseFactory
    {
        // A mesma lista do seletor da pagina: as arquivadas ficam de fora
        // porque um calculo que as peca cai na predefinida de qualquer maneira.
        $printers = PrinterOptions::all($this->settings->electricityPriceMicrosPerKwh());

        return Response::structured([
            'printers' => array_map(fn (array $printer): array => [
                'id' => $printer['id'],
                'name' => $printer['name'],
                'hourly_cost' => McpFormat::micros($printer['hourlyCostMicros']),
                'is_default' => $printer['isDefault'],
            ], $printers),
            'note' => $printers === []
                ? 'Não há nenhuma impressora ativa: a calculadora usa os valores por omissão da máquina.'
                : null,
        ]);
    }
}
