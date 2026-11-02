<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use Illuminate\Support\Facades\DB;

class Contratos extends Importador
{
    public const SITUACOES = ['VIGENTE' => 'Vigente', 'ENCERRADO' => 'Encerrado', 'RESCINDIDO' => 'Rescindido', 'SUSPENSO' => 'Suspenso'];

    public function codigo(): string
    {
        return 'CONTRATOS';
    }

    public function titulo(): string
    {
        return 'Contratos';
    }

    public function descricao(): string
    {
        return 'Contratos pelo número e unidade compradora, com fornecedor, processo, valores, vigência, gestor e fiscal.';
    }

    public function campos(): array
    {
        return [
            'numero' => ['rotulo' => 'Nº do contrato', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['numero_contrato', 'contrato', 'numero_contrato_empenho']],
            'identificador' => ['rotulo' => 'Identificador na fonte (ex.: nº de controle PNCP)', 'tipo' => 'texto', 'sinonimos' => ['uid', 'numero_controle_pncp']],
            'unidade' => ['rotulo' => 'Unidade compradora (código, UASG ou nome)', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['unidade_compradora', 'uasg']],
            'fornecedor_documento' => ['rotulo' => 'CPF/CNPJ do fornecedor', 'tipo' => 'documento', 'obrigatorio' => true, 'sinonimos' => ['cnpj', 'cnpj_fornecedor', 'ni_fornecedor']],
            'fornecedor_nome' => ['rotulo' => 'Nome do fornecedor', 'tipo' => 'texto', 'sinonimos' => ['fornecedor', 'razao_social']],
            'numero_publicacao' => ['rotulo' => 'Nº de publicação do processo', 'tipo' => 'texto', 'sinonimos' => ['processo', 'numero_processo']],
            'ata' => ['rotulo' => 'Nº da ata de registro de preços', 'tipo' => 'texto', 'sinonimos' => ['numero_ata']],
            'objeto' => ['rotulo' => 'Objeto', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['objeto_contrato']],
            'valor_inicial' => ['rotulo' => 'Valor inicial', 'tipo' => 'decimal', 'obrigatorio' => true, 'sinonimos' => ['valor', 'valor_contrato', 'valor_global']],
            'valor_atualizado' => ['rotulo' => 'Valor atualizado', 'tipo' => 'decimal'],
            'data_assinatura' => ['rotulo' => 'Data de assinatura', 'tipo' => 'data', 'sinonimos' => ['assinatura']],
            'vigencia_inicio' => ['rotulo' => 'Início da vigência', 'tipo' => 'data', 'obrigatorio' => true, 'sinonimos' => ['data_vigencia_inicio', 'inicio_vigencia']],
            'vigencia_fim' => ['rotulo' => 'Fim da vigência', 'tipo' => 'data', 'obrigatorio' => true, 'sinonimos' => ['data_vigencia_fim', 'fim_vigencia']],
            'situacao' => ['rotulo' => 'Situação (Vigente, Encerrado, Rescindido, Suspenso)', 'tipo' => 'texto'],
            'gestor' => ['rotulo' => 'Gestor', 'tipo' => 'texto'],
            'fiscal' => ['rotulo' => 'Fiscal', 'tipo' => 'texto'],
            'link_pncp' => ['rotulo' => 'Link no PNCP', 'tipo' => 'texto', 'sinonimos' => ['link']],
        ];
    }

    public function chave(array $l): ?string
    {
        return mb_strtoupper($l['unidade'].'|'.$l['numero']);
    }

    private function id(array $l, Contexto $c): ?int
    {
        $u = $c->ref->buscar('unidades_compradoras', $l['unidade']);

        return ($l['identificador'] ? DB::table('contratos')->where('uid', $l['identificador'])->value('id') : null)
            ?? DB::table('contratos')->where('numero', $l['numero'])->where('unidade_compradora_id', $u)->value('id');
    }

    public function existe(array $l, Contexto $c): bool
    {
        return $this->id($l, $c) !== null;
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if (! $c->ref->buscar('unidades_compradoras', $l['unidade'])) {
            $e[] = self::naoEncontrado('unidade', 'Unidade compradora', $l['unidade']);
        }
        if ($l['numero_publicacao'] !== null && ! $c->ref->processo($l['numero_publicacao'])) {
            $e[] = self::naoEncontrado('numero_publicacao', 'Processo', $l['numero_publicacao']);
        }
        if ($l['situacao'] !== null && ! self::opcao($l['situacao'], self::SITUACOES)) {
            $e[] = ['situacao', 'Situação inválida. Use: '.implode(', ', self::SITUACOES).'.'];
        }
        if ($l['vigencia_inicio'] && $l['vigencia_fim'] && $l['vigencia_fim'] < $l['vigencia_inicio']) {
            $e[] = ['vigencia_fim', 'O fim da vigência é anterior ao início.'];
        }
        if ($l['valor_inicial'] !== null && $l['valor_inicial'] < 0) {
            $e[] = ['valor_inicial', 'O valor não pode ser negativo.'];
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $u = $c->ref->buscar('unidades_compradoras', $l['unidade']);
        $id = $this->id($l, $c);
        $orgao = DB::table('unidades_compradoras')->where('id', $u)->value('orgao_id');
        $this->salvar('contratos', $id ? ['id' => $id] : ['uid' => $l['identificador'] ?? "IMP:$u:{$l['numero']}"], [
            'numero' => $l['numero'], 'unidade_compradora_id' => $u, 'fornecedor_id' => Fornecedores::garantir($l['fornecedor_documento'], $l['fornecedor_nome'], $c),
            'processo_id' => $c->ref->processo($l['numero_publicacao']), 'ata_id' => $c->ref->ata($l['ata'], $orgao) ?? $c->ref->ata($l['ata'], null),
            'objeto' => $l['objeto'], 'valor_inicial' => $l['valor_inicial'], 'valor_atualizado' => $l['valor_atualizado'], 'data_assinatura' => $l['data_assinatura'],
            'vigencia_inicio' => $l['vigencia_inicio'], 'vigencia_fim' => $l['vigencia_fim'],
            'situacao' => self::opcao($l['situacao'], self::SITUACOES) ?? ($id ? null : ($l['vigencia_fim'] < now()->toDateString() ? 'ENCERRADO' : 'VIGENTE')),
            'gestor' => $l['gestor'], 'fiscal' => $l['fiscal'], 'link_pncp' => $l['link_pncp'],
        ], $c);
    }
}
