<?php

namespace App\Services\Ia;

/**
 * Interpretador local, sem modelo de linguagem: palavras-chave com peso escolhem a consulta, e expressões
 * simples extraem ano, quantidade, prazo, órgão (sigla) e o tipo de material ou serviço.
 * É o padrão (AI_PROVIDER=none) e a alternativa automática quando o modelo não responde.
 */
final class InterpretadorRegras
{
    public function interpretar(string $pergunta): Interpretacao
    {
        $t = Texto::normalizar($pergunta);
        $melhor = null;
        $nota = 0;
        foreach (CatalogoConsultas::CONSULTAS as $codigo => $c) {
            $soma = 0;
            foreach ($c['padroes'] as [$re, $peso]) {
                if (preg_match($re, $t)) {
                    $soma += $peso;
                }
            }
            if ($soma > $nota) {
                [$melhor, $nota] = [$codigo, $soma];
            }
        }

        return new Interpretacao($nota >= CatalogoConsultas::MINIMO ? $melhor : null, self::parametros($pergunta), 'regras');
    }

    /** @return array<string, mixed> */
    public static function parametros(string $pergunta): array
    {
        $t = Texto::normalizar($pergunta);
        $p = [];

        preg_match_all('/\b(19[5-9]\d|20\d{2})\b/', $t, $m);
        $anos = array_values(array_unique(array_map('intval', $m[1])));
        if (count($anos) === 2 && preg_match('/\b(entre|de \d{4} a|ate)\b/', $t)) {
            sort($anos);
            $anos = $anos[1] - $anos[0] <= 20 ? range($anos[0], $anos[1]) : $anos;
        }
        if ($anos) {
            $p['anos'] = $anos;
        } elseif (preg_match('/\b(este ano|neste ano|ano atual|ano corrente)\b/', $t)) {
            $p['periodo'] = 'ano_atual';
        } elseif (preg_match('/\b(ano passado|ano anterior|ultimo ano)\b/', $t)) {
            $p['periodo'] = 'ano_anterior';
        } elseif (preg_match('/\bultimos (5|cinco) anos\b/', $t)) {
            $p['periodo'] = 'ultimos_5';
        }

        if (preg_match('/\b(?:top|os|as|primeir[oa]s|maiores)\s+(\d{1,3})\b/', $t, $m) || preg_match('/\b(\d{1,3})\s+(?:maiores|primeir|principais|itens|fornecedores|orgaos|secretarias|municipios|atas|contratos)/', $t, $m)) {
            $p['limite'] = max(1, min(100, (int) $m[1]));
        }
        if (preg_match('/\b(\d{1,3})\s*dias\b/', $t, $m)) {
            $p['dias'] = max(1, min(365, (int) $m[1]));
        }

        // Siglas de órgão: palavras em maiúsculas na pergunta original (SESAU, SEED, PMRR).
        preg_match_all('/\b([A-ZÁÉÍÓÚÃÕÇ]{2,12})\b/u', $pergunta, $m);
        $siglas = array_values(array_diff(array_unique($m[1]), ['MPE', 'MEI', 'ME', 'EPP', 'PNAE', 'RP', 'TI', 'PCA', 'CNPJ', 'CPF', 'RR', 'ATA', 'ATAS']));
        if ($siglas) {
            $p['orgaos'] = $siglas;
        }

        // Tipo de material ou serviço: o que vem depois de "comprou de", "gastou com", "compras de"...
        if (preg_match('/\b(?:compr\w*|gast\w*|adquir\w*|contrat\w*|aquisic\w*|despesas?)\s+(?:de|do|da|dos|das|com|em)\s+(.+?)(?=\s+(?:em|no|na|nos|nas|durante|entre|pel[oa]s?|para|pelo|ate|neste|este|ano|desde)\b|[?.!,;]|$)/u', $t, $m)) {
            $termo = trim($m[1]);
            if ($termo !== '' && ! preg_match('/^(\d{4}|licitac\w*|compras?|agricultura familiar|mpe)$/', $termo) && Texto::radicais($termo)) {
                $p['termo'] = mb_substr($termo, 0, 80);
            }
        }

        return $p;
    }
}
