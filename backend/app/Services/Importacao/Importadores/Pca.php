<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use App\Services\Planejamento\PcaService;
use Illuminate\Support\Facades\DB;

/** Plano de Contratações Anual: uma linha por demanda (seção 20). */
class Pca extends Importador
{
    private const PRIORIDADES = ['ALTA' => 'Alta', 'MEDIA' => 'Média', 'BAIXA' => 'Baixa'];

    private const PLANEJAMENTO = ['PLANEJADA' => 'Planejada', 'APROVADA' => 'Aprovada', 'REPROGRAMADA' => 'Reprogramada', 'EXCLUIDA' => 'Excluída'];

    public function codigo(): string
    {
        return 'PCA';
    }

    public function titulo(): string
    {
        return 'Plano de Contratações Anual (PCA)';
    }

    public function descricao(): string
    {
        return 'Uma linha por demanda do plano, com todos os campos da seção 20. O nº de publicação do processo liga a demanda à contratação.';
    }

    public function campos(): array
    {
        return [
            'exercicio' => ['rotulo' => 'Exercício', 'tipo' => 'numero', 'obrigatorio' => true, 'sinonimos' => ['ano', 'ano_pca']],
            'orgao' => ['rotulo' => 'Órgão (sigla, código ou nome)', 'tipo' => 'texto', 'obrigatorio' => true],
            'numero_demanda' => ['rotulo' => 'Nº da demanda', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['demanda', 'numero_item_pca', 'numero']],
            'objeto' => ['rotulo' => 'Objeto', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['descricao', 'descricao_item']],
            'valor_estimado' => ['rotulo' => 'Valor estimado', 'tipo' => 'decimal', 'obrigatorio' => true, 'sinonimos' => ['valor', 'valor_total']],
            'unidade_requisitante' => ['rotulo' => 'Unidade requisitante', 'tipo' => 'texto', 'sinonimos' => ['unidade']],
            'codigo_contratacao' => ['rotulo' => 'Código da contratação', 'tipo' => 'texto'],
            'descricao_necessidade' => ['rotulo' => 'Descrição da necessidade', 'tipo' => 'texto', 'sinonimos' => ['necessidade', 'justificativa']],
            'categoria' => ['rotulo' => 'Categoria (Material, Serviço, Obra, TIC)', 'tipo' => 'texto'],
            'classe' => ['rotulo' => 'Classe (código ou nome)', 'tipo' => 'texto'],
            'grupo' => ['rotulo' => 'Grupo (código ou nome)', 'tipo' => 'texto'],
            'quantidade_estimada' => ['rotulo' => 'Quantidade estimada', 'tipo' => 'decimal', 'sinonimos' => ['quantidade']],
            'data_pretendida' => ['rotulo' => 'Data pretendida', 'tipo' => 'data', 'sinonimos' => ['data_desejada']],
            'prioridade' => ['rotulo' => 'Prioridade (Alta, Média, Baixa)', 'tipo' => 'texto', 'sinonimos' => ['grau_prioridade']],
            'situacao_planejamento' => ['rotulo' => 'Situação do planejamento', 'tipo' => 'texto'],
            'responsavel' => ['rotulo' => 'Responsável', 'tipo' => 'texto'],
            'processo_sei' => ['rotulo' => 'Processo SEI', 'tipo' => 'texto'],
            'situacao_execucao' => ['rotulo' => 'Situação de execução', 'tipo' => 'texto'],
            'numero_publicacao' => ['rotulo' => 'Nº de publicação do processo de contratação', 'tipo' => 'texto', 'sinonimos' => ['processo_relacionado']],
            'situacao_pca' => ['rotulo' => 'Situação do plano (Elaboração, Aprovado, Publicado, Revisado)', 'tipo' => 'codigo'],
            'data_aprovacao' => ['rotulo' => 'Data de aprovação do plano', 'tipo' => 'data'],
            'link_pncp' => ['rotulo' => 'Link do plano no PNCP', 'tipo' => 'texto'],
        ];
    }

    public function exemplo(): array
    {
        return [
            'exercicio' => '2026',
            'orgao' => 'SEEX',
            'numero_demanda' => '15',
            'objeto' => 'Aquisição de papel A4 para as unidades da secretaria',
            'valor_estimado' => '32.500,00',
            'unidade_requisitante' => 'SEEX - Departamento de Compras',
            'codigo_contratacao' => 'PCA-2026-015',
            'descricao_necessidade' => 'Reposição anual do material de expediente',
            'categoria' => 'Material',
            'classe' => 'Material de expediente',
            'grupo' => 'Material de escritório',
            'quantidade_estimada' => '1.000',
            'data_pretendida' => '01/03/2026',
            'prioridade' => 'Alta',
            'situacao_planejamento' => 'Aprovada',
            'responsavel' => 'Maria Exemplo da Silva',
            'processo_sei' => '00000.000001/2026-01',
            'situacao_execucao' => 'Em andamento',
            'numero_publicacao' => '90001/2026',
            'situacao_pca' => 'PUBLICADO',
            'data_aprovacao' => '15/12/2025',
            'link_pncp' => 'https://pncp.gov.br/app/pca/11222333000181/2026',
        ];
    }

    public function chave(array $l): ?string
    {
        return mb_strtoupper($l['exercicio'].'|'.$l['orgao'].'|'.$l['numero_demanda']);
    }

    private function pca(array $l, Contexto $c): ?int
    {
        return DB::table('pca')->where('exercicio', (int) $l['exercicio'])->where('orgao_id', $c->ref->buscar('orgaos', $l['orgao']))->value('id');
    }

    public function existe(array $l, Contexto $c): bool
    {
        $p = $this->pca($l, $c);

        return $p && DB::table('pca_demandas')->where('pca_id', $p)->where('numero_demanda', $l['numero_demanda'])->exists();
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if (! $c->ref->buscar('orgaos', $l['orgao'])) {
            $e[] = self::naoEncontrado('orgao', 'Órgão', $l['orgao']);
        }
        if ($l['exercicio'] !== null && ($l['exercicio'] < 2000 || $l['exercicio'] > 2100)) {
            $e[] = ['exercicio', 'Exercício inválido.'];
        }
        $checks = ['categoria' => PcaService::CATEGORIAS, 'prioridade' => self::PRIORIDADES, 'situacao_planejamento' => self::PLANEJAMENTO, 'situacao_execucao' => PcaService::EXECUCAO];
        foreach ($checks as $campo => $op) {
            if ($l[$campo] !== null && ! self::opcao($l[$campo], $op)) {
                $e[] = [$campo, 'Valor inválido. Use: '.implode(', ', $op).'.'];
            }
        }
        foreach (['unidade_requisitante' => ['unidades_compradoras', 'Unidade'], 'classe' => ['classes_materiais', 'Classe'], 'grupo' => ['grupos_materiais', 'Grupo']] as $campo => [$t, $n]) {
            if ($l[$campo] !== null && ! $c->ref->buscar($t, $l[$campo])) {
                $e[] = self::naoEncontrado($campo, $n, $l[$campo]);
            }
        }
        if ($l['numero_publicacao'] !== null && ! $c->ref->processo($l['numero_publicacao'])) {
            $e[] = self::naoEncontrado('numero_publicacao', 'Processo', $l['numero_publicacao']);
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $pca = $this->salvar('pca', ['exercicio' => (int) $l['exercicio'], 'orgao_id' => $c->ref->buscar('orgaos', $l['orgao'])], [
            'situacao' => in_array($l['situacao_pca'], ['ELABORACAO', 'APROVADO', 'PUBLICADO', 'REVISADO'], true) ? $l['situacao_pca'] : null,
            'data_aprovacao' => $l['data_aprovacao'], 'link_pncp' => $l['link_pncp'],
        ], $c);
        $processo = $c->ref->processo($l['numero_publicacao']);
        $classe = $c->ref->buscar('classes_materiais', $l['classe']);
        $demanda = $this->salvar('pca_demandas', ['pca_id' => $pca, 'numero_demanda' => $l['numero_demanda']], [
            'unidade_requisitante_id' => $c->ref->buscar('unidades_compradoras', $l['unidade_requisitante']), 'codigo_contratacao' => $l['codigo_contratacao'],
            'objeto' => $l['objeto'], 'descricao_necessidade' => $l['descricao_necessidade'], 'categoria' => self::opcao($l['categoria'], PcaService::CATEGORIAS),
            'grupo_id' => $c->ref->buscar('grupos_materiais', $l['grupo']) ?? ($classe ? DB::table('classes_materiais')->where('id', $classe)->value('grupo_id') : null),
            'classe_id' => $classe, 'quantidade_estimada' => $l['quantidade_estimada'], 'valor_estimado' => $l['valor_estimado'],
            'data_pretendida' => $l['data_pretendida'], 'prioridade' => self::opcao($l['prioridade'], self::PRIORIDADES),
            'situacao_planejamento' => self::opcao($l['situacao_planejamento'], self::PLANEJAMENTO), 'responsavel' => $l['responsavel'],
            'processo_sei' => $l['processo_sei'],
            'situacao_execucao' => self::opcao($l['situacao_execucao'], PcaService::EXECUCAO) ?? ($processo ? 'EM_ANDAMENTO' : null),
        ], $c);
        if ($processo) {
            DB::table('pca_execucao')->insertOrIgnore(['demanda_id' => $demanda, 'processo_id' => $processo, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
