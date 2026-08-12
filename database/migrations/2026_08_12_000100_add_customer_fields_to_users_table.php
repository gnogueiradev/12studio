<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O backoffice passa a criar clientes so com o nome. Duas consequencias:
     *
     * 1. `email` deixa de ser obrigatorio. Continua unico — um indice unico
     *    aceita varios NULL em SQLite, por isso nada muda para quem tem email.
     * 2. `phone` e `nif` deixam de viver em `addresses`. Sao atributos da
     *    pessoa, nao da morada de envio; com a morada opcional nao havia onde
     *    guardar o NIF de um cliente sem morada (line1/postal_code/city sao
     *    NOT NULL nessa tabela).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Particular ou Empresa. Muda o label do nome no formulario e
            // torna o NIF obrigatorio — nao ha faturacao a empresa sem ele.
            $table->string('customer_type', 20)->default('particular')->after('is_admin');
            $table->string('phone', 30)->nullable()->after('customer_type');
            $table->string('nif', 20)->nullable()->after('phone');
            // Notas do admin sobre o cliente. Nunca sai para o cliente.
            $table->text('admin_note')->nullable()->after('nif');
        });

        DB::transaction(function (): void {
            // Uma subconsulta correlacionada em vez de um ciclo em PHP: uma ida
            // a base de dados, e sem meio caminho onde metade dos clientes ja
            // migrou e a outra metade nao.
            DB::table('users')
                ->where('is_admin', false)
                ->update([
                    'phone' => DB::raw('(select a.phone from addresses a where a.user_id = users.id order by a.is_default desc, a.id limit 1)'),
                    'nif' => DB::raw('(select a.nif from addresses a where a.user_id = users.id order by a.is_default desc, a.id limit 1)'),
                ]);

            // NIF portugues comecado por 5: pessoa coletiva. E a unica pista
            // que os dados existentes dao sobre o tipo de cliente.
            DB::table('users')
                ->where('is_admin', false)
                ->where('nif', 'like', '5%')
                ->update(['customer_type' => 'empresa']);
        });

        // Depois do backfill: manter as colunas antigas era ficar com o mesmo
        // NIF em dois sitios, a divergir em silencio assim que alguem editasse
        // um deles. As encomendas nunca leram daqui — guardam sempre um
        // snapshot json proprio.
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn(['phone', 'nif']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
        });

        $this->ensureEmailStaysUnique();
    }

    public function down(): void
    {
        // Repor NOT NULL exige que nao haja nulos. O placeholder e feio de
        // proposito: um email destes na base de dados e um cliente que foi
        // criado sem email, nao um endereco a que valha a pena escrever.
        DB::table('users')
            ->whereNull('email')
            ->update(['email' => DB::raw("'cliente-' || id || '@sem-email.local'")]);

        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('phone', 30)->nullable();
            $table->string('nif', 20)->nullable();
        });

        DB::table('addresses')->update([
            'phone' => DB::raw('(select u.phone from users u where u.id = addresses.user_id)'),
            'nif' => DB::raw('(select u.nif from users u where u.id = addresses.user_id)'),
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
            $table->dropColumn(['customer_type', 'phone', 'nif', 'admin_note']);
        });

        $this->ensureEmailStaysUnique();
    }

    /**
     * O `change()` em SQLite nao altera a coluna: recria a tabela e volta a
     * criar os indices. O Laravel trata disso, mas o unico do email e a peca
     * que nao pode falhar em silencio — dois clientes com o mesmo email partem
     * o login da Fase 5. Verificar custa uma consulta ao schema.
     */
    private function ensureEmailStaysUnique(): void
    {
        $hasUnique = collect(Schema::getIndexes('users'))
            ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['email']);

        if (! $hasUnique) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('email');
            });
        }
    }
};
