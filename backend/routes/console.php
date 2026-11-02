<?php

use App\Services\AgregacaoService;
use App\Services\Integracoes\IntegracoesService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sic:recalcular-agregados', function (AgregacaoService $servico) {
    $this->info($servico->recalcular().' linhas de resumo recalculadas.');
})->purpose('Recalcula a tabela de resumo mensal das compras');

Artisan::command('sic:sincronizar {codigo? : PNCP, COMPRASGOV ou FINANCEIRO_RR (vazio = todas as ativas)} {--de=} {--ate=}', function (IntegracoesService $s) {
    $codigos = $this->argument('codigo') ? [strtoupper($this->argument('codigo'))]
        : DB::table('integracoes')->where('ativa', true)->pluck('codigo')->all();
    foreach ($codigos as $c) {
        $log = $s->agendar($c, null, $this->option('de'), $this->option('ate'));
        $this->info("$c: sincronização na fila (log $log).");
    }
})->purpose('Coloca na fila a sincronização das integrações externas');

// Sincronização diária das integrações ativas (requer o agendador: php artisan schedule:work ou cron).
Schedule::command('sic:sincronizar')->dailyAt('02:00')->withoutOverlapping();
