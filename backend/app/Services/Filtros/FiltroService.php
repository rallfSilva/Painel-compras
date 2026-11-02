<?php

namespace App\Services\Filtros;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Camada única de aplicação dos filtros globais (seção 27/28: não filtrar em cada componente).
 *
 * Toda consulta analítica parte de baseItens(), que une itens, processos e todas as dimensões
 * usadas pelos filtros, com aliases fixos:
 *   pi  processos_itens      p   processos           s   sistematicas_aquisicao
 *   uc  unidades_compradoras o   orgaos              mc  municípios do comprador
 *   ic  itens_catalogo       cm  classes_materiais   fo  fornecedores
 *   mf  municípios do fornecedor                     ef  estados do fornecedor
 */
class FiltroService
{
    public function baseItens(FiltrosAplicados $filtros): Builder
    {
        $q = DB::table('processos_itens as pi')
            ->join('processos as p', 'p.id', '=', 'pi.processo_id')
            ->join('sistematicas_aquisicao as s', 's.id', '=', 'p.sistematica_id')
            ->join('unidades_compradoras as uc', 'uc.id', '=', 'p.unidade_compradora_id')
            ->join('orgaos as o', 'o.id', '=', 'uc.orgao_id')
            ->leftJoin('municipios as mc', 'mc.id', '=', 'uc.municipio_id')
            ->leftJoin('itens_catalogo as ic', 'ic.id', '=', 'pi.item_catalogo_id')
            ->leftJoin('classes_materiais as cm', 'cm.id', '=', 'ic.classe_id')
            ->leftJoin('fornecedores as fo', 'fo.id', '=', 'pi.fornecedor_id')
            ->leftJoin('municipios as mf', 'mf.id', '=', 'fo.municipio_id')
            ->leftJoin('estados as ef', 'ef.id', '=', 'mf.estado_id');

        $this->aplicar($q, $filtros);

        return $q;
    }

    /** Subconsulta com os IDs de processos que atendem aos filtros. */
    public function idsProcessos(FiltrosAplicados $filtros): Builder
    {
        return $this->baseItens($filtros)->select('p.id')->distinct();
    }

    public function aplicar(Builder $q, FiltrosAplicados $f): void
    {
        if ($f->orgaosPermitidos !== null) {
            $q->whereIn('o.id', $f->orgaosPermitidos ?: [0]);
        }

        if ($intervalo = $f->intervalo()) {
            $q->whereBetween('p.data_abertura', [$intervalo[0]->toDateString(), $intervalo[1]->toDateString()]);
        }

        $this->tempo($q, $f->valores('tempo'));
        $this->porPrefixo($q, $f->valores('porte'), ['p' => 'fo.porte_id']);
        $this->porPrefixo($q, $f->valores('sistematica'), ['s' => 'p.sistematica_id', 'f' => 'p.forma_aquisicao_id']);
        $this->porPrefixo($q, $f->valores('natureza'), [
            'n' => 'p.classificacao_natureza_id',
            'se' => 'pi.subelemento_despesa_id',
            'el' => fn (Builder $w, array $ids) => $w->whereIn('pi.subelemento_despesa_id', DB::table('subelementos_despesa')->select('id')->whereIn('elemento_despesa_id', $ids)),
            'nd' => fn (Builder $w, array $ids) => $w->whereIn('pi.subelemento_despesa_id', DB::table('subelementos_despesa as sd')
                ->join('elementos_despesa as ed', 'ed.id', '=', 'sd.elemento_despesa_id')->select('sd.id')->whereIn('ed.natureza_despesa_id', $ids)),
        ]);
        $this->porPrefixo($q, $f->valores('classe'), ['g' => 'cm.grupo_id', 'c' => 'ic.classe_id']);
        $this->porPrefixo($q, $f->valores('item'), ['i' => 'pi.item_catalogo_id']);
        $this->porPrefixo($q, $f->valores('comprador_local'), ['r' => 'mc.regiao_planejamento_id', 'm' => 'uc.municipio_id']);
        $this->porPrefixo($q, $f->valores('unidade'), ['us' => 'o.unidade_superior_id', 'o' => 'uc.orgao_id', 'u' => 'p.unidade_compradora_id']);
        $this->porPrefixo($q, $f->valores('amparo'), [
            'a' => 'p.amparo_legal_id',
            'rg' => 'p.regime_legal_id',
        ]);
        $this->porPrefixo($q, $f->valores('fornecedor'), ['fo' => 'pi.fornecedor_id']);
        $this->porPrefixo($q, $f->valores('fornecedor_local'), [
            'uf' => fn (Builder $w, array $ufs) => $w->whereIn('ef.uf', $ufs),
            'm' => 'fo.municipio_id',
        ], numerico: false);

        $rp = $f->valores('rp');
        if ($rp && ! (in_array('sim', $rp, true) && in_array('nao', $rp, true))) {
            $q->where('p.registro_precos', in_array('sim', $rp, true));
        }

        if ($pub = $f->valores('publicacao')) {
            $q->whereIn('p.numero_publicacao', $pub);
        }
    }

    /** Árvore Ano > Semestre > Trimestre > Mês: a:2025, s:2025-1, t:2025-3, m:2025-07 */
    private function tempo(Builder $q, array $chaves): void
    {
        if (! $chaves) {
            return;
        }
        $q->where(function (Builder $w) use ($chaves) {
            foreach ($chaves as $chave) {
                if (preg_match('/^a:(\d{4})$/', $chave, $m)) {
                    $w->orWhere('p.ano', (int) $m[1]);
                } elseif (preg_match('/^s:(\d{4})-([12])$/', $chave, $m)) {
                    $w->orWhere(fn ($x) => $x->where('p.ano', (int) $m[1])->where('p.semestre', (int) $m[2]));
                } elseif (preg_match('/^t:(\d{4})-([1-4])$/', $chave, $m)) {
                    $w->orWhere(fn ($x) => $x->where('p.ano', (int) $m[1])->where('p.trimestre', (int) $m[2]));
                } elseif (preg_match('/^m:(\d{4})-(\d{2})$/', $chave, $m)) {
                    $w->orWhere(fn ($x) => $x->where('p.ano', (int) $m[1])->where('p.mes', (int) $m[2]));
                }
            }
        });
    }

    /**
     * Aplica uma árvore de filtro: nós de níveis diferentes são combinados com OU dentro do mesmo filtro.
     *
     * @param  list<string>  $chaves
     * @param  array<string, string|\Closure>  $mapa  prefixo => coluna ou closure
     */
    private function porPrefixo(Builder $q, array $chaves, array $mapa, bool $numerico = true): void
    {
        if (! $chaves) {
            return;
        }
        $grupos = [];
        foreach ($chaves as $chave) {
            $partes = explode(':', $chave, 2);
            if (count($partes) !== 2 || ! isset($mapa[$partes[0]])) {
                continue;
            }
            $valor = $partes[1];
            if ($numerico && $partes[0] !== 'uf') {
                if (! ctype_digit($valor)) {
                    continue;
                }
                $valor = (int) $valor;
            }
            $grupos[$partes[0]][] = $valor;
        }
        if (! $grupos) {
            $q->whereRaw('1 = 0'); // filtro informado mas nenhum valor válido: não retornar tudo

            return;
        }
        $q->where(function (Builder $w) use ($grupos, $mapa) {
            foreach ($grupos as $prefixo => $valores) {
                $alvo = $mapa[$prefixo];
                $w->orWhere(function (Builder $x) use ($alvo, $valores) {
                    if ($alvo instanceof \Closure) {
                        $alvo($x, $valores);
                    } else {
                        $x->whereIn($alvo, $valores);
                    }
                });
            }
        });
    }
}
