<?php

namespace App\Services\Ia;

use App\Models\User;
use App\Services\Filtros\FiltrosAplicados;
use Illuminate\Support\Facades\DB;

/**
 * Assistente de consultas em linguagem natural (seção 32).
 *
 * Fluxo: a pergunta é interpretada (regras locais ou modelo de linguagem) em uma consulta do catálogo
 * e seus parâmetros; os parâmetros viram filtros somados aos filtros que o usuário já tem no painel;
 * a consulta roda nos serviços de leitura, com a restrição por órgão do perfil; a resposta mostra o
 * que foi entendido, o período, os filtros, os indicadores (com a natureza de cada um), a fonte e o
 * módulo onde abrir os registros. Toda pergunta fica no log de auditoria.
 */
final class AssistenteService
{
    public function __construct(
        private readonly InterpretadorRegras $regras,
        private readonly InterpretadorLlm $llm,
        private readonly ResolvedorTermos $termos,
        private readonly ConsultasIa $consultas,
    ) {}

    public function configuracao(): array
    {
        $provedor = (string) config('ia.provider', 'none');

        return [
            'provedor' => InterpretadorLlm::ativo() ? $provedor : 'none',
            'modelo' => InterpretadorLlm::ativo() ? (string) config('ia.model') : null,
            'descricao' => InterpretadorLlm::ativo()
                ? 'As perguntas são interpretadas pelo modelo configurado; os números vêm sempre das consultas do sistema.'
                : 'As perguntas são interpretadas por regras locais, sem serviço externo. Os números vêm das consultas do sistema.',
            'consultas' => CatalogoConsultas::lista(),
            'aviso' => ConsultasIa::AVISO_JURIDICO,
        ];
    }

    public function perguntar(string $pergunta, FiltrosAplicados $base, User $user): array
    {
        $pergunta = trim(preg_replace('/\s+/', ' ', $pergunta));
        $int = InterpretadorLlm::ativo() ? $this->llm->interpretar($pergunta) : $this->regras->interpretar($pergunta);

        $resultado = $int->consulta === null
            ? $this->naoEntendida()
            : $this->responder($int, $base, $user);

        $resultado = [
            'pergunta' => $pergunta,
            'consulta' => $int->consulta ? ['codigo' => $int->consulta, 'titulo' => CatalogoConsultas::CONSULTAS[$int->consulta]['titulo']] : null,
            'interpretador' => $int->interpretador,
        ] + $resultado + ['sem_dados' => false, 'natureza' => 'registrado', 'avisos' => [], 'filtros' => [], 'registros' => null, 'tabela' => null, 'indicadores' => [], 'fonte' => null];

        if ($int->observacao) {
            $resultado['avisos'][] = $int->observacao;
        }
        $resultado['avisos'][] = ConsultasIa::AVISO_JURIDICO;

        DB::table('logs_auditoria')->insert([
            'user_id' => $user->id, 'acao' => 'ia.consultar', 'recurso' => 'assistente', 'recurso_id' => null, 'ip' => request()?->ip(),
            'detalhes' => json_encode(['pergunta' => mb_substr($pergunta, 0, 500), 'consulta' => $int->consulta, 'interpretador' => $int->interpretador,
                'parametros' => $int->parametros, 'sem_dados' => $resultado['sem_dados']], JSON_UNESCAPED_UNICODE),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $resultado;
    }

    private static function periodo(FiltrosAplicados $f): string
    {
        $t = $f->valores('tempo');
        if ($t && ! $f->intervalo()) {
            return 'Seleção do filtro de tempo: '.implode(', ', array_map(fn ($c) => preg_replace('/^[astm]:/', '', $c), $t));
        }

        return $f->descricaoPeriodo();
    }

    private function naoEntendida(): array
    {
        return [
            'resposta' => 'Não consegui relacionar a pergunta a uma das consultas disponíveis. Veja abaixo os tipos de pergunta que o assistente responde, ou reformule citando o que quer saber (valor comprado, órgãos, itens, fornecedores, economia, atas, contratos, MPE, municípios).',
            'sem_dados' => true,
            'entendimento' => null,
            'periodo' => null,
            'sugestoes' => array_column(CatalogoConsultas::lista(), 'exemplo'),
        ];
    }

    private function responder(Interpretacao $int, FiltrosAplicados $base, User $user): array
    {
        $p = $int->parametros;
        $selecoes = $base->selecoes;
        $periodo = [$base->periodo, $base->dataInicio, $base->dataFim];
        $filtros = [];
        $abrir = [];
        $avisos = [];
        $recorte = '';

        // Período citado na pergunta substitui o período do painel.
        if (! empty($p['anos'])) {
            $selecoes['tempo'] = array_map(fn ($a) => "a:$a", $p['anos']);
            $periodo = ['todo', null, null];
            $rotulo = count($p['anos']) > 1 ? min($p['anos']).' a '.max($p['anos']) : (string) $p['anos'][0];
            $filtros[] = ['filtro' => 'tempo', 'rotulo' => 'Ano', 'valor' => $rotulo, 'origem' => 'pergunta'];
            foreach ($p['anos'] as $a) {
                $abrir[] = ['filtro' => 'tempo', 'chave' => "a:$a", 'rotulo' => (string) $a];
            }
        } elseif (! empty($p['periodo'])) {
            $periodo = [$p['periodo'], null, null];
            unset($selecoes['tempo']);
            $filtros[] = ['filtro' => 'periodo', 'rotulo' => 'Período', 'valor' => str_replace('_', ' ', $p['periodo']), 'origem' => 'pergunta'];
        }

        if (! empty($p['orgaos'])) {
            $orgaos = $this->termos->orgaos($p['orgaos']);
            $faltam = array_values(array_filter($p['orgaos'], fn ($sg) => mb_strlen($sg) >= 3 && ! in_array(mb_strtoupper($sg), $this->termos->siglas($orgaos), true)));
            if ($faltam) {
                $avisos[] = 'Sigla não encontrada entre os órgãos cadastrados: '.implode(', ', $faltam).'. O resultado considera '.($orgaos ? 'apenas os órgãos encontrados.' : 'todos os órgãos do seu acesso.');
            }
            if ($orgaos) {
                $selecoes['unidade'] = array_column($orgaos, 'chave');
                foreach ($orgaos as $o) {
                    $filtros[] = ['filtro' => 'unidade', 'rotulo' => 'Órgão', 'valor' => $o['nome'], 'origem' => 'pergunta'];
                    $abrir[] = ['filtro' => 'unidade', 'chave' => $o['chave'], 'rotulo' => $o['nome']];
                }
            }
        }

        $usaTermo = in_array($int->consulta, ['total_comprado', 'ranking_orgaos', 'ranking_fornecedores', 'ranking_itens', 'municipios_volume', 'economia_media', 'variacao_precos', 'participacao_mpe', 'compras_conjuntas', 'orgaos_mesmos_materiais'], true);
        if ($usaTermo && ! empty($p['termo'])) {
            $c = $this->termos->classificacao($p['termo']);
            if (! $c) {
                $r = ConsultasIa::semDados(sprintf('Não encontrei no catálogo um grupo, classe ou item que corresponda a “%s”. Reformule com o nome usado no catálogo (ex.: equipamentos de informática, medicamentos, material de limpeza).', $p['termo']), 'catalogo');
                unset($r['modulo']);

                return $r + ['entendimento' => CatalogoConsultas::CONSULTAS[$int->consulta]['titulo'].' de “'.$p['termo'].'”', 'periodo' => null, 'filtros' => $filtros,
                    'registros' => ['modulo' => 'catalogo', 'filtros' => [], 'periodo' => null]];
            }
            $selecoes[$c['filtro']] = [$c['chave']];
            $tipo = ['grupo' => 'Grupo', 'classe' => 'Classe', 'item' => 'Item'][$c['tipo']];
            $filtros[] = ['filtro' => $c['filtro'], 'rotulo' => $tipo, 'valor' => $c['nome'], 'origem' => 'pergunta', 'termo' => $p['termo']];
            $abrir[] = ['filtro' => $c['filtro'], 'chave' => $c['chave'], 'rotulo' => $c['nome']];
            $recorte = 'de '.mb_strtoupper($c['nome']);
            $avisos[] = sprintf('“%s” foi relacionado %s “%s” do catálogo. Confira se é o recorte desejado.', $p['termo'], ['grupo' => 'ao grupo', 'classe' => 'à classe', 'item' => 'ao item'][$c['tipo']], $c['nome']);
        }

        $f = new FiltrosAplicados($periodo[0], $periodo[1], $periodo[2], $selecoes, $user->orgaosPermitidos(), $base->hoje);
        $r = $this->consultas->executar($int->consulta, $f, $p, $recorte, $user->temPermissao('dados_pessoais.ver'));

        $doPainel = collect($base->selecoes)->except(array_unique(array_column($filtros, 'filtro')))->map(fn ($v) => count($v))->filter()->all();
        foreach ($doPainel as $nome => $n) {
            $filtros[] = ['filtro' => $nome, 'rotulo' => 'Filtro do painel', 'valor' => ucfirst(str_replace('_', ' ', $nome)).' ('.$n.($n > 1 ? ' seleções' : ' seleção').')', 'origem' => 'painel'];
        }
        if ($user->orgaosPermitidos() !== null) {
            $filtros[] = ['filtro' => 'acesso', 'rotulo' => 'Acesso', 'valor' => 'Somente os órgãos permitidos ao seu perfil', 'origem' => 'perfil'];
        }

        $entendimento = CatalogoConsultas::CONSULTAS[$int->consulta]['titulo'].($recorte ? " $recorte" : '');
        $modulo = $r['modulo'] ?? null;
        unset($r['modulo']);
        $r['avisos'] = array_merge($avisos, $r['avisos'] ?? []);

        return $r + [
            'entendimento' => $entendimento,
            'periodo' => ! empty($p['anos']) ? (count($p['anos']) > 1 ? 'Anos de '.min($p['anos']).' a '.max($p['anos']) : 'Ano de '.$p['anos'][0]) : self::periodo($f),
            'filtros' => $filtros,
            'registros' => $modulo ? ['modulo' => $modulo, 'filtros' => $abrir, 'periodo' => ! empty($p['anos']) ? 'todo' : ($p['periodo'] ?? null)] : null,
        ];
    }
}
