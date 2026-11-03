<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As conversas com clientes da montra (tipo ticket, sem conta).
 *
 * - conversations: quem escreveu, sobre o que (contacto, interesse num
 *   produto, peca personalizada) e o estado.
 * - conversation_tokens: os links privados. So o hash: o token em claro so
 *   existe no email que o leva. Cada email leva um token novo (os anteriores
 *   continuam a valer) — e assim que um email de resposta tem um link que
 *   funciona sem o token ficar guardado.
 * - conversation_messages: o fio, com as notas internas da loja.
 * - conversation_attachments: ficheiros no disco privado (local).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->string('status')->default('open');
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('locale', 5);
            $table->string('subject');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->json('personalization')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('order_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('admin_read_at')->nullable();
            $table->timestamp('customer_read_at')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index('email');
        });

        Schema::create('conversation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('conversation_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('author');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamps();
        });

        Schema::create('conversation_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_attachments');
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('conversation_tokens');
        Schema::dropIfExists('conversations');
    }
};
