<?php

declare(strict_types=1);

/**
 * Roda os casos de testes/fixtures.php contra a CalculadoraMedia.
 *
 * No terminal:  php bin/testar.php
 * No navegador: suba a pasta e abra bin/testar.php (mostra o mesmo relatório em HTML).
 *
 * Sai com código 1 se qualquer caso falhar, então serve de gate antes de subir.
 */

// Em produção a Hostinger desliga display_errors, e erro fatal vira página em
// branco. Como isto é ferramenta de teste, queremos o erro na tela, sempre.
if (PHP_SAPI !== 'cli') {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

foreach (['/../src/Servico/Expressao.php', '/../src/Servico/CalculadoraMedia.php'] as $rel) {
    $alvo = __DIR__ . $rel;
    if (!is_file($alvo)) {
        http_response_code(500);
        exit('Arquivo faltando no servidor: ' . $alvo . "\nSuba a pasta inteira, preservando src/, testes/ e bin/.\n");
    }
    require $alvo;
}

use SegundoCerebro\Servico\CalculadoraMedia;

const TOLERANCIA = 1e-4;

$web = PHP_SAPI !== 'cli';
if ($web) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><meta charset=utf-8><title>Testes da calculadora</title>";
    echo "<style>body{background:#111;color:#ddd;font:14px/1.6 ui-monospace,Consolas,monospace;padding:2rem}"
       . "b{color:#fff}.ok{color:#5cd67a}.f{color:#ff6b6b}.d{color:#888}</style><pre>";
}

/** Formata número para comparação legível. */
function n(mixed $v): string
{
    return $v === null ? 'null' : (is_bool($v) ? ($v ? 'true' : 'false') : sprintf('%.6f', (float) $v));
}

function iguais(mixed $a, mixed $b): bool
{
    if ($a === null || $b === null) {
        return $a === $b;
    }
    if (is_bool($a) || is_bool($b)) {
        return (bool) $a === (bool) $b;
    }
    if (is_string($a) || is_string($b)) {
        return (string) $a === (string) $b;
    }

    return abs((float) $a - (float) $b) < TOLERANCIA;
}

$casos = require __DIR__ . '/../testes/fixtures.php';
$calc  = new CalculadoraMedia();

$totalCasos = 0;
$okCasos    = 0;
$falhas     = [];

foreach ($casos as $nome => $caso) {
    $totalCasos++;
    $res      = $calc->calcular($caso['materia']);
    $problems = [];

    foreach (($caso['esperado'] ?? []) as $chave => $esperado) {
        $obtido = $res[$chave] ?? null;
        if (!iguais($esperado, $obtido)) {
            $problems[] = sprintf('%s: esperado %s, obtido %s', $chave, n($esperado), n($obtido));
        }
    }

    if (isset($caso['por_avaliacao'])) {
        $porTitulo = [];
        foreach ($res['por_avaliacao'] as $pa) {
            $porTitulo[$pa['titulo']] = $pa['viavel'] ? $pa['nota'] : null;
        }
        foreach ($caso['por_avaliacao'] as $titulo => $esperado) {
            if (!array_key_exists($titulo, $porTitulo)) {
                $problems[] = sprintf('por_avaliacao["%s"] não foi calculada', $titulo);
                continue;
            }
            if (!iguais($esperado, $porTitulo[$titulo])) {
                $problems[] = sprintf(
                    'por_avaliacao["%s"]: esperado %s, obtido %s',
                    $titulo,
                    n($esperado),
                    n($porTitulo[$titulo])
                );
            }
        }
    }

    foreach (['espera_aviso' => 'avisos', 'espera_erro' => 'erros'] as $chave => $campo) {
        if (!isset($caso[$chave])) {
            continue;
        }
        $achou = false;
        foreach ($res[$campo] as $msg) {
            if (str_contains($msg, $caso[$chave])) {
                $achou = true;
                break;
            }
        }
        if (!$achou) {
            $problems[] = sprintf('esperava %s contendo "%s"; veio: %s', $campo, $caso[$chave], json_encode($res[$campo], JSON_UNESCAPED_UNICODE));
        }
    }

    if (isset($caso['espera_trava'])) {
        $nomes = array_column($res['travas'], 'nome');
        if (!in_array($caso['espera_trava'], $nomes, true)) {
            $problems[] = sprintf('esperava trava em "%s"; travas: %s', $caso['espera_trava'], json_encode($nomes, JSON_UNESCAPED_UNICODE));
        }
    }

    // Erro não anunciado é falha: fórmula que quebra em silêncio é o pior caso.
    if (!isset($caso['espera_erro']) && $res['erros'] !== []) {
        $problems[] = 'erros inesperados: ' . json_encode($res['erros'], JSON_UNESCAPED_UNICODE);
    }

    if ($problems === []) {
        $okCasos++;
        linha('ok', $nome, resumo($res));
    } else {
        $falhas[$nome] = $problems;
        linha('f', $nome, resumo($res));
        foreach ($problems as $p) {
            linha('d', '    ' . $p, '');
        }
    }
}

function resumo(array $r): string
{
    return sprintf(
        'hoje %s · parcial %s · máx %s · %s%s',
        $r['media_consolidada'] === null ? '—' : number_format((float) $r['media_consolidada'], 2, ',', ''),
        $r['media_parcial'] === null ? '—' : number_format((float) $r['media_parcial'], 2, ',', ''),
        $r['media_maxima'] === null ? '—' : number_format((float) $r['media_maxima'], 2, ',', ''),
        (string) $r['situacao'],
        $r['necessaria_pct'] === null ? '' : sprintf(' · precisa %s%%', number_format((float) $r['necessaria_pct'], 1, ',', ''))
    );
}

function linha(string $classe, string $texto, string $extra): void
{
    global $web;

    $marca = match ($classe) {
        'ok' => 'PASSOU',
        'f'  => 'FALHOU',
        default => '      ',
    };

    if ($web) {
        printf(
            "<span class=\"%s\">%s</span>  %s%s\n",
            $classe,
            $marca,
            htmlspecialchars($texto, ENT_QUOTES, 'UTF-8'),
            $extra === '' ? '' : '  <span class="d">' . htmlspecialchars($extra, ENT_QUOTES, 'UTF-8') . '</span>'
        );

        return;
    }

    printf("%s  %s%s\n", $marca, $texto, $extra === '' ? '' : '  |  ' . $extra);
}

echo "\n";
linha(
    $falhas === [] ? 'ok' : 'f',
    sprintf('%d/%d casos passaram', $okCasos, $totalCasos),
    ''
);

if ($web) {
    echo '</pre>';
}

exit($falhas === [] ? 0 : 1);
