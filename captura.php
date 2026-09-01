<?php

declare(strict_types=1);

/**
 * Captura rápida: recebe texto de fora e joga na caixa de entrada.
 *
 * Existe porque o app Notas do iOS não tem API pública — não dá para ler nem
 * escrever nele. O caminho que funciona é o contrário: um Atalho no iPhone
 * MANDA o texto para cá, e ele pode ser chamado do botão Compartilhar de
 * qualquer app, inclusive de dentro do próprio Notas.
 *
 * Endpoint público, autenticado só pelo token — o Atalho não faz login.
 * Diferente do feed iCal, este ESCREVE, então é mais sensível: nunca devolve
 * conteúdo, tem limite de tamanho e de frequência.
 */

require __DIR__ . '/app/nucleo.php';

const CAPTURA_BYTES_MAX  = 20000;
const CAPTURA_POR_HORA   = 60;
const NOTA_CAIXA_ENTRADA = 'Caixa de entrada';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function recusar(string $erro, int $codigo): never
{
    http_response_code($codigo);
    echo json_encode(['erro' => $erro]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    recusar('use_post', 405);
}

$token = (string) ($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    recusar('nao_encontrado', 404);
}

$capturas = ler_json(caminho('capturas.json'), []);
$chave    = hash('sha256', $token);
$reg      = $capturas[$chave] ?? null;

// Token de leitura NAO escreve: cada tipo so serve ao endpoint dele.
if (!is_array($reg) || !empty($reg['revogado_em']) || ($reg['tipo'] ?? 'captura') !== 'captura') {
    recusar('nao_encontrado', 404);
}

// Token que escreve merece freio próprio: se vazar, limita o estrago.
$agoraTs = time();
$janela  = array_values(array_filter(
    $reg['batidas'] ?? [],
    static fn ($t) => ($agoraTs - (int) $t) < 3600
));
if (count($janela) >= CAPTURA_POR_HORA) {
    recusar('muitas_capturas', 429);
}

$bruto = file_get_contents('php://input') ?: '';
if (strlen($bruto) > CAPTURA_BYTES_MAX) {
    recusar('grande_demais', 413);
}

// Aceita JSON ou texto puro: o Atalho do iOS manda texto sem cerimônia, e
// exigir JSON dele seria atrito à toa.
$dados = json_decode($bruto, true);
if (is_array($dados)) {
    $texto  = trim((string) ($dados['texto'] ?? $dados['text'] ?? ''));
    $titulo = trim((string) ($dados['titulo'] ?? $dados['title'] ?? ''));
} else {
    $texto  = trim($bruto);
    $titulo = '';
}

if ($texto === '' && $titulo === '') {
    recusar('vazio', 422);
}

$dono = $reg['usuario_id'] ?? USUARIO_PADRAO;
$b    = base_de($dono);

// Com título vira nota própria; sem título, empilha na caixa de entrada.
// Captura rápida quase nunca tem título, e criar dezenas de notas "Sem título"
// transformaria a lista em lixo.
if ($titulo !== '') {
    $id = novo_id();
    $b['anotacoes'][] = [
        'id'            => $id,
        'titulo'        => mb_substr($titulo, 0, 200),
        'conteudo'      => $texto,
        'espaco_id'     => $reg['espaco_id'] ?? null,
        'favorita'      => false,
        'criado_em'     => agora(),
        'atualizado_em' => agora(),
        'excluida_em'   => null,
    ];
} else {
    $linha = '- ' . str_replace(["\r\n", "\r", "\n"], ' ', $texto)
        . '  <!-- ' . gmdate('d/m H:i') . ' -->';

    $achou = false;
    foreach ($b['anotacoes'] as $i => $n) {
        if (($n['titulo'] ?? '') === NOTA_CAIXA_ENTRADA && empty($n['excluida_em'])) {
            // Novo no topo: o que acabou de chegar é o que você vai triar.
            $b['anotacoes'][$i]['conteudo']      = $linha . "\n" . (string) ($n['conteudo'] ?? '');
            $b['anotacoes'][$i]['atualizado_em'] = agora();
            $achou = true;
            break;
        }
    }

    if (!$achou) {
        $b['anotacoes'][] = [
            'id'            => novo_id(),
            'titulo'        => NOTA_CAIXA_ENTRADA,
            'conteudo'      => $linha,
            'espaco_id'     => $reg['espaco_id'] ?? null,
            'favorita'      => true,
            'criado_em'     => agora(),
            'atualizado_em' => agora(),
            'excluida_em'   => null,
        ];
    }
}

if (!escrever_json(caminho_usuario('base.json', $dono), $b)) {
    recusar('escrita', 500);
}

$janela[] = $agoraTs;
$capturas[$chave]['batidas'] = $janela;
$capturas[$chave]['ultimo']  = gmdate('c');
$capturas[$chave]['total']   = (int) ($reg['total'] ?? 0) + 1;
escrever_json(caminho('capturas.json'), $capturas);

// Devolve o mínimo: quem capturou não precisa saber nada da base.
echo json_encode(['ok' => true]);
