<?php

namespace App\Alerts;

/**
 * As etiquetas que o backoffice mostra, para as mensagens do Discord dizerem
 * o mesmo que o ecra. Copia de resources/js/types/order.ts — mudar la e mudar
 * aqui.
 */
final class Labels
{
    public const ORDER_STATUSES = [
        'pending_payment' => 'A aguardar pagamento',
        'paid' => 'Pagamento confirmado',
        'in_production' => 'Em produção',
        'ready_to_ship' => 'Pronto a enviar',
        'shipped' => 'Enviado',
        'delivered' => 'Entregue',
        'cancelled' => 'Cancelado',
        'refunded' => 'Reembolsado',
    ];

    public const PAYMENT_STATUSES = [
        'pending' => 'Pendente',
        'paid' => 'Pago',
        'partially_refunded' => 'Parcialmente reembolsado',
        'refunded' => 'Reembolsado',
        'failed' => 'Falhou',
    ];

    public const PAYMENT_METHODS = [
        'card' => 'Cartão',
        'multibanco' => 'Multibanco',
        'mbway' => 'MB WAY',
        'cash' => 'Dinheiro',
        'bank_transfer' => 'Transferência',
        'vinted' => 'Vinted',
        'other' => 'Outro',
    ];

    public const SALES_CHANNELS = [
        'website' => 'Loja online',
        'vinted' => 'Vinted',
        'instagram' => 'Instagram',
        'manual' => 'Manual',
    ];

    public const PRODUCTION_STATUSES = [
        'not_required' => 'Sem produção',
        'awaiting_production' => 'Por produzir',
        'printing' => 'A imprimir',
        'quality_check' => 'Controlo de qualidade',
        'ready' => 'Pronto',
    ];

    /**
     * @param  array<string, string>  $labels
     */
    public static function of(array $labels, ?string $value): ?string
    {
        return $value === null ? null : ($labels[$value] ?? $value);
    }
}
