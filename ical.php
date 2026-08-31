<?php

declare(strict_types=1);

/**
 * Endpoint público do feed. Sem sessão: quem assina é o servidor do Google ou
 * da Apple, que não tem como fazer login.
 *
 * Por isso o token é a única chave — e por isso ele é próprio do feed, não o
 * de sessão. Vazar este link entrega leitura da agenda; não entrega a conta,
 * não permite escrever nada, e você revoga sem trocar de senha.
 */

require __DIR__ . '/app/nucleo.php';
require __DIR__ . '/app/ical.php';

$token = (string) ($_GET['t'] ?? '');

// Formato conferido antes de tocar o disco: assim uma varredura de bots não
// vira leitura de arquivo a cada tentativa.
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404);
    exit;
}

$feeds  = ler_json(caminho('feeds.json'), []);
$chave  = hash('sha256', $token);
$feed   = $feeds[$chave] ?? null;

if (!is_array($feed) || !empty($feed['revogado_em'])) {
    http_response_code(404);
    exit;
}

// Registro de acesso: é o que permite ver depois que um feed esquecido continua
// sendo consultado, ou de onde. Gravado no máximo de hora em hora para não
// escrever em disco a cada batida do Google.
$agoraTs = time();
if (($agoraTs - (int) ($feed['ultimo_acesso_ts'] ?? 0)) > 3600) {
    $feeds[$chave]['ultimo_acesso_ts'] = $agoraTs;
    $feeds[$chave]['ultimo_acesso']    = gmdate('c');
    $feeds[$chave]['ultimo_ip']        = impressao_ip();
    $feeds[$chave]['acessos']          = (int) ($feed['acessos'] ?? 0) + 1;
    escrever_json(caminho('feeds.json'), $feeds);
}

$ics = ical_gerar(base(), $feed['escopo'] ?? [], (string) ($_SERVER['HTTP_HOST'] ?? 'megabrain'));

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="megabrain.ics"');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: private, max-age=1800');

echo $ics;
