<?php

namespace Tests\Feature\Admin;

use App\Models\PrinterProfile;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrinterProfileCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * Exactamente o que o modal manda — sem `is_default` nem `active`. Um
     * payload de teste mais rico do que o real e um payload que deixa passar a
     * regressao que interessa.
     *
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Bambu Lab P1S',
            'average_power_watts' => 180,
            'purchase_price' => '700.00',
            'lifetime_hours' => 5_000,
            'maintenance_rate' => '0.05',
            'notes' => 'Consumo medido com wattimetro.',
            'sort_order' => 0,
        ];
    }

    /**
     * Os dois campos decimais viram inteiros — e em unidades diferentes. O
     * preco de compra em centimos, a manutencao em MICROS: 0,0450 EUR/h
     * arredondado ao centimo era 5, e 0,0350 EUR/h era 4.
     */
    public function test_store_converts_the_decimal_fields_to_integers(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.impressoras.store'), [
                ...$this->validPayload(),
                'purchase_price' => '700,00',
                'maintenance_rate' => '0,0450',
            ])
            ->assertRedirect(route('admin.impressoras.index'));

        $this->assertDatabaseHas('printer_profiles', [
            'name' => 'Bambu Lab P1S',
            'average_power_watts' => 180,
            'purchase_price_cents' => 70_000,
            'lifetime_hours' => 5_000,
            'maintenance_micros_per_hour' => 45_000,
        ]);
    }

    /**
     * O custo/hora deixou de ser escrito e passou a ser derivado dos quatro
     * numeros: 180 W a 0,1420 EUR/kWh + 700 EUR / 5000 h + 0,05 EUR/h.
     */
    public function test_the_hourly_cost_is_derived_from_the_four_columns(): void
    {
        $this->actingAs($this->admin)->post(route('admin.impressoras.store'), $this->validPayload());

        $profile = PrinterProfile::query()->where('name', 'Bambu Lab P1S')->firstOrFail();

        // 0,02556 de luz + 0,14 de amortizacao + 0,05 de manutencao.
        $this->assertSame(215_560, $profile->hourlyCostMicros(142_000));

        // E acompanha a tarifa: e por isso que ela e global e nao uma coluna.
        $this->assertSame(215_560 + 25_560, $profile->hourlyCostMicros(284_000));
    }

    /**
     * O indice unico PARCIAL `printer_profiles_one_default` foi criado por DDL
     * cru e e invisivel ao Blueprint. A migracao que largou o `hourly_rate_cents`
     * podia te-lo levado com ela: em SQLite antigo o `dropColumn` reconstroi a
     * tabela, e a reconstrucao nao sabe de indices que o Laravel nao criou.
     *
     * Perde-lo nao dava erro nenhum — dava duas impressoras predefinidas ao
     * mesmo tempo e a calculadora a escolher a errada. Este teste e a unica
     * coisa que apanha isso.
     */
    public function test_the_one_default_printer_index_survives_the_migrations(): void
    {
        PrinterProfile::factory()->isDefault()->create(['name' => 'A1']);

        $this->expectException(QueryException::class);

        PrinterProfile::factory()->isDefault()->create(['name' => 'P1S']);
    }

    /** Sem vida util nao ha amortizacao — e nao ha divisao por zero. */
    public function test_the_hourly_cost_survives_a_printer_with_no_lifetime(): void
    {
        $profile = PrinterProfile::factory()->create([
            'average_power_watts' => 180,
            'purchase_price_cents' => 70_000,
            'lifetime_hours' => 0,
            'maintenance_micros_per_hour' => 50_000,
        ]);

        $this->assertSame(75_560, $profile->hourlyCostMicros(142_000));
    }

    /**
     * Uma loja com uma impressora so nao pode ficar sem predefinida: a
     * calculadora caia no custo/hora do config e mostrava um aviso logo a
     * seguir a o dono ter criado a unica maquina que tem.
     */
    public function test_the_first_profile_becomes_the_default(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.impressoras.store'), $this->validPayload());

        $this->assertDatabaseHas('printer_profiles', [
            'name' => 'Bambu Lab P1S',
            'is_default' => true,
        ]);
    }

    /**
     * Ha um indice unico PARCIAL na tabela a garantir que so existe uma
     * predefinida. Marcar a nova sem limpar a antiga rebentava com um erro de
     * base de dados em vez de trocar a impressora.
     */
    public function test_setting_a_default_unsets_the_previous_one(): void
    {
        $old = PrinterProfile::factory()->isDefault()->create(['name' => 'A1']);
        $new = PrinterProfile::factory()->create(['name' => 'P1S']);

        $this->actingAs($this->admin)
            ->patch(route('admin.impressoras.predefinida', $new))
            ->assertRedirect();

        $this->assertFalse($old->refresh()->is_default);
        $this->assertTrue($new->refresh()->is_default);
        $this->assertSame(1, PrinterProfile::query()->where('is_default', true)->count());
    }

    /**
     * Regra global: arquivar, nunca apagar. O historial de custo de uma peca
     * feita nesta maquina tem de continuar a fazer sentido.
     */
    public function test_destroy_archives_instead_of_deleting(): void
    {
        $profile = PrinterProfile::factory()->create(['name' => 'A1']);

        $this->actingAs($this->admin)
            ->delete(route('admin.impressoras.destroy', $profile))
            ->assertRedirect(route('admin.impressoras.index'));

        $this->assertDatabaseHas('printer_profiles', ['id' => $profile->id, 'active' => false]);
    }

    /**
     * Uma impressora arquivada a servir de predefinida deixava a calculadora a
     * usar o custo/hora de uma maquina que ja nao esta na oficina. A
     * predefinicao passa para a primeira ativa que sobre.
     */
    public function test_an_archived_profile_cannot_stay_the_default(): void
    {
        $default = PrinterProfile::factory()->isDefault()->create(['name' => 'A1']);
        $other = PrinterProfile::factory()->create(['name' => 'P1S']);

        $this->actingAs($this->admin)
            ->delete(route('admin.impressoras.destroy', $default));

        $this->assertFalse($default->refresh()->is_default);
        $this->assertTrue($other->refresh()->is_default);
    }

    /**
     * Sem impressoras ativas nao ha predefinida nenhuma — e esta certo. A
     * calculadora avisa e cai no config, que e o comportamento correto para uma
     * loja que ainda nao configurou maquina nenhuma.
     */
    public function test_archiving_the_last_profile_leaves_no_default(): void
    {
        $only = PrinterProfile::factory()->isDefault()->create(['name' => 'A1']);

        $this->actingAs($this->admin)
            ->delete(route('admin.impressoras.destroy', $only));

        $this->assertSame(0, PrinterProfile::query()->where('is_default', true)->count());
    }

    /**
     * A FK `variants.printer_profile_id` e restrictOnDelete de proposito: a
     * unica saida e arquivar, e as variantes que ja a usam ficam intactas.
     */
    public function test_a_profile_used_by_a_variant_survives_archiving(): void
    {
        $profile = PrinterProfile::factory()->create(['name' => 'A1']);
        $variant = Variant::factory()->create(['printer_profile_id' => $profile->id]);

        $this->actingAs($this->admin)
            ->delete(route('admin.impressoras.destroy', $profile));

        $this->assertDatabaseHas('printer_profiles', ['id' => $profile->id]);
        $this->assertSame($profile->id, $variant->refresh()->printer_profile_id);
    }

    public function test_duplicate_names_are_rejected(): void
    {
        PrinterProfile::factory()->create(['name' => 'Bambu Lab P1S']);

        $this->actingAs($this->admin)
            ->post(route('admin.impressoras.store'), $this->validPayload())
            ->assertSessionHasErrors('name');
    }

    public function test_the_four_cost_fields_are_required(): void
    {
        foreach (['average_power_watts', 'purchase_price', 'lifetime_hours', 'maintenance_rate'] as $field) {
            $payload = $this->validPayload();
            unset($payload[$field]);

            $this->actingAs($this->admin)
                ->post(route('admin.impressoras.store'), $payload)
                ->assertSessionHasErrors($field);
        }
    }

    /**
     * A vida util e um DIVISOR: zero horas era uma divisao por zero. O
     * calculador tem uma guarda para ela, mas o formulario nao tem de a deixar
     * chegar la.
     */
    public function test_a_lifetime_of_zero_hours_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.impressoras.store'), [
                ...$this->validPayload(),
                'lifetime_hours' => 0,
            ])
            ->assertSessionHasErrors('lifetime_hours');
    }

    public function test_update_changes_the_cost_model(): void
    {
        $profile = PrinterProfile::factory()->create(['average_power_watts' => 100]);

        $this->actingAs($this->admin)
            ->put(route('admin.impressoras.update', $profile), [
                ...$this->validPayload(),
                'name' => $profile->name,
                'average_power_watts' => 220,
                'maintenance_rate' => '0,075',
            ])
            ->assertRedirect(route('admin.impressoras.index'));

        $profile->refresh();

        $this->assertSame(220, $profile->average_power_watts);
        $this->assertSame(75_000, $profile->maintenance_micros_per_hour);
    }

    /**
     * O modal nao manda `is_default` nem `active`: predefinir e arquivar sao um
     * clique na propria linha da listagem. Guardar uma alteracao ao nome ou ao
     * custo/hora nao pode despromover a predefinida nem desarquivar seja o que
     * for — e este o teste que apanha um `$data['is_default'] ?? false` posto no
     * servico a fingir que a ausencia da chave e um "nao".
     */
    public function test_update_leaves_the_default_and_active_flags_alone(): void
    {
        $profile = PrinterProfile::factory()->isDefault()->create(['name' => 'A1']);

        $this->actingAs($this->admin)
            ->patch(route('admin.impressoras.update', $profile), [
                ...$this->validPayload(),
                'name' => 'A1 Combo',
                'notes' => 'Com AMS.',
                'sort_order' => 3,
            ])
            ->assertRedirect(route('admin.impressoras.index'));

        $profile->refresh();

        $this->assertSame('A1 Combo', $profile->name);
        $this->assertSame(180, $profile->average_power_watts);
        $this->assertSame(3, $profile->sort_order);
        $this->assertTrue($profile->is_default);
        $this->assertTrue($profile->active);
    }

    /**
     * O mesmo pelo outro lado: uma maquina arquivada nao volta a activo so
     * porque alguem lhe corrigiu o consumo.
     */
    public function test_update_does_not_unarchive_a_profile(): void
    {
        $profile = PrinterProfile::factory()->archived()->create(['name' => 'A1']);

        $this->actingAs($this->admin)
            ->patch(route('admin.impressoras.update', $profile), [
                ...$this->validPayload(),
                'name' => 'A1',
                'notes' => null,
                'sort_order' => 0,
            ]);

        $this->assertFalse($profile->refresh()->active);
    }

    public function test_restore_brings_a_profile_back(): void
    {
        $profile = PrinterProfile::factory()->archived()->create();

        $this->actingAs($this->admin)
            ->patch(route('admin.impressoras.restaurar', $profile))
            ->assertRedirect();

        $this->assertTrue($profile->refresh()->active);
    }

    public function test_non_admins_cannot_touch_printer_profiles(): void
    {
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->get(route('admin.impressoras.index'))
            ->assertForbidden();

        $this->actingAs($customer)
            ->post(route('admin.impressoras.store'), $this->validPayload())
            ->assertForbidden();
    }
}
