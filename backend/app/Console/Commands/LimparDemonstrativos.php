<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Remove todos os dados demonstrativos fictícios antes de carregar dados oficiais (seção 34). */
class LimparDemonstrativos extends Command
{
    protected $signature = 'sic:limpar-demonstrativos {--force : Não pedir confirmação}';

    protected $description = 'Remove os dados demonstrativos fictícios (ficticio = true)';

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Remover TODOS os dados demonstrativos fictícios?')) {
            return self::FAILURE;
        }

        // Ordem respeita as chaves estrangeiras
        $tabelas = [
            'atas_utilizacoes', 'contratos_execucao_financeira', 'contratos_aditivos', 'contratos', 'atas_registro_precos',
            'processos_participantes', 'processos_itens', 'processos', 'fornecedores', 'itens_catalogo', 'classes_materiais', 'grupos_materiais',
            'unidades_compradoras', 'orgaos', 'unidades_superiores',
        ];
        DB::transaction(function () use ($tabelas) {
            DB::table('municipios')->whereIn('regiao_planejamento_id', DB::table('regioes_planejamento')->where('ficticio', true)->select('id'))
                ->update(['regiao_planejamento_id' => null]);
            foreach ($tabelas as $t) {
                $n = DB::table($t)->where('ficticio', true)->delete();
                $this->line("$t: $n removido(s)");
            }
            DB::table('regioes_planejamento')->where('ficticio', true)->delete();
            DB::table('agregados_compras_mensal')->delete();
        });
        $this->info('Dados demonstrativos removidos.');

        return self::SUCCESS;
    }
}
