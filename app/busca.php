<?php

declare(strict_types=1);

require_once __DIR__ . '/pendencias.php';

/**
 * Busca global: dentro do conteúdo das anotações, nos espaços e nas pendências.
 *
 * O dobramento de acento troca um caractere por outro, nunca por dois, então a
 * contagem de CARACTERES não muda. É isso que permite achar a posição no texto
 * dobrado e recortar o trecho no texto original, com acento e maiúscula
 * preservados — usar posição de byte aqui cortaria "ç" no meio.
 */

const BUSCA_TRECHO = 90;
const BUSCA_MAX    = 40;

function dobrar_acentos(string $s): string
{
    return strtr(mb_strtolower($s, 'UTF-8'), [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
}

/**
 * Recorta o pedaço do texto em volta da primeira ocorrência.
 *
 * @return array{trecho: string, inicio: int, tamanho: int}|null
 */
function trecho_em_volta(string $original, string $dobrado, string $alvo): ?array
{
    $pos = mb_strpos($dobrado, $alvo, 0, 'UTF-8');
    if ($pos === false) {
        return null;
    }

    $antes = max(0, $pos - (int) (BUSCA_TRECHO / 3));
    $trecho = mb_substr($original, $antes, BUSCA_TRECHO, 'UTF-8');

    // Quebra de linha vira espaço: o trecho é uma linha só na tela.
    $trecho = trim(preg_replace('/\s+/u', ' ', $trecho) ?? $trecho);

    return [
        'trecho'  => ($antes > 0 ? '…' : '') . $trecho . '…',
        'inicio'  => $pos - $antes,
        'tamanho' => mb_strlen($alvo, 'UTF-8'),
    ];
}

/**
 * @param  array<string, mixed> $b
 * @return array<string, mixed>
 */
function buscar(array $b, string $q): array
{
    $alvo = trim(dobrar_acentos($q));
    if (mb_strlen($alvo, 'UTF-8') < 2) {
        return ['notas' => [], 'espacos' => [], 'pendencias' => [], 'termo' => $q];
    }

    $espacos = [];
    foreach ($b['espacos'] as $e) {
        if (str_contains(dobrar_acentos((string) ($e['nome'] ?? '')), $alvo)) {
            $espacos[] = ['id' => $e['id'], 'nome' => $e['nome'], 'tipo' => $e['tipo'] ?? 'projeto', 'cor' => $e['cor'] ?? null];
        }
    }

    $notas = [];
    foreach ($b['anotacoes'] as $n) {
        if (!empty($n['excluida_em'])) {
            continue;
        }

        $titulo   = (string) ($n['titulo'] ?? '');
        $conteudo = (string) ($n['conteudo'] ?? '');

        $noTitulo   = str_contains(dobrar_acentos($titulo), $alvo);
        $noConteudo = trecho_em_volta($conteudo, dobrar_acentos($conteudo), $alvo);

        if (!$noTitulo && $noConteudo === null) {
            continue;
        }

        $notas[] = [
            'id'         => $n['id'],
            'titulo'     => $titulo !== '' ? $titulo : 'Sem título',
            'espaco_id'  => $n['espaco_id'] ?? null,
            'trecho'     => $noConteudo['trecho'] ?? null,
            'realce'     => $noConteudo ? [$noConteudo['inicio'], $noConteudo['tamanho']] : null,
            // Título vale mais que corpo: quem procura "Krebs" quer a nota
            // chamada Krebs antes de uma que só a cita de passagem.
            'peso'       => ($noTitulo ? 100 : 0) + ($noConteudo ? 10 : 0),
            'atualizado' => $n['atualizado_em'] ?? '',
        ];
    }

    usort($notas, static function (array $x, array $y): int {
        return $x['peso'] === $y['peso']
            ? strcmp((string) $y['atualizado'], (string) $x['atualizado'])
            : $y['peso'] <=> $x['peso'];
    });

    $pend = [];
    foreach (pendencias($b) as $p) {
        if (str_contains(dobrar_acentos($p['texto']), $alvo)) {
            $pend[] = $p;
        }
    }

    return [
        'termo'      => $q,
        'espacos'    => array_slice($espacos, 0, 8),
        'notas'      => array_slice($notas, 0, BUSCA_MAX),
        'pendencias' => array_slice($pend, 0, 12),
    ];
}

/** Primeira linha com conteúdo, sem marcação de título nem lista. */
function primeira_linha(string $texto): string
{
    foreach (preg_split('/\R/u', $texto) ?: [] as $l) {
        $l = trim(preg_replace('/^[#>\-*+\s]+|\[[ xX]\]/u', '', $l) ?? $l);
        if ($l !== '') {
            return mb_substr($l, 0, 90);
        }
    }

    return '';
}

/**
 * O resumo do dia: o que orienta ao abrir o app.
 *
 * @param  array<string, mixed> $b
 * @return array<string, mixed>
 */
function resumo_de_hoje(array $b, string $hoje): array
{
    require_once __DIR__ . '/agenda.php';

    $fim  = (new DateTimeImmutable($hoje))->modify('+14 days')->format('Y-m-d');
    $dias = agenda_entre($b, $hoje, $fim);

    $doDia = $dias[$hoje] ?? ['itens' => [], 'feriados' => null];

    // Provas das próximas duas semanas, que é o horizonte em que dá para agir.
    $proximas = [];
    foreach ($dias as $data => $d) {
        if ($data === $hoje) {
            continue;
        }
        foreach ($d['itens'] as $it) {
            if ($it['origem'] === 'avaliacao' && empty($it['lancada'])) {
                $proximas[] = $it + ['data' => $data, 'faltam' => (int) (new DateTimeImmutable($hoje))->diff(new DateTimeImmutable($data))->days];
            }
        }
    }

    $urgentes = [];
    foreach (pendencias($b) as $p) {
        $atrasada = !empty($p['prazo']) && $p['prazo'] < $hoje;
        if ($p['urgente'] || $atrasada) {
            $p['atrasada'] = $atrasada;
            $urgentes[] = $p;
        }
    }

    $importantes = [];
    foreach ($b['anotacoes'] as $n) {
        if (!empty($n['favorita']) && empty($n['excluida_em'])) {
            $importantes[] = [
                'id'        => $n['id'],
                'titulo'    => ($n['titulo'] ?? '') !== '' ? $n['titulo'] : 'Sem título',
                'espaco_id' => $n['espaco_id'] ?? null,
                // Primeira linha com texto: serve de lembrete do que tem dentro
                // sem precisar abrir a nota.
                'previa'    => primeira_linha((string) ($n['conteudo'] ?? '')),
            ];
        }
    }

    $recentes = array_values(array_filter($b['anotacoes'], static fn ($n) => empty($n['excluida_em'])));
    usort($recentes, static fn ($x, $y) => strcmp((string) ($y['atualizado_em'] ?? ''), (string) ($x['atualizado_em'] ?? '')));

    return [
        'data'      => $hoje,
        'feriados'  => $doDia['feriados'],
        'itens'     => $doDia['itens'],
        'proximas'  => array_slice($proximas, 0, 5),
        'urgentes'    => array_slice($urgentes, 0, 6),
        'importantes' => array_slice($importantes, 0, 8),
        'recentes'  => array_map(
            static fn ($n) => ['id' => $n['id'], 'titulo' => $n['titulo'] ?: 'Sem título', 'espaco_id' => $n['espaco_id'] ?? null],
            array_slice($recentes, 0, 5)
        ),
        'espacos'   => array_values($b['espacos']),
    ];
}
