<?php

declare(strict_types=1);

/**
 * Núcleo: sessão, armazenamento e limites. Sem banco por enquanto — tudo em JSON
 * fora do public_html. É deliberado: dá pra usar hoje, e o formato dos dados já
 * é o mesmo que vai pro MySQL depois (mesmas chaves, mesmos ids).
 */

const ARQUIVO_CODIGO_SETUP = 'CODIGO-DE-INSTALACAO.txt';

// A Hostinger vem com display_errors ligado. Stack trace na tela vaza caminho
// absoluto do servidor e trecho de código: erro vai pro log, nunca pro navegador.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

const COOKIE_SESSAO   = 'mb_sessao';
const SESSAO_DIAS     = 30;
const TENTATIVAS_MAX  = 8;
const TENTATIVAS_MIN  = 15;   // janela, em minutos

/**
 * Pasta de dados. Preferimos um nível ACIMA do public_html: nada de conteúdo
 * seu servido direto pelo LiteSpeed. Se não der pra escrever lá, caímos para
 * dentro do público — mas aí o .htaccess é a única defesa, e avisamos.
 */
function raiz_dados(): string
{
    static $raiz = null;
    if ($raiz !== null) {
        return $raiz;
    }

    $fora = dirname(__DIR__, 2) . '/dados';
    if (is_dir($fora) || @mkdir($fora, 0700, true) || is_dir($fora)) {
        if (is_writable($fora)) {
            return $raiz = $fora;
        }
    }

    $dentro = dirname(__DIR__) . '/dados';
    if (!is_dir($dentro)) {
        @mkdir($dentro, 0700, true);
    }
    // Cinto e suspensório: se caiu aqui, pelo menos negue por .htaccess.
    $ht = $dentro . '/.htaccess';
    if (is_dir($dentro) && !is_file($ht)) {
        @file_put_contents($ht, "Require all denied\n");
    }

    return $raiz = $dentro;
}

function dados_fora_do_publico(): bool
{
    return !str_starts_with(raiz_dados(), dirname(__DIR__) . DIRECTORY_SEPARATOR)
        && !str_starts_with(raiz_dados(), dirname(__DIR__) . '/');
}

function caminho(string $rel): string
{
    return raiz_dados() . '/' . ltrim($rel, '/');
}

// ------------------------------------------------------------------ json

/** @return array<string, mixed> */
function ler_json(string $arquivo, array $padrao = []): array
{
    if (!is_file($arquivo)) {
        return $padrao;
    }
    $txt = @file_get_contents($arquivo);
    if ($txt === false || $txt === '') {
        return $padrao;
    }
    $dados = json_decode($txt, true);

    return is_array($dados) ? $dados : $padrao;
}

/** Escrita atômica: grava num temporário e renomeia, para nunca deixar meio-arquivo. */
function escrever_json(string $arquivo, array $dados): bool
{
    $pasta = dirname($arquivo);
    if (!is_dir($pasta) && !@mkdir($pasta, 0700, true) && !is_dir($pasta)) {
        return false;
    }

    $tmp = $arquivo . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok  = @file_put_contents(
        $tmp,
        json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    if ($ok === false) {
        @unlink($tmp);

        return false;
    }

    return @rename($tmp, $arquivo);
}

// ------------------------------------------------------------- instalação

/**
 * Código de instalação, gerado no servidor e gravado em dados/ na primeira
 * visita. Fica FORA do código-fonte de propósito: assim o repositório pode ser
 * público sem publicar junto a chave que abre a instalação de quem clonar.
 *
 * Só quem tem acesso aos arquivos do servidor consegue ler — que é exatamente
 * o mesmo nível de acesso de quem deveria poder instalar. É o padrão de token
 * de instalador.
 */
function codigo_setup(): string
{
    $arq = caminho(ARQUIVO_CODIGO_SETUP);

    // O arquivo é escrito para humano ler, então o código vem atrás de um
    // marcador em vez de ser o conteúdo inteiro.
    if (is_file($arq)) {
        $txt = (string) file_get_contents($arq);
        if (preg_match('/^CODIGO:\s*(\S+)\s*$/m', $txt, $m)) {
            return $m[1];
        }
    }

    $codigo = strtr(rtrim(base64_encode(random_bytes(12)), '='), '+/', 'AB');
    @file_put_contents(
        $arq,
        "Codigo de instalacao do Megabrain\n"
        . "=================================\n\n"
        . "CODIGO: " . $codigo . "\n\n"
        . "Digite esse codigo na tela de instalacao do site.\n"
        . "Assim que a instalacao terminar, este arquivo e apagado sozinho.\n"
    );

    return $codigo;
}

function config(): ?array
{
    $c = ler_json(caminho('config.json'));

    return isset($c['senha_hash']) ? $c : null;
}

function instalado(): bool
{
    return config() !== null;
}

function instalar(string $codigo, string $senha): string|true
{
    if (instalado()) {
        return 'Este servidor já foi instalado.';
    }
    if (!hash_equals(codigo_setup(), trim($codigo))) {
        usleep(400000);

        return 'Código de instalação incorreto.';
    }
    if (strlen($senha) < 10) {
        return 'A senha precisa ter pelo menos 10 caracteres.';
    }

    $ok = escrever_json(caminho('config.json'), [
        'senha_hash' => password_hash($senha, PASSWORD_DEFAULT),
        'criado_em'  => gmdate('c'),
    ]);

    if ($ok) {
        // Instalado: o código não abre mais nada e não tem por que continuar existindo.
        @unlink(caminho(ARQUIVO_CODIGO_SETUP));
    }

    return $ok ? true : 'Não consegui escrever em ' . raiz_dados() . '. Confira as permissões da pasta.';
}

// ------------------------------------------------------------------ sessão

/**
 * Em produção o .htaccess força HTTPS, então isto é sempre true lá. Detectamos
 * em vez de fixar só para o servidor embutido do PHP (http) funcionar em teste;
 * cookie com Secure nunca é enviado em http e o login não fecharia o ciclo.
 */
function em_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function impressao_ip(): string
{
    return substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|megabrain'), 0, 32);
}

function sessoes(): array
{
    return ler_json(caminho('sessoes.json'), []);
}

function autenticado(): bool
{
    return sessao_atual() !== null;
}

function sessao_atual(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }

    $token = $_COOKIE[COOKIE_SESSAO] ?? '';
    if (!is_string($token) || strlen($token) !== 64) {
        return $cache = null;
    }

    $chave = hash('sha256', $token);
    $todas = sessoes();
    $s     = $todas[$chave] ?? null;

    if (!is_array($s) || ($s['expira'] ?? 0) < time()) {
        return $cache = null;
    }

    // Expiração deslizante, com teto absoluto já gravado na criação.
    if (($s['expira'] - time()) < (SESSAO_DIAS * 86400 * 0.8)) {
        $todas[$chave]['expira'] = min(
            time() + SESSAO_DIAS * 86400,
            (int) ($s['absoluto'] ?? PHP_INT_MAX)
        );
        escrever_json(caminho('sessoes.json'), $todas);
    }

    return $cache = $s;
}

function criar_sessao(): void
{
    $token = bin2hex(random_bytes(32));
    $todas = sessoes();

    foreach ($todas as $k => $s) {           // faxina oportunista
        if (($s['expira'] ?? 0) < time()) {
            unset($todas[$k]);
        }
    }

    $todas[hash('sha256', $token)] = [
        'expira'   => time() + SESSAO_DIAS * 86400,
        'absoluto' => time() + 90 * 86400,
        'csrf'     => bin2hex(random_bytes(16)),
        'ip'       => impressao_ip(),
        'criado'   => time(),
    ];

    escrever_json(caminho('sessoes.json'), $todas);

    setcookie(COOKIE_SESSAO, $token, [
        'expires'  => time() + SESSAO_DIAS * 86400,
        'path'     => '/',
        'secure'   => em_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function destruir_sessao(): void
{
    $token = $_COOKIE[COOKIE_SESSAO] ?? '';
    if (is_string($token) && $token !== '') {
        $todas = sessoes();
        unset($todas[hash('sha256', $token)]);
        escrever_json(caminho('sessoes.json'), $todas);
    }

    setcookie(COOKIE_SESSAO, '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => em_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function csrf(): string
{
    return (string) (sessao_atual()['csrf'] ?? '');
}

// ------------------------------------------------------- limite de tentativas

function tentativas_bloqueado(): int
{
    $log   = ler_json(caminho('tentativas.json'), []);
    $corte = time() - TENTATIVAS_MIN * 60;
    $ip    = impressao_ip();
    $n     = 0;

    foreach ($log as $t) {
        if (($t['ts'] ?? 0) >= $corte && ($t['ip'] ?? '') === $ip) {
            $n++;
        }
    }

    return $n >= TENTATIVAS_MAX ? TENTATIVAS_MIN : 0;
}

function registrar_tentativa(): void
{
    $log   = ler_json(caminho('tentativas.json'), []);
    $corte = time() - TENTATIVAS_MIN * 60;

    $log = array_values(array_filter($log, static fn ($t) => ($t['ts'] ?? 0) >= $corte));
    $log[] = ['ts' => time(), 'ip' => impressao_ip()];

    escrever_json(caminho('tentativas.json'), $log);
}

function limpar_tentativas(): void
{
    escrever_json(caminho('tentativas.json'), []);
}

// ------------------------------------------------------------------- base

/** Base do usuário. Um arquivo só: simples, atômico, e some fácil no backup. */
function base(): array
{
    return ler_json(caminho('base.json'), [
        'versao'    => 1,
        'materias'  => [],
        'anotacoes' => [],
    ]);
}

function salvar_base(array $b): bool
{
    $b['versao'] = 1;

    return escrever_json(caminho('base.json'), $b);
}

function novo_id(): string
{
    return substr(bin2hex(random_bytes(8)), 0, 12);
}

function agora(): string
{
    return gmdate('c');
}

// -------------------------------------------------------------- arquivos

const IMAGEM_LADO_MAX  = 1600;   // px no maior lado
const MINIATURA_LADO   = 360;
const ARQUIVO_BYTES_MAX = 12582912;  // 12 MB por arquivo, antes de reprocessar
const QUOTA_BYTES      = 524288000;  // 500 MB no total

/** Aceita só o que dá pra reencodar. Reencodar é a defesa; sem ela, não entra. */
const MIMES_ACEITOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

function pasta_arquivos(): string
{
    $p = caminho('arquivos');
    if (!is_dir($p)) {
        @mkdir($p, 0700, true);
    }

    return $p;
}

/** Caminho no disco a partir do hash, com dois níveis de pasta. */
function caminho_arquivo(string $hash, string $ext, bool $miniatura = false): string
{
    $sub = substr($hash, 0, 2) . '/' . substr($hash, 2, 2);
    $dir = pasta_arquivos() . '/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    return $dir . '/' . $hash . ($miniatura ? '.min' : '') . '.' . $ext;
}

function indice_arquivos(): array
{
    return ler_json(caminho('arquivos.json'), []);
}

function salvar_indice_arquivos(array $i): bool
{
    return escrever_json(caminho('arquivos.json'), $i);
}

function bytes_usados(): int
{
    $total = 0;
    foreach (indice_arquivos() as $a) {
        $total += (int) ($a['bytes'] ?? 0);
    }

    return $total;
}

/**
 * Reencoda a imagem via GD. É aqui que mora a segurança: um "png" com PHP
 * embutido não sobrevive a decodificar e recodificar. De quebra, some o EXIF
 * (que carrega coordenada de GPS) e a foto de 4000px do celular vira algo
 * que carrega no wifi da faculdade.
 *
 * @return array{0: string, 1: int, 2: int}|null  [caminho temporário, largura, altura]
 */
function reencodar_imagem(string $origem, string $mime, int $ladoMax): ?array
{
    $img = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($origem),
        'image/png'  => @imagecreatefrompng($origem),
        'image/webp' => @imagecreatefromwebp($origem),
        default      => null,
    };

    if (!$img) {
        return null;
    }

    $l = imagesx($img);
    $a = imagesy($img);

    if (max($l, $a) > $ladoMax) {
        $escala = $ladoMax / max($l, $a);
        $novo   = imagescale($img, (int) round($l * $escala), (int) round($a * $escala));
        if ($novo) {
            imagedestroy($img);
            $img = $novo;
            $l   = imagesx($img);
            $a   = imagesy($img);
        }
    }

    $tmp = caminho('.tmp-' . bin2hex(random_bytes(6)));

    // PNG e WEBP mantêm transparência; JPEG não tem alfa para preservar.
    if ($mime === 'image/jpeg') {
        $ok = imagejpeg($img, $tmp, 82);
    } elseif ($mime === 'image/webp') {
        $ok = imagewebp($img, $tmp, 82);
    } else {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $ok = imagepng($img, $tmp, 6);
    }

    imagedestroy($img);

    if (!$ok) {
        @unlink($tmp);

        return null;
    }

    return [$tmp, $l, $a];
}

// -------------------------------------------------------------- ligações

/**
 * Chave de comparação de título: minúscula e sem acento. "Ciclo de Krebs",
 * "ciclo de krebs" e "Ciclo de Krebs " apontam para a mesma nota — em português
 * exigir acento exato faria metade dos links falhar em silêncio.
 */
function chave_titulo(string $t): string
{
    $t = mb_strtolower(trim($t), 'UTF-8');

    return strtr($t, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
}

/**
 * Extrai os alvos de [[link]] do texto. Aceita [[Título|como aparece]],
 * igual ao Obsidian: o alvo é o que vem antes da barra.
 *
 * @return list<string>
 */
function extrair_links(string $conteudo): array
{
    if (!preg_match_all('/\[\[([^\]\r\n]{1,200})\]\]/u', $conteudo, $m)) {
        return [];
    }

    $saida = [];
    foreach ($m[1] as $bruto) {
        $alvo = trim(explode('|', $bruto, 2)[0]);
        if ($alvo !== '') {
            $saida[$alvo] = true;
        }
    }

    return array_keys($saida);
}

// ------------------------------------------------------------------ saída

function json_saida(mixed $dados, int $codigo = 200): never
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cabecalhos_seguranca(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header(
        "Content-Security-Policy: default-src 'none'; "
        . "script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "connect-src 'self'; manifest-src 'self'; base-uri 'none'; form-action 'self'"
    );
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
