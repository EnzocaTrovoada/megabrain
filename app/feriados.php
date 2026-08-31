<?php

declare(strict_types=1);

/**
 * Feriados da cidade do Rio de Janeiro.
 *
 * Nada de lista fixa ano a ano: metade das datas é móvel, presa à Páscoa, e uma
 * tabela chumbada vira mentira em janeiro. Aqui a Páscoa é calculada e o resto
 * sai dela por deslocamento de dias.
 *
 * Fontes das datas fixas conferidas em 2026-08-31. Consciência Negra é feriado
 * nacional desde a Lei 14.759/2023 — antes disso era municipal no Rio, então
 * calendários antigos discordam.
 */

/** feriado = fecha por lei · facultativo = não fecha por lei, mas não tem aula */
const FERIADO_NACIONAL   = 'nacional';
const FERIADO_ESTADUAL   = 'estadual';
const FERIADO_MUNICIPAL  = 'municipal';
const FERIADO_FACULTATIVO = 'facultativo';

/**
 * Domingo de Páscoa pelo algoritmo gregoriano anônimo (Meeus/Jones/Butcher).
 *
 * Feito na mão de propósito: easter_date() do PHP exige a extensão calendar,
 * que não está habilitada na Hostinger. São dez linhas contra uma dependência
 * que pode não existir no servidor.
 */
function domingo_de_pascoa(int $ano): string
{
    $a = $ano % 19;
    $b = intdiv($ano, 100);
    $c = $ano % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);

    $mes = intdiv($h + $l - 7 * $m + 114, 31);
    $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

function dia_mais(string $data, int $dias): string
{
    return (new DateTimeImmutable($data))->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
}

/**
 * Todos os feriados e pontos facultativos do ano, para a cidade do Rio.
 *
 * O valor é uma LISTA porque duas datas podem coincidir: em 2028 a Quarta-feira
 * de Cinzas cai em 1º de março, o mesmo dia do aniversário da cidade. Guardar
 * um só por data faria um feriado apagar o outro sem ninguém perceber.
 *
 * @return array<string, list<array{nome: string, tipo: string}>>  indexado por Y-m-d
 */
function feriados_do_ano(int $ano): array
{
    $pascoa = domingo_de_pascoa($ano);

    $fixos = [
        '01-01' => ['Confraternização Universal', FERIADO_NACIONAL],
        '04-21' => ['Tiradentes', FERIADO_NACIONAL],
        '05-01' => ['Dia do Trabalho', FERIADO_NACIONAL],
        '09-07' => ['Independência do Brasil', FERIADO_NACIONAL],
        '10-12' => ['Nossa Senhora Aparecida', FERIADO_NACIONAL],
        '11-02' => ['Finados', FERIADO_NACIONAL],
        '11-15' => ['Proclamação da República', FERIADO_NACIONAL],
        '11-20' => ['Consciência Negra', FERIADO_NACIONAL],
        '12-25' => ['Natal', FERIADO_NACIONAL],

        '04-23' => ['São Jorge', FERIADO_ESTADUAL],

        '01-20' => ['São Sebastião, padroeiro da cidade', FERIADO_MUNICIPAL],
        '03-01' => ['Aniversário da cidade do Rio', FERIADO_MUNICIPAL],

        '10-28' => ['Dia do Servidor Público', FERIADO_FACULTATIVO],
        '12-24' => ['Véspera de Natal', FERIADO_FACULTATIVO],
        '12-31' => ['Véspera de Ano-Novo', FERIADO_FACULTATIVO],
    ];

    $lista = [];
    foreach ($fixos as $md => [$nome, $tipo]) {
        $lista[$ano . '-' . $md][] = ['nome' => $nome, 'tipo' => $tipo];
    }

    // Móveis. Carnaval e Corpus Christi são facultativos por lei, mas não tem
    // aula em nenhum deles — o que importa aqui é a aula, não o texto da lei.
    $moveis = [
        [-48, 'Carnaval (segunda)',    FERIADO_FACULTATIVO],
        [-47, 'Carnaval (terça)',      FERIADO_FACULTATIVO],
        [-46, 'Quarta-feira de Cinzas', FERIADO_FACULTATIVO],
        [-2,  'Sexta-feira Santa',     FERIADO_NACIONAL],
        [60,  'Corpus Christi',        FERIADO_FACULTATIVO],
    ];

    foreach ($moveis as [$desloc, $nome, $tipo]) {
        $lista[dia_mais($pascoa, $desloc)][] = ['nome' => $nome, 'tipo' => $tipo];
    }

    ksort($lista);

    return $lista;
}

/**
 * Feriados dentro de um intervalo, atravessando a virada de ano.
 *
 * @return array<string, list<array{nome: string, tipo: string}>>
 */
function feriados_entre(string $de, string $ate): array
{
    $saida = [];

    for ($ano = (int) substr($de, 0, 4); $ano <= (int) substr($ate, 0, 4); $ano++) {
        foreach (feriados_do_ano($ano) as $data => $doDia) {
            if ($data >= $de && $data <= $ate) {
                $saida[$data] = $doDia;
            }
        }
    }

    ksort($saida);

    return $saida;
}

/**
 * Tipo predominante do dia: um feriado de verdade vence um ponto facultativo.
 * Serve para a tela decidir com que força marcar a data.
 */
function tipo_do_dia(array $doDia): string
{
    foreach ([FERIADO_NACIONAL, FERIADO_ESTADUAL, FERIADO_MUNICIPAL] as $forte) {
        foreach ($doDia as $f) {
            if ($f['tipo'] === $forte) {
                return $forte;
            }
        }
    }

    return FERIADO_FACULTATIVO;
}

/**
 * Cancela aula? Feriado e ponto facultativo cancelam — Carnaval e Corpus
 * Christi não são feriado por lei, mas aula não tem em nenhum dos dois.
 *
 * @return list<array{nome: string, tipo: string}>|null
 */
function dia_sem_aula(string $data): ?array
{
    return feriados_do_ano((int) substr($data, 0, 4))[$data] ?? null;
}
