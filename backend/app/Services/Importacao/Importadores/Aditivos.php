<?php

namespace App\Services\Importacao\Importadores;

use App\Services\Importacao\Contexto;
use App\Services\Importacao\Importador;
use Illuminate\Support\Facades\DB;

class Aditivos extends Importador
{
    private const TIPOS = ['PRAZO' => 'Prazo', 'VALOR' => 'Valor', 'MISTO' => 'Prazo e valor', 'OBJETO' => 'Objeto', 'APOSTILAMENTO' => 'Apostilamento'];

    public function codigo(): string
    {
        return 'ADITIVOS';
    }

    public function titulo(): string
    {
        return 'Aditivos de contratos';
    }

    public function descricao(): string
    {
        return 'Termos aditivos de contratos já cadastrados. Aditivos de prazo prorrogam o fim da vigência do contrato.';
    }

    public function campos(): array
    {
        return [
            'contrato' => ['rotulo' => 'Nº do contrato', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['numero_contrato', 'contrato_numero']],
            'unidade' => ['rotulo' => 'Unidade compradora do contrato', 'tipo' => 'texto', 'sinonimos' => ['unidade_compradora', 'uasg']],
            'numero' => ['rotulo' => 'Nº do aditivo', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['numero_aditivo', 'aditivo']],
            'tipo' => ['rotulo' => 'Tipo (Prazo, Valor, Misto, Objeto, Apostilamento)', 'tipo' => 'texto', 'obrigatorio' => true, 'sinonimos' => ['tipo_aditivo']],
            'valor' => ['rotulo' => 'Valor do acréscimo (negativo para supressão)', 'tipo' => 'decimal', 'sinonimos' => ['valor_aditivo', 'valor_acrescimo']],
            'nova_vigencia_fim' => ['rotulo' => 'Novo fim da vigência', 'tipo' => 'data', 'sinonimos' => ['nova_vigencia', 'vigencia_fim']],
            'data_assinatura' => ['rotulo' => 'Data de assinatura', 'tipo' => 'data'],
            'justificativa' => ['rotulo' => 'Justificativa', 'tipo' => 'texto', 'sinonimos' => ['motivo']],
        ];
    }

    public function exemplo(): array
    {
        return [
            'contrato' => '010/2026',
            'unidade' => '925001',
            'numero' => '1',
            'tipo' => 'Valor',
            'valor' => '1.445,00',
            'nova_vigencia_fim' => '',
            'data_assinatura' => '15/08/2026',
            'justificativa' => 'Acréscimo de 25% na quantidade (art. 125 da Lei 14.133/2021)',
        ];
    }

    public function chave(array $l): ?string
    {
        return mb_strtoupper(($l['unidade'] ?? '').'|'.$l['contrato'].'|'.$l['numero']);
    }

    private function contrato(array $l, Contexto $c): ?int
    {
        return $c->ref->contrato($l['contrato'], $c->ref->buscar('unidades_compradoras', $l['unidade']));
    }

    public function existe(array $l, Contexto $c): bool
    {
        $ct = $this->contrato($l, $c);

        return $ct && DB::table('contratos_aditivos')->where('contrato_id', $ct)->where('numero', $l['numero'])->exists();
    }

    public function validar(array $l, Contexto $c): array
    {
        $e = [];
        if (! $this->contrato($l, $c)) {
            $e[] = self::naoEncontrado('contrato', 'Contrato', $l['contrato']);
        } elseif ($l['unidade'] === null && DB::table('contratos')->where('numero', $l['contrato'])->count() > 1) {
            $e[] = ['unidade', 'Há mais de um contrato com esse número: informe a unidade compradora.'];
        }
        $tipo = self::opcao($l['tipo'], self::TIPOS);
        if (! $tipo) {
            $e[] = ['tipo', 'Tipo inválido. Use: '.implode(', ', self::TIPOS).'.'];
        } elseif (in_array($tipo, ['VALOR', 'MISTO'], true) && $l['valor'] === null) {
            $e[] = ['valor', 'Aditivo de valor precisa do valor.'];
        } elseif (in_array($tipo, ['PRAZO', 'MISTO'], true) && $l['nova_vigencia_fim'] === null) {
            $e[] = ['nova_vigencia_fim', 'Aditivo de prazo precisa do novo fim da vigência.'];
        }

        return $e;
    }

    public function gravar(array $l, Contexto $c): void
    {
        $ct = $this->contrato($l, $c);
        $this->salvar('contratos_aditivos', ['contrato_id' => $ct, 'numero' => $l['numero']], [
            'tipo' => self::opcao($l['tipo'], self::TIPOS), 'valor' => $l['valor'], 'nova_vigencia_fim' => $l['nova_vigencia_fim'],
            'data_assinatura' => $l['data_assinatura'], 'justificativa' => $l['justificativa'],
        ], $c);
        if ($l['nova_vigencia_fim']) {
            DB::table('contratos')->where('id', $ct)->where('vigencia_fim', '<', $l['nova_vigencia_fim'])->update(['vigencia_fim' => $l['nova_vigencia_fim']]);
        }
    }
}
