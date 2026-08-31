<?php

declare(strict_types=1);

require_once __DIR__ . '/agenda.php';

/**
 * Gera o arquivo .ics que o celular assina.
 *
 * As ocorrências são expandidas aqui em vez de sair como RRULE + EXDATE. RRULE
 * seria menor, mas aula que pula feriado viraria uma lista de exceções que cada
 * app de calendário interpreta de um jeito. Expandido, todo mundo mostra igual.
 *
 * Horário vai como hora local flutuante (sem Z e sem TZID): o RFC 5545 permite,
 * e evita ter que embutir um VTIMEZONE inteiro. O celular mostra no fuso dele,
 * que é o mesmo em que a aula acontece.
 */

const ICAL_DIAS_ANTES  = 30;
const ICAL_DIAS_DEPOIS = 365;

/** Escapa os caracteres que são separadores no formato. */
function ical_texto(string $s): string
{
    return str_replace(
        ['\\', ';', ',', "\r\n", "\n", "\r"],
        ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'],
        $s
    );
}

/**
 * Dobra a linha em 75 octetos, como manda o RFC.
 *
 * O corte é por byte, então recua até a fronteira do caractere UTF-8 — cortar
 * um "ç" no meio produz um arquivo que o iPhone recusa inteiro, sem dizer por quê.
 */
function ical_dobrar(string $linha): string
{
    if (strlen($linha) <= 75) {
        return $linha;
    }

    $partes = [];
    $limite = 75;

    while (strlen($linha) > $limite) {
        $corte = $limite;
        while ($corte > 1 && (ord($linha[$corte]) & 0xC0) === 0x80) {
            $corte--;
        }

        $partes[] = substr($linha, 0, $corte);
        $linha    = substr($linha, $corte);
        $limite   = 74;   // continuação começa com espaço, que conta no limite
    }

    $partes[] = $linha;

    return implode("\r\n ", $partes);
}

/** UID estável: o mesmo evento precisa manter identidade entre sincronizações. */
function ical_uid(string $semente, string $host): string
{
    return substr(hash('sha256', $semente), 0, 32) . '@' . $host;
}

/**
 * @param  array<string, mixed> $b        base do usuário
 * @param  array<string, mixed> $escopo   feriados, tipos incluídos, títulos genéricos
 */
function ical_gerar(array $b, array $escopo, string $host): string
{
    $hoje = new DateTimeImmutable(gmdate('Y-m-d'));
    $de   = $hoje->modify('-' . ICAL_DIAS_ANTES . ' days')->format('Y-m-d');
    $ate  = $hoje->modify('+' . ICAL_DIAS_DEPOIS . ' days')->format('Y-m-d');

    $dias      = agenda_entre($b, $de, $ate);
    $materias  = [];
    foreach ($b['materias'] as $m) {
        $materias[$m['id']] = $m['nome'] ?? '';
    }

    $generico = !empty($escopo['titulos_genericos']);
    $tipos    = $escopo['tipos'] ?? ['avaliacao', 'compromisso', 'rotina', 'pendencia'];
    $comFeriado = !empty($escopo['feriados']);

    $agora = gmdate('Ymd\THis\Z');
    $l = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Megabrain//Agenda//PT-BR',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . ical_texto('Megabrain'),
        'X-WR-TIMEZONE:America/Sao_Paulo',
        // Pede ao cliente que reconsulte a cada 3h em vez do padrão dele.
        'REFRESH-INTERVAL;VALUE=DURATION:PT3H',
        'X-PUBLISHED-TTL:PT3H',
    ];

    foreach ($dias as $data => $dia) {
        $compacta = str_replace('-', '', $data);

        if ($comFeriado && $dia['feriados']) {
            foreach ($dia['feriados'] as $i => $f) {
                $l[] = 'BEGIN:VEVENT';
                $l[] = 'UID:' . ical_uid('feriado|' . $data . '|' . $i, $host);
                $l[] = 'DTSTAMP:' . $agora;
                $l[] = 'DTSTART;VALUE=DATE:' . $compacta;
                $l[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d'));
                $l[] = 'SUMMARY:' . ical_texto($f['nome']);
                $l[] = 'TRANSP:TRANSPARENT';
                $l[] = 'END:VEVENT';
            }
        }

        foreach ($dia['itens'] as $n => $it) {
            if (!in_array($it['origem'], $tipos, true)) {
                continue;
            }

            $materia = $materias[$it['materia_id'] ?? ''] ?? null;

            if ($generico) {
                $titulo = match ($it['origem']) {
                    'avaliacao'    => 'Avaliação' . ($materia ? ' — ' . $materia : ''),
                    'rotina'       => 'Compromisso',
                    default        => 'Tarefa',
                };
            } else {
                $titulo = $it['titulo'];
                if ($it['origem'] === 'avaliacao' && $materia) {
                    $titulo = $materia . ' — ' . $titulo;
                }
            }

            $descricao = [];
            if (!$generico && $it['origem'] === 'avaliacao' && empty($it['lancada'])) {
                $descricao[] = 'Vale ' . $it['peso_media'] . '% da média final.';
            }
            if (!$generico && $materia && $it['origem'] !== 'avaliacao') {
                $descricao[] = $materia;
            }

            $l[] = 'BEGIN:VEVENT';
            $l[] = 'UID:' . ical_uid($it['origem'] . '|' . ($it['ref'] ?? $n) . '|' . $data, $host);
            $l[] = 'DTSTAMP:' . $agora;

            if (!empty($it['hora'])) {
                $l[] = 'DTSTART:' . $compacta . 'T' . str_replace(':', '', $it['hora']) . '00';

                // Sem hora de fim NAO inventamos duracao. O RFC aceita DTSTART
                // sozinho, e o calendario mostra so o horario de inicio; supor
                // uma hora encheria a agenda de blocos que nunca existiram.
                if (!empty($it['hora_fim'])) {
                    $l[] = 'DTEND:' . $compacta . 'T' . str_replace(':', '', $it['hora_fim']) . '00';
                }
            } else {
                $l[] = 'DTSTART;VALUE=DATE:' . $compacta;
                $l[] = 'DTEND;VALUE=DATE:' . str_replace('-', '', (new DateTimeImmutable($data))->modify('+1 day')->format('Y-m-d'));
            }

            $l[] = 'SUMMARY:' . ical_texto($titulo);
            if ($descricao !== []) {
                $l[] = 'DESCRIPTION:' . ical_texto(implode(' ', $descricao));
            }
            if (!$generico && !empty($it['local'])) {
                $l[] = 'LOCATION:' . ical_texto($it['local']);
            }
            if ($it['origem'] === 'avaliacao') {
                // Um aviso na véspera: a prova é o que não pode ser esquecido.
                $l[] = 'BEGIN:VALARM';
                $l[] = 'TRIGGER:-P1D';
                $l[] = 'ACTION:DISPLAY';
                $l[] = 'DESCRIPTION:' . ical_texto($titulo);
                $l[] = 'END:VALARM';
            }
            $l[] = 'END:VEVENT';
        }
    }

    $l[] = 'END:VCALENDAR';

    return implode("\r\n", array_map('ical_dobrar', $l)) . "\r\n";
}
