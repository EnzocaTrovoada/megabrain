<?php

/**
 * Diagnóstico de ambiente.
 *
 * Escrito em sintaxe antiga DE PROPÓSITO (PHP 5.4+): se o servidor estiver numa
 * versão velha demais para a calculadora, este arquivo ainda roda e consegue
 * dizer isso. Um script moderno daria erro de parse e página em branco — que é
 * exatamente o sintoma que estamos investigando.
 *
 * Abra no navegador: .../bin/diagnostico.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "DIAGNOSTICO - Segundo Cerebro\n";
echo str_repeat('=', 60) . "\n\n";

echo "AMBIENTE\n";
echo "  PHP             : " . PHP_VERSION . "\n";
echo "  SAPI            : " . PHP_SAPI . "\n";
echo "  display_errors  : " . var_export((bool) ini_get('display_errors'), true) . "\n";
echo "  memory_limit    : " . ini_get('memory_limit') . "\n";
echo "  upload_max      : " . ini_get('upload_max_filesize') . "\n";
echo "  post_max        : " . ini_get('post_max_size') . "\n";
echo "\n";

echo "CAMINHOS\n";
echo "  este arquivo    : " . __FILE__ . "\n";
echo "  esta pasta      : " . __DIR__ . "\n";
echo "  document root   : " . (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '-') . "\n";
echo "  URL pedida      : " . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-') . "\n";
echo "  host            : " . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '-') . "\n";
echo "\n";

$precisa = version_compare(PHP_VERSION, '8.0.0', '>=');
echo "REQUISITO\n";
echo "  PHP 8.0 ou mais : " . ($precisa ? 'OK' : 'FALHOU - a calculadora nao roda nesta versao') . "\n";
if (!$precisa) {
    echo "                    Troque a versao no hPanel (Avancado > Configuracao PHP).\n";
}
echo "\n";

echo "EXTENSOES (as que o projeto vai querer)\n";
$exts = array('json', 'mbstring', 'pdo_mysql', 'fileinfo', 'gd', 'openssl', 'curl');
foreach ($exts as $ext) {
    echo '  ' . str_pad($ext, 16) . (extension_loaded($ext) ? 'OK' : 'ausente') . "\n";
}
echo "\n";

echo "ARQUIVOS DO PROJETO\n";
$base  = dirname(__DIR__);
$alvos = array(
    'src/Servico/CalculadoraMedia.php',
    'src/Servico/Expressao.php',
    'testes/fixtures.php',
    'bin/testar.php',
);
$faltou = false;
foreach ($alvos as $rel) {
    $p  = $base . '/' . $rel;
    $ok = file_exists($p);
    if (!$ok) {
        $faltou = true;
    }
    echo '  ' . ($ok ? 'OK    ' : 'FALTA ') . str_pad($rel, 36);
    echo $ok ? ('(' . filesize($p) . ' bytes)') : '';
    echo "\n";
}
echo "\n";

echo "CONTEUDO DE " . $base . "\n";
$itens = @scandir($base);
if ($itens === false) {
    echo "  (nao consegui listar esta pasta)\n";
} else {
    foreach ($itens as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        echo '  ' . (is_dir($base . '/' . $f) ? '[dir] ' : '      ') . $f . "\n";
    }
}
echo "\n";

echo str_repeat('=', 60) . "\n";
if ($precisa && !$faltou) {
    echo "Tudo no lugar. Abra bin/testar.php.\n";
} elseif ($faltou) {
    echo "Faltam arquivos. Suba a pasta inteira preservando src/, testes/ e bin/.\n";
} else {
    echo "Ajuste a versao do PHP no hPanel e recarregue esta pagina.\n";
}
