<?php

namespace App\Images;

/**
 * Os enderecos de um nome. Classe propria para os testes a poderem trocar: o
 * Http::fake() nao cobre o DNS, e um teste nao deve depender da rede.
 */
class HostResolver
{
    /**
     * @return array<int, string> vazio se o nome nao resolver
     */
    public function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        $addresses = array_map(
            fn (array $record): string => (string) ($record['ip'] ?? $record['ipv6'] ?? ''),
            is_array($records) ? $records : [],
        );

        $addresses = array_values(array_filter($addresses));

        // Ha resolvers que so respondem por aqui (e so em IPv4).
        return $addresses !== [] ? $addresses : (gethostbynamel($host) ?: []);
    }
}
