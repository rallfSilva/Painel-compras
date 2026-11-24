<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use Illuminate\Support\Facades\DB;

/** Empenho, liquidação e pagamento vinculados ao contrato ou ao processo (arquivo do sistema financeiro estadual). */
class ExecucaoFinanceira extends Importador
{
    private const TIPOS = ['EMPENHO' => 'Empenho', 'LIQUIDACAO' => 'Liquidação', 'PAGAMENTO' => 'Pagamento'];

    public function codigo(): string
    {
        return 'EXECUCAO_FINANCEIRA';
    }

    public function titulo(): string
    {
        return 'Execução financeira (empenho, liquidação, pagamento)';
    }

    public function descricao(): string
    {
        return 'Documentos financeiros com tipo, número, data e valor, ligados ao contrato ou ao processo. Ativa empenhado, liquidado e pago nos contratos.';
    }

    public function campos(): array
    {
        return [
            'tipo' => ['rotulo' => 'Tipo (Empenho, Liquidação, Pagamento)', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['fase', 'etapa', 'tipo_documento']],
            'documento' => ['rotulo' => 'Nº do documento (NE, NL, OB)', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['numero', 'numero_documento', 'nota']],
            'data' => ['rotulo' => 'Data', 'tipo' => 'data', 'obrigatorio' => true, 'sinonimos' => ['data_documento', 'data_emissao']],
            'valor' => ['rotulo' => 'Valor', 'tipo' => 'decimal', 'obrigatorio' => true],
            'contrato' => ['rotulo' => 'Nº do contrato', 'tipo' => 'texto', 'sinonimos' => ['numero_contrato']],
            'unidade' => ['rotulo' => 'Unidade compradora', 'tipo' => 'texto', 'sinonimos' => ['unidade_gestora', 'ug']],
            'processo' => ['rotulo' => 'Nº de publicação ou processo SEI', 'tipo' => 'texto', 'sinonimos' => ['numero_publicacao', 'processo_sei']],
        ];
    }

    public function exemplo(): array
    {
        return [
            'tipo' => 'Empenho',
            'documento' => '2026NE000123',
            'data' => '12/04/2026',
            'valor' => '5.780,00',
            'contrato' => '010/2026',
            'unidade' => '925001',
            'processo' => '90001/2026',
        ];
    }

    public function chave(array $l): ?string
    {
        return self::opcao($l['tipo'], self::TIPOS).'|'.mb_strtoupper($l['documento']);
    }

    public function existe(array $l, Contexto $c): bool
    {
        return DB::table('contratos_execucao_financeira')->where('tipo', self::opcao($l['tipo'], self::TIPOS))->where('documento', $l['documento'])->exists();
    }

    private function contrato(array $l, Contexto $c): ?int
    {
        return $c->ref->contrato($l['contrato'], $c->ref->buscar('unidades_compradoras', $l['unidade']));
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if (! self::opcao($l['tipo'], self::TIPOS)) {
            $e[] = ['tipo', 'Tipo inválido. Use Empenho, Liquidação ou Pagamento.'];
        }
        if ($l['contrato'] === null && $l['processo'] === null) {
            $e[] = ['contrato', 'Informe o contrato ou o processo do documento.'];
        }
        if ($l['contrato'] !== null && ! $this->contrato($l, $c)) {
            $e[] = self::naoEncontrado('contrato', 'Contrato', $l['contrato']);
        }
        if ($l['processo'] !== null && ! $c->ref->processo($l['processo'])) {
            $e[] = self::naoEncontrado('processo', 'Processo', $l['processo']);
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $ct = $this->contrato($l, $c);
        $this->salvar('contratos_execucao_financeira', ['tipo' => self::opcao($l['tipo'], self::TIPOS), 'documento' => $l['documento']], [
            'data' => $l['data'], 'valor' => $l['valor'], 'contrato_id' => $ct,
            'processo_id' => $c->ref->processo($l['processo']) ?? ($ct ? DB::table('contratos')->where('id', $ct)->value('processo_id') : null),
        ], $c);
    }
}
