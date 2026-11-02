<?php

/*
 * Credenciais das integrações (seção 26). Ficam só no .env do servidor; a tela de integrações mostra
 * apenas se o token está configurado. O PNCP e o Compras.gov.br (dados abertos) não exigem token.
 */
return [
    'tokens' => [
        'COMPRASGOV' => env('COMPRASGOV_TOKEN'),
        'FINANCEIRO_RR' => env('FINANCEIRO_RR_TOKEN'),
    ],
];
