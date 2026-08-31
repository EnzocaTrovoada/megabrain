<?php

declare(strict_types=1);

/**
 * Casos de teste da CalculadoraMedia.
 *
 * Cada caso é uma matéria real (ou plausível) com os valores esperados calculados
 * à mão. Se você tem uma disciplina com regra esquisita, adicione aqui ANTES de
 * confiar no número que a tela mostrar.
 *
 * 'esperado' aceita: media_consolidada, media_parcial, media_maxima, situacao,
 * necessaria_razao, monotona, pendentes. Chaves ausentes não são conferidas.
 * 'espera_aviso' / 'espera_erro' conferem substring. 'por_avaliacao' confere
 * a nota necessária por avaliação, no formato titulo => nota|null.
 */

$folha = static function (string $titulo, float $max, ?float $obtida = null, array $extra = []): array {
    return array_merge([
        'tipo'        => 'avaliacao',
        'titulo'      => $titulo,
        'apelido'     => strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $titulo) ?? $titulo),
        'nota_maxima' => $max,
        'nota_obtida' => $obtida,
    ], $extra);
};

return [

    // ---------------------------------------------------------------------
    'enzo: provas 50% somadas + trabalhos 50% somados' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Bioquímica II',
                'regra'  => 'media_ponderada',
                'filhos' => [
                    [
                        'nome'          => 'Provas',
                        'peso'          => 50.0,
                        'regra'         => 'soma_pontos',
                        'pontos_totais' => 20.0,
                        'filhos'        => [
                            $folha('P1', 10.0, 7.0),
                            $folha('P2', 10.0),
                        ],
                    ],
                    [
                        'nome'          => 'Trabalhos',
                        'peso'          => 50.0,
                        'regra'         => 'soma_pontos',
                        'pontos_totais' => 10.0,
                        'filhos'        => [
                            $folha('Seminario', 4.0, 3.5),
                            $folha('Relatorio', 3.0, 3.0),
                            $folha('Artigo', 3.0),
                        ],
                    ],
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 5.0,
            'media_parcial'     => 8.142857,
            'media_maxima'      => 9.0,
            'situacao'          => 'em_aberto',
            'necessaria_razao'  => 0.5,
            'pendentes'         => 2,
        ],
        // P2 sozinha (zerando o Artigo) ainda salva; o Artigo sozinho não salva.
        'por_avaliacao' => ['P2' => 8.0, 'Artigo' => null],
    ],

    // ---------------------------------------------------------------------
    'media simples de 3 provas, uma pendente' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Fisiologia',
                'regra'  => 'media_simples',
                'filhos' => [
                    $folha('P1', 10.0, 8.0),
                    $folha('P2', 10.0, 6.0),
                    $folha('P3', 10.0),
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 4.666667,
            'media_parcial'     => 7.0,
            'media_maxima'      => 8.0,
            'situacao'          => 'em_aberto',
            'necessaria_razao'  => 0.7,
        ],
        'por_avaliacao' => ['P3' => 7.0],
    ],

    // ---------------------------------------------------------------------
    'melhores_n: quatro listas, descarta a pior' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'     => 'Cálculo',
                'regra'    => 'melhores_n',
                'manter_n' => 3,
                'filhos'   => [
                    $folha('L1', 10.0, 10.0),
                    $folha('L2', 10.0, 6.0),
                    $folha('L3', 10.0, 9.0),
                    $folha('L4', 10.0),
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 8.333333,
            'media_parcial'     => 8.333333,
            'media_maxima'      => 9.666667,
            'situacao'          => 'garantida',
            'necessaria_razao'  => 0.0,
        ],
    ],

    // ---------------------------------------------------------------------
    'maior_entre: substitutiva vale a maior nota' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Histologia',
                'regra'  => 'maior_entre',
                'filhos' => [
                    $folha('P1', 10.0, 5.0),
                    $folha('Substitutiva', 10.0),
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 5.0,
            'media_parcial'     => 5.0,
            'media_maxima'      => 10.0,
            'situacao'          => 'em_aberto',
            'necessaria_razao'  => 0.7,
        ],
        'por_avaliacao' => ['Substitutiva' => 7.0],
    ],

    // ---------------------------------------------------------------------
    'soma_bonus: presença soma até 1 ponto' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Genética',
                'regra'  => 'soma_bonus',
                'filhos' => [
                    $folha('Base', 10.0, 6.0),
                    $folha('Participacao', 10.0, null, ['peso' => 10.0]),
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 6.0,
            'media_maxima'      => 7.0,
            'situacao'          => 'em_aberto',
            'necessaria_razao'  => 1.0,
        ],
    ],

    // ---------------------------------------------------------------------
    'expressão do usuário com se()' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'          => 'Estatística',
                'regra'         => 'expressao',
                'pontos_totais' => 10.0,
                'expressao'     => 'se(PROVAS >= 5; PROVAS*0.6 + TRAB*0.4; PROVAS*0.6)',
                'filhos'        => [
                    $folha('PROVAS', 10.0, 6.0),
                    $folha('TRAB', 10.0),
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 3.6,
            'media_maxima'      => 7.6,
            'situacao'          => 'em_aberto',
            'necessaria_razao'  => 0.85,
            'monotona'          => true,
        ],
        'por_avaliacao' => ['TRAB' => 8.5],
    ],

    // ---------------------------------------------------------------------
    'nota mínima no grupo: média boa, mas reprovado nas provas' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Farmacologia',
                'regra'  => 'media_ponderada',
                'filhos' => [
                    [
                        'nome'          => 'Provas',
                        'peso'          => 50.0,
                        'regra'         => 'soma_pontos',
                        'pontos_totais' => 20.0,
                        'nota_minima'   => 5.0,
                        'filhos'        => [
                            $folha('P1', 10.0, 4.0),
                            $folha('P2', 10.0, 4.0),
                        ],
                    ],
                    [
                        'nome'   => 'Trabalhos',
                        'peso'   => 50.0,
                        'regra'  => 'media_simples',
                        'filhos' => [$folha('T1', 10.0, 10.0)],
                    ],
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 7.0,
            'situacao'          => 'garantida',
        ],
        'espera_trava' => 'Provas',
    ],

    // ---------------------------------------------------------------------
    'aprovação matematicamente impossível' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Química Orgânica',
                'regra'  => 'media_simples',
                'filhos' => [
                    $folha('P1', 10.0, 2.0),
                    $folha('P2', 10.0, 3.0),
                    $folha('P3', 10.0),
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 1.666667,
            'media_maxima'      => 5.0,
            'situacao'          => 'impossivel',
            'necessaria_razao'  => null,
        ],
    ],

    // ---------------------------------------------------------------------
    'pesos que não somam 100 são normalizados com aviso' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Imunologia',
                'regra'  => 'media_ponderada',
                'filhos' => [
                    ['nome' => 'A', 'peso' => 60.0, 'regra' => 'media_simples', 'filhos' => [$folha('A1', 10.0, 9.0)]],
                    ['nome' => 'B', 'peso' => 30.0, 'regra' => 'media_simples', 'filhos' => [$folha('B1', 10.0, 6.0)]],
                ],
            ],
        ],
        'esperado'     => ['media_consolidada' => 8.0, 'situacao' => 'garantida'],
        'espera_aviso' => 'os pesos somam',
    ],

    // ---------------------------------------------------------------------
    'avaliação dispensada sai da conta (não é zero nem pendente)' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Anatomia',
                'regra'  => 'media_simples',
                'filhos' => [
                    $folha('P1', 10.0, 8.0),
                    $folha('P2', 10.0, null, ['status' => 'dispensada']),
                    $folha('P3', 10.0, 6.0),
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 7.0,
            'media_maxima'      => 7.0,
            'situacao'          => 'garantida',
            'pendentes'         => 0,
        ],
    ],

    // ---------------------------------------------------------------------
    'aninhamento: bimestres com teórica/prática dentro' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Neuroanatomia',
                'regra'  => 'media_ponderada',
                'filhos' => [
                    [
                        'nome'   => '1º Bimestre',
                        'peso'   => 40.0,
                        'regra'  => 'media_ponderada',
                        'filhos' => [
                            [
                                'nome'   => 'Teórica',
                                'peso'   => 70.0,
                                'regra'  => 'maior_entre',
                                'filhos' => [$folha('P1', 10.0, 7.0), $folha('Sub', 10.0)],
                            ],
                            [
                                'nome'          => 'Prática',
                                'peso'          => 30.0,
                                'regra'         => 'soma_pontos',
                                'pontos_totais' => 10.0,
                                'filhos'        => [$folha('Roteiro', 5.0, 4.0), $folha('Arguicao', 5.0)],
                            ],
                        ],
                    ],
                    [
                        'nome'          => '2º Bimestre',
                        'peso'          => 40.0,
                        'regra'         => 'soma_pontos',
                        'pontos_totais' => 20.0,
                        'filhos'        => [$folha('P3', 10.0), $folha('P4', 10.0)],
                    ],
                    [
                        'nome'   => 'Participação',
                        'peso'   => 20.0,
                        'regra'  => 'media_simples',
                        'filhos' => [$folha('Presenca', 10.0, 9.0)],
                    ],
                ],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 4.24,
            'media_parcial'     => 7.866667,
            'media_maxima'      => 9.68,
            'situacao'          => 'em_aberto',
            'necessaria_razao'  => 0.6,
            'pendentes'         => 4,
        ],
    ],

    // ---------------------------------------------------------------------
    'fórmula não monótona é detectada e desativa a nota necessária' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'          => 'Matéria Torta',
                'regra'         => 'expressao',
                'pontos_totais' => 20.0,
                'expressao'     => 'P1 + 5 - P2',
                'filhos'        => [$folha('P1', 10.0, 5.0), $folha('P2', 10.0)],
            ],
        ],
        'esperado'     => ['monotona' => false, 'necessaria_razao' => null],
        'espera_aviso' => 'não é monótona',
    ],

    // ---------------------------------------------------------------------
    'nada lançado ainda: parcial não existe' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Matéria Nova',
                'regra'  => 'media_simples',
                'filhos' => [$folha('P1', 10.0), $folha('P2', 10.0)],
            ],
        ],
        'esperado' => [
            'media_consolidada' => 0.0,
            'media_parcial'     => null,
            'media_maxima'      => 10.0,
            'situacao'          => 'em_aberto',
            'necessaria_razao'  => 0.7,
            'pendentes'         => 2,
        ],
    ],

    // ---------------------------------------------------------------------
    'nota acima do máximo vira erro' => [
        'materia' => [
            'escala_maxima'   => 10.0,
            'media_aprovacao' => 7.0,
            'raiz' => [
                'nome'   => 'Matéria Errada',
                'regra'  => 'media_simples',
                'filhos' => [$folha('P1', 10.0, 12.0)],
            ],
        ],
        'espera_erro' => 'acima do máximo',
    ],
];
