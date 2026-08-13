<?php

namespace Tests\Feature\Admin;

use App\Models\PrinterProfile;
use App\Models\User;
use App\Models\Variant;
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
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'name' => 'Bambu Lab P1S',
            'hourly_rate' => '0.65',
            'notes' => 'Inclui energia, desgaste e depreciacao.',
            'active' => true,
            'sort_order' => 0,
        ];
    }

    public function test_store_converts_euros_per_hour_to_cents(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.impressoras.store'), [
                ...$this->validPayload(),
                'hourly_rate' => '0,65',
            ])
            ->assertRedirect(route('admin.impressoras.index'));

        $this->assertDatabaseHas('printer_profiles', [
            'name' => 'Bambu Lab P1S',
            'hourly_rate_cents' => 65,
        ]);
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

    public function test_the_hourly_rate_is_required(): void
    {
        $payload = $this->validPayload();
        unset($payload['hourly_rate']);

        $this->actingAs($this->admin)
            ->post(route('admin.impressoras.store'), $payload)
            ->assertSessionHasErrors('hourly_rate');
    }

    public function test_update_changes_the_rate(): void
    {
        $profile = PrinterProfile::factory()->create(['hourly_rate_cents' => 50]);

        $this->actingAs($this->admin)
            ->put(route('admin.impressoras.update', $profile), [
                ...$this->validPayload(),
                'name' => $profile->name,
                'hourly_rate' => '0,75',
            ])
            ->assertRedirect(route('admin.impressoras.index'));

        $this->assertSame(75, $profile->refresh()->hourly_rate_cents);
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
