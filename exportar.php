<?php

declare(strict_types=1);

/**
 * Saída em texto puro, para um Atalho do iPhone buscar e jogar no app Notas.
 *
 * O Atalhos tem ações nativas do Notas (Criar Nota, Acrescentar à Nota), então
 * o caminho de volta existe — só não existe API para eu escrever lá direto.
 * Quem escreve é o Atalho, rodando no aparelho; aqui só entregamos o texto.
 *
 * É CÓPIA, não sincronização: o que você editar no Notas não volta para cá.
 */

require __DIR__ . '/app/nucleo.php';
require __DIR__ . '/app/busca.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, no-store');

function fim(string $msg, int $codigo): never
{
    http_response_code($codigo);
    echo $msg;
    exit;
}

$token = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    fim('nao encontrado', 404);
}

$tokens = ler_json(caminho('capturas.json'), []);
$reg    = $tokens[hash('sha256', $token)] ?? null;

// Token de captura NAO serve aqui: ele foi prometido como só-escrita, e deixar
// que leia romperia essa promessa sem ninguém perceber.
if (!is_array($reg) || !empty($reg['revogado_em']) || ($reg['tipo'] ?? 'captura') !== 'exportar') {
    fim('nao encontrado', 404);
}

$b = base_de($reg['usuario_id'] ?? USUARIO_PADRAO);

$espacoNome = static function (?string $id) use ($b): string {
    foreach ($b['espacos'] as $e) {
        if (($e['id'] ?? '') === $id) {
            return (string) ($e['nome'] ?? '');
        }
    }

    return '';
};

$o = (string) ($_GET['o'] ?? 'hoje');

switch ($o) {

    case 'nota':
        $id = (string) ($_GET['id'] ?? '');
        foreach ($b['anotacoes'] as $n) {
            if (($n['id'] ?? '') === $id && empty($n['excluida_em'])) {
                echo ($n['titulo'] ?: 'Sem título') . "\n\n" . ($n['conteudo'] ?? '');
                exit;
            }
        }
        fim('nota nao encontrada', 404);

        // no break

    case 'importantes':
        $partes = [];
        foreach ($b['anotacoes'] as $n) {
            if (!empty($n['favorita']) && empty($n['excluida_em'])) {
                $partes[] = ($n['titulo'] ?: 'Sem título') . "\n" . str_repeat('—', 20) . "\n" . ($n['conteudo'] ?? '');
            }
        }
        echo $partes === [] ? 'Nada marcado como importante.' : implode("\n\n\n", $partes);
        exit;

        // no break

    case 'pendencias':
        require_once __DIR__ . '/app/pendencias.php';

        $linhas = [];
        foreach (pendencias($b) as $p) {
            $linhas[] = '- ' . ($p['urgente'] ? '! ' : '') . $p['texto']
                . ($p['prazo'] ? '  (' . date('d/m', strtotime($p['prazo'])) . ')' : '')
                . '  [' . $p['nota'] . ']';
        }
        echo $linhas === [] ? 'Nada pendente.' : "Pendências\n\n" . implode("\n", $linhas);
        exit;

        // no break

    case 'hoje':
    default:
        // A data pode vir do Atalho: o servidor esta em UTC e ja virou o dia
        // enquanto no Brasil ainda e ontem a noite.
        $dia = (string) ($_GET['data'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
            $dia = gmdate('Y-m-d');
        }

        $r = resumo_de_hoje($b, $dia);
        $l = [date('d/m/Y', strtotime($dia))];

        if ($r['feriados']) {
            $l[] = 'Feriado: ' . implode(' · ', array_column($r['feriados'], 'nome'));
        }

        $l[] = '';
        $l[] = 'NO DIA';
        if ($r['itens'] === []) {
            $l[] = '  nada marcado';
        }
        foreach ($r['itens'] as $it) {
            $hora = !empty($it['dia_inteiro']) ? 'dia' : ($it['hora'] ?? '  —');
            $l[]  = '  ' . str_pad($hora, 6) . $it['titulo']
                . (($it['origem'] ?? '') === 'avaliacao' && empty($it['lancada'])
                    ? '  (vale ' . $it['peso_media'] . '% da media)' : '');
        }

        if ($r['urgentes']) {
            $l[] = '';
            $l[] = 'PRECISA DE ATENCAO';
            foreach ($r['urgentes'] as $p) {
                $l[] = '  - ' . ($p['atrasada'] ? '[atrasada] ' : '') . $p['texto'];
            }
        }

        if ($r['proximas']) {
            $l[] = '';
            $l[] = 'VEM AI';
            foreach ($r['proximas'] as $a) {
                $l[] = '  - ' . $a['titulo']
                    . ' em ' . $a['faltam'] . ($a['faltam'] === 1 ? ' dia' : ' dias')
                    . ' (vale ' . $a['peso_media'] . '% da media)'
                    . ($espacoNome($a['espaco_id']) ? ' — ' . $espacoNome($a['espaco_id']) : '');
            }
        }

        echo implode("\n", $l);
        exit;
}
