<?php

declare(strict_types=1);

require_once __DIR__ . '/feriados.php';

/**
 * Monta a agenda de um intervalo de datas.
 *
 * Rotina não é gravada dia a dia: guardamos a regra e expandimos na leitura.
 * Seis disciplinas com duas aulas por semana dariam ~216 linhas de lixo por
 * semestre, que ainda teriam de ser reescritas a cada mudança de horário.
 *
 * O cruzamento que importa mora aqui: rotina acadêmica não aparece em dia sem
 * aula. Um calendário que mostra aula em feriado é pior do que nenhum, porque
 * você para de confiar nele.
 */

const DIAS_SEMANA = ['seg' => 1, 'ter' => 2, 'qua' => 4, 'qui' => 8, 'sex' => 16, 'sab' => 32, 'dom' => 64];

/** Bit do dia da semana de uma data. date('N') dá 1=segunda .. 7=domingo. */
function bit_do_dia(string $data): int
{
    return 1 << ((int) (new DateTimeImmutable($data))->format('N') - 1);
}

/** Teto de janela para uma expansão não virar laço infinito por parâmetro torto. */
const AGENDA_DIAS_MAX = 400;

/**
 * @param  array<string, mixed> $b  base do usuário
 * @return array<string, mixed>     indexado por Y-m-d
 */
function agenda_entre(array $b, string $de, string $ate): array
{
    $inicio = new DateTimeImmutable($de);
    $fim    = new DateTimeImmutable($ate);

    if ($fim < $inicio) {
        return [];
    }
    if ((int) $inicio->diff($fim)->days > AGENDA_DIAS_MAX) {
        $fim = $inicio->modify('+' . AGENDA_DIAS_MAX . ' days');
    }

    $feriados = feriados_entre($inicio->format('Y-m-d'), $fim->format('Y-m-d'));

    // Exceções indexadas por rotina+data, para o custo não crescer com o tempo.
    $excecoes = [];
    foreach ($b['rotina_excecoes'] as $e) {
        $excecoes[($e['rotina_id'] ?? '') . '|' . ($e['data'] ?? '')] = $e;
    }

    $porData = [];
    foreach ($b['compromissos'] as $c) {
        if (!empty($c['data'])) {
            $porData[$c['data']][] = $c;
        }
    }

    $avaliacoes = avaliacoes_por_data($b);

    $dias = [];
    for ($d = $inicio; $d <= $fim; $d = $d->modify('+1 day')) {
        $data = $d->format('Y-m-d');
        $doDia = $feriados[$data] ?? null;

        $itens = [];

        foreach ($b['rotinas'] as $r) {
            if (!(($r['dias_semana'] ?? 0) & bit_do_dia($data))) {
                continue;
            }
            if (!empty($r['inicio']) && $data < $r['inicio']) {
                continue;
            }
            if (!empty($r['fim']) && $data > $r['fim']) {
                continue;
            }

            $ex = $excecoes[($r['id'] ?? '') . '|' . $data] ?? null;
            if ($ex && ($ex['acao'] ?? '') === 'cancelada') {
                continue;
            }

            // Aula pula feriado; academia não. Feriado não fecha academia.
            if ($doDia !== null && ($r['tipo'] ?? 'pessoal') === 'academica') {
                continue;
            }

            $itens[] = [
                'origem'     => 'rotina',
                'ref'        => $r['id'] ?? null,
                'titulo'     => $r['titulo'] ?? '',
                'tipo'       => $r['tipo'] ?? 'pessoal',
                'hora'       => $ex['nova_hora'] ?? ($r['hora_inicio'] ?? null),
                'hora_fim'   => $r['hora_fim'] ?? null,
                'local'      => $r['local'] ?? null,
                'materia_id' => $r['materia_id'] ?? null,
                'movida'     => $ex !== null,
            ];
        }

        foreach ($porData[$data] ?? [] as $c) {
            $itens[] = [
                'origem'     => 'compromisso',
                'ref'        => $c['id'] ?? null,
                'titulo'     => $c['titulo'] ?? '',
                'hora'       => $c['hora'] ?? null,
                'materia_id' => $c['materia_id'] ?? null,
                'concluido'  => !empty($c['concluido']),
            ];
        }

        foreach ($avaliacoes[$data] ?? [] as $a) {
            $itens[] = $a;
        }

        // Sem hora vai para o fim: compromisso do dia não disputa com horário.
        usort($itens, static function (array $x, array $y): int {
            $hx = $x['hora'] ?? '99:99';
            $hy = $y['hora'] ?? '99:99';

            return $hx === $hy ? strcmp((string) $x['titulo'], (string) $y['titulo']) : strcmp($hx, $hy);
        });

        $dias[$data] = [
            'data'     => $data,
            'semana'   => (int) $d->format('N'),
            'feriados' => $doDia,
            'sem_aula' => $doDia !== null,
            'itens'    => $itens,
        ];
    }

    return $dias;
}

/**
 * Avaliações com data marcada, já carregando o peso que têm na média.
 *
 * É o que faz a prova de quinta aparecer como "P2 — vale 25% da média" em vez
 * de só "P2". As duas features já existiam; só faltava cruzá-las.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function avaliacoes_por_data(array $b): array
{
    $saida = [];

    foreach ($b['materias'] as $m) {
        if (empty($m['raiz'])) {
            continue;
        }
        coletar_avaliacoes_datadas($m['raiz'], $m, 1.0, $saida);
    }

    return $saida;
}

/**
 * Desce a árvore multiplicando a fração que cada nó representa da média final.
 *
 * @param array<string, list<array<string, mixed>>> $saida
 */
function coletar_avaliacoes_datadas(array $no, array $materia, float $fracao, array &$saida): void
{
    if (($no['tipo'] ?? 'grupo') === 'avaliacao') {
        if (empty($no['data_prevista']) || ($no['status'] ?? '') === 'dispensada') {
            return;
        }

        $saida[$no['data_prevista']][] = [
            'origem'     => 'avaliacao',
            'ref'        => $no['id'] ?? null,
            'titulo'     => $no['titulo'] ?? 'Avaliação',
            'hora'       => $no['hora'] ?? null,
            'materia_id' => $materia['id'] ?? null,
            'peso_media' => round($fracao * 100, 1),
            'lancada'    => ($no['nota_obtida'] ?? null) !== null,
        ];

        return;
    }

    $filhos = array_values($no['filhos'] ?? []);
    if ($filhos === []) {
        return;
    }

    // Em soma de pontos o peso é o próprio ponto; nas outras regras é o peso.
    $porPontos = ($no['regra'] ?? '') === 'soma_pontos';

    $total = 0.0;
    foreach ($filhos as $f) {
        $total += $porPontos
            ? (float) ($f['nota_maxima'] ?? $f['pontos_totais'] ?? 0)
            : (float) ($f['peso'] ?? 1);
    }

    // Quando o grupo declara quantos pontos vale, é esse o denominador — e não
    // a soma dos filhos já cadastrados. Sem isto, "os trabalhos juntos valem 10"
    // com um trabalho lançado faria esse trabalho parecer valer o grupo inteiro.
    if ($porPontos && ($no['pontos_totais'] ?? null) !== null) {
        $total = (float) $no['pontos_totais'];
    }

    foreach ($filhos as $f) {
        $parte = $porPontos
            ? (float) ($f['nota_maxima'] ?? $f['pontos_totais'] ?? 0)
            : (float) ($f['peso'] ?? 1);

        coletar_avaliacoes_datadas($f, $materia, $total > 0 ? $fracao * ($parte / $total) : 0.0, $saida);
    }
}
