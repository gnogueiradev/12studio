<?php

use Illuminate\Support\Facades\Schedule;

// Scheduler corrido em producao pelo supervisor (`php artisan schedule:work`,
// docker/scheduler.conf) — nao por cron do sistema. Tudo withoutOverlapping
// (padrao qrcode).

// Backup diario do SQLite as 04:00 — sem replicacao, e a unica rede de
// seguranca da BD (plano, risco 7d). A copia para fora da maquina e feita
// pelo dono a partir de storage/backups (bind-mounted no host).
Schedule::command('db:backup')
    ->dailyAt('04:00')
    ->withoutOverlapping();

// Fase 3 acrescenta aqui o sweep de reservas de stock expiradas
// (a cada 15 minutos) — rede de seguranca para eventos Stripe perdidos.
