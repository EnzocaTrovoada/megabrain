<?php

declare(strict_types=1);

/**
 * Pendências: as caixas "- [ ]" espalhadas pelas anotações, reunidas.
 *
 * Nada de lista de tarefas separada. Você já escreve a tarefa no meio da
 * anotação da aula, no contexto em que ela nasceu — duplicar isso numa segunda
 * lista significaria manter as duas em dia, e uma delas ficaria para trás.
 *
 * A anotação continua sendo a fonte da verdade: marcar aqui reescreve o "[ ]"
 * da nota, não um registro paralelo.
 *
 *   - [ ] revisar o ciclo de Krebs
 *   - [ ] ! entregar o relatório          (o ! marca urgente)
 *   - [ ] ! prova de bioquímica @2026-09-10   (o @data joga na agenda)
 */

/**
 * @param  array<string, mixed> $b
 * @return list<array<string, mixed>>
 */
function pendencias(array $b, bool $incluirFeitas = false): array
{
    $saida = [];

    foreach ($b['anotacoes'] as $n) {
        if (!empty($n['excluida_em'])) {
            continue;
        }

        foreach (tarefas_do_texto((string) ($n['conteudo'] ?? '')) as $t) {
            if ($t['feita'] && !$incluirFeitas) {
                continue;
            }

            $saida[] = $t + [
                'nota_id'    => $n['id'] ?? null,
                'nota'       => ($n['titulo'] ?? '') !== '' ? $n['titulo'] : 'Sem título',
                'espaco_id' => $n['espaco_id'] ?? null,
            ];
        }
    }

    // Urgente primeiro; depois com prazo, do mais próximo; depois o resto.
    usort($saida, static function (array $x, array $y): int {
        if ($x['urgente'] !== $y['urgente']) {
            return $x['urgente'] ? -1 : 1;
        }
        if (($x['prazo'] ?? null) !== ($y['prazo'] ?? null)) {
            if (empty($x['prazo'])) {
                return 1;
            }
            if (empty($y['prazo'])) {
                return -1;
            }

            return strcmp($x['prazo'], $y['prazo']);
        }

        return 0;
    });

    return $saida;
}

/**
 * @return list<array{linha: int, texto: string, bruto: string, feita: bool, urgente: bool, prazo: ?string}>
 */
function tarefas_do_texto(string $conteudo): array
{
    $linhas = preg_split('/\r\n|\r|\n/', $conteudo) ?: [];
    $saida  = [];

    foreach ($linhas as $i => $linha) {
        if (!preg_match('/^\s*[-*+]\s+\[( |x|X)\]\s*(.*)$/u', $linha, $m)) {
            continue;
        }

        $texto = trim($m[2]);
        if ($texto === '') {
            continue;
        }

        $urgente = false;
        if (preg_match('/^!+\s*/u', $texto)) {
            $urgente = true;
            $texto   = trim(preg_replace('/^!+\s*/u', '', $texto) ?? $texto);
        }

        $prazo = null;
        if (preg_match('/@(\d{4}-\d{2}-\d{2})/u', $texto, $p)) {
            $prazo = $p[1];
            $texto = trim(str_replace($p[0], '', $texto));
        }

        $saida[] = [
            'linha'   => $i,
            'texto'   => $texto,
            'bruto'   => $linha,
            'feita'   => strtolower($m[1]) === 'x',
            'urgente' => $urgente,
            'prazo'   => $prazo,
        ];
    }

    return $saida;
}

/**
 * Marca ou desmarca a caixa direto no texto da anotação.
 *
 * Casa pelo número da linha E pelo conteúdo dela: se a nota mudou desde que a
 * tela carregou, a linha certa pode ter andado, e marcar a errada seria pior
 * do que não marcar. Sem casar as duas coisas, não mexe.
 */
function marcar_tarefa(string $conteudo, int $linha, string $bruto, bool $feita): ?string
{
    $linhas = preg_split('/\r\n|\r|\n/', $conteudo) ?: [];

    if (!isset($linhas[$linha]) || $linhas[$linha] !== $bruto) {
        return null;
    }

    $linhas[$linha] = preg_replace(
        '/^(\s*[-*+]\s+)\[( |x|X)\]/u',
        '$1[' . ($feita ? 'x' : ' ') . ']',
        $linhas[$linha],
        1
    );

    return implode("\n", $linhas);
}

/**
 * Tarefas com @data, no formato que a agenda consome.
 *
 * @return array<string, list<array<string, mixed>>>
 */
function pendencias_por_data(array $b): array
{
    $saida = [];

    foreach (pendencias($b) as $p) {
        if (empty($p['prazo'])) {
            continue;
        }

        $saida[$p['prazo']][] = [
            'origem'     => 'pendencia',
            'ref'        => $p['nota_id'],
            'titulo'     => $p['texto'],
            'hora'       => null,
            'espaco_id' => $p['espaco_id'],
            'urgente'    => $p['urgente'],
            'nota'       => $p['nota'],
        ];
    }

    return $saida;
}
