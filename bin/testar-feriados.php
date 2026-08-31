<?php

declare(strict_types=1);

/**
 * Confere os feriados contra datas verificadas fora do código.
 *
 * A Páscoa é a raiz de cinco datas; se ela errar, o calendário inteiro erra
 * junto e em silêncio. Por isso ela é testada em vários anos, incluindo os
 * extremos (Páscoa mais cedo e mais tarde possível no século).
 */

require __DIR__ . '/../app/feriados.php';

$web = PHP_SAPI !== 'cli';
if ($web) {
    header('Content-Type: text/plain; charset=utf-8');
}

$falhas = 0;
$ok     = 0;

function conferir(string $rotulo, $esperado, $obtido): void
{
    global $falhas, $ok;

    if ($esperado === $obtido) {
        $ok++;
        printf("PASSOU  %s\n", $rotulo);

        return;
    }

    $falhas++;
    printf("FALHOU  %s\n          esperado: %s\n          obtido:   %s\n", $rotulo, var_export($esperado, true), var_export($obtido, true));
}

// Domingo de Páscoa — datas de referência independentes do código.
$pascoas = [
    2023 => '2023-04-09',
    2024 => '2024-03-31',
    2025 => '2025-04-20',
    2026 => '2026-04-05',
    2027 => '2027-03-28',
    2028 => '2028-04-16',
    2030 => '2030-04-21',
    2038 => '2038-04-25',   // a mais tarde possível
    2035 => '2035-03-25',   // das mais cedo
];

foreach ($pascoas as $ano => $esperado) {
    conferir("Páscoa de $ano", $esperado, domingo_de_pascoa($ano));
}

// 2026 conferido contra fontes públicas em 2026-08-31.
$f2026 = feriados_do_ano(2026);

$esperados2026 = [
    '2026-01-01' => 'Confraternização Universal',
    '2026-01-20' => 'São Sebastião, padroeiro da cidade',
    '2026-02-16' => 'Carnaval (segunda)',
    '2026-02-17' => 'Carnaval (terça)',
    '2026-02-18' => 'Quarta-feira de Cinzas',
    '2026-03-01' => 'Aniversário da cidade do Rio',
    '2026-04-03' => 'Sexta-feira Santa',
    '2026-04-21' => 'Tiradentes',
    '2026-04-23' => 'São Jorge',
    '2026-05-01' => 'Dia do Trabalho',
    '2026-06-04' => 'Corpus Christi',
    '2026-09-07' => 'Independência do Brasil',
    '2026-10-12' => 'Nossa Senhora Aparecida',
    '2026-11-02' => 'Finados',
    '2026-11-15' => 'Proclamação da República',
    '2026-11-20' => 'Consciência Negra',
    '2026-12-25' => 'Natal',
];

foreach ($esperados2026 as $data => $nome) {
    conferir("$data e $nome", $nome, $f2026[$data][0]['nome'] ?? '(ausente)');
}

// Carnaval 2027: Páscoa em 28/03, então terça de Carnaval em 09/02.
$f2027 = feriados_do_ano(2027);
conferir('Carnaval 2027 cai em 09/02', 'Carnaval (terça)', $f2027['2027-02-09'][0]['nome'] ?? '(ausente)');
conferir('Corpus Christi 2027 cai em 27/05', 'Corpus Christi', $f2027['2027-05-27'][0]['nome'] ?? '(ausente)');

// Tipos
conferir('Consciência Negra é nacional', FERIADO_NACIONAL, $f2026['2026-11-20'][0]['tipo'] ?? null);
conferir('São Jorge é estadual', FERIADO_ESTADUAL, $f2026['2026-04-23'][0]['tipo'] ?? null);
conferir('São Sebastião é municipal', FERIADO_MUNICIPAL, $f2026['2026-01-20'][0]['tipo'] ?? null);
conferir('Corpus Christi é facultativo', FERIADO_FACULTATIVO, $f2026['2026-06-04'][0]['tipo'] ?? null);

// Intervalo atravessando a virada do ano
$virada = feriados_entre('2026-12-20', '2027-01-25');
conferir('intervalo pega o Natal de 2026', true, isset($virada['2026-12-25']));
conferir('intervalo pega o Ano-Novo de 2027', true, isset($virada['2027-01-01']));
conferir('intervalo pega São Sebastião de 2027', true, isset($virada['2027-01-20']));
conferir('intervalo não vaza para fevereiro', false, isset($virada['2027-02-09']));

// Em 2028 a Quarta-feira de Cinzas cai em 1o de marco, junto com o aniversario
// da cidade. Sao dois feriados no mesmo dia: nenhum pode sumir.
$f2028 = feriados_do_ano(2028);
conferir('2028 tem dois feriados em 01/03', 2, count($f2028['2028-03-01'] ?? []));
conferir(
    '2028 mantem o aniversario da cidade',
    true,
    in_array('Aniversário da cidade do Rio', array_column($f2028['2028-03-01'] ?? [], 'nome'), true)
);
conferir(
    '2028 mantem a Quarta-feira de Cinzas',
    true,
    in_array('Quarta-feira de Cinzas', array_column($f2028['2028-03-01'] ?? [], 'nome'), true)
);
conferir('dia com dois feriados vale como municipal', FERIADO_MUNICIPAL, tipo_do_dia($f2028['2028-03-01']));

// Nenhum feriado pode sumir em ano nenhum: 15 fixos + 5 moveis.
foreach ([2025, 2026, 2027, 2028, 2029, 2030] as $ano) {
    $total = 0;
    foreach (feriados_do_ano($ano) as $doDia) {
        $total += count($doDia);
    }
    conferir("$ano tem 20 feriados", 20, $total);
}

printf("\n%s  %d/%d conferências passaram\n", $falhas === 0 ? 'PASSOU' : 'FALHOU', $ok, $ok + $falhas);

exit($falhas === 0 ? 0 : 1);
