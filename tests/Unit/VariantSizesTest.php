<?php

namespace Tests\Unit;

use App\Support\VariantSizes;
use PHPUnit\Framework\TestCase;

/**
 * O tamanho e texto livre na variante — "20cm", " 20CM ", "20cm " escritos em
 * alturas diferentes sao o mesmo tamanho para quem os escreveu. A chave e o
 * que os junta, e e a mesma em todos os sitios que agrupam por tamanho.
 */
class VariantSizesTest extends TestCase
{
    public function test_ignora_espacos_nas_pontas_e_maiusculas(): void
    {
        $this->assertSame('20cm', VariantSizes::key(' 20CM '));
        $this->assertSame(VariantSizes::key('20cm'), VariantSizes::key("20Cm\t"));
    }

    public function test_sem_tamanho_e_vazio_e_so_espacos_sao_o_mesmo(): void
    {
        $this->assertSame('', VariantSizes::key(null));
        $this->assertSame('', VariantSizes::key(''));
        $this->assertSame('', VariantSizes::key('   '));
    }

    /**
     * Os espacos DENTRO contam: "20 cm" e "20cm" ficam dois tamanhos. Juntar
     * isso era adivinhar, e o dono ve os dois grupos e corrige.
     */
    public function test_espacos_no_meio_contam(): void
    {
        $this->assertNotSame(VariantSizes::key('20cm'), VariantSizes::key('20 cm'));
    }

    public function test_acentos_descem_a_minusculas(): void
    {
        $this->assertSame('médio', VariantSizes::key('MÉDIO'));
    }
}
