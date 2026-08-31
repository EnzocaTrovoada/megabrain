<?php

declare(strict_types=1);

require __DIR__ . '/app/nucleo.php';

header('Cache-Control: no-store');

if (!autenticado()) {
    json_saida(['erro' => 'sem_sessao'], 401);
}

$acao = (string) ($_GET['a'] ?? '');

// Toda mutação exige o token da sessão num header próprio. Não basta o cookie:
// SameSite=Lax sozinho não cobre tudo, e header custom não viaja em form cross-site.
$mutacoes = ['materia.salvar', 'materia.excluir', 'nota.salvar', 'nota.excluir', 'arquivo.enviar'];
if (in_array($acao, $mutacoes, true)) {
    $enviado = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($enviado) || !hash_equals(csrf(), $enviado)) {
        json_saida(['erro' => 'csrf'], 403);
    }
}

/** @return array<string, mixed> */
function corpo(): array
{
    $bruto = file_get_contents('php://input') ?: '';
    if ($bruto === '') {
        return [];
    }

    $d = json_decode($bruto, true);
    if (!is_array($d)) {
        // Sem isto, corpo malformado (ex.: bytes que não são UTF-8) chega adiante
        // como campo vazio e o erro sai como "nome vazio", que despista.
        json_saida(['erro' => 'json_invalido', 'detalhe' => json_last_error_msg()], 400);
    }

    return $d;
}

function texto(array $d, string $chave, int $max = 500): string
{
    $v = $d[$chave] ?? '';

    return mb_substr(is_scalar($v) ? trim((string) $v) : '', 0, $max);
}

$b = base();

switch ($acao) {

    case 'estado':
        json_saida([
            'materias'  => array_values($b['materias']),
            'anotacoes' => array_values(array_map(
                // A lista não precisa do corpo das notas; só o editor precisa.
                static fn (array $n): array => array_diff_key($n, ['conteudo' => 1]) + [
                    'tamanho' => mb_strlen((string) ($n['conteudo'] ?? '')),
                ],
                array_filter($b['anotacoes'], static fn ($n) => empty($n['excluida_em']))
            )),
        ]);

        // no break

    case 'nota':
        $id   = texto($_GET, 'id', 24);
        $nota = null;
        foreach ($b['anotacoes'] as $n) {
            if (($n['id'] ?? '') === $id && empty($n['excluida_em'])) {
                $nota = $n;
            }
        }
        if ($nota === null) {
            json_saida(['erro' => 'nao_encontrada'], 404);
        }

        // Backlinks: quem aponta para cá. Calculado na leitura em vez de gravado,
        // então renomear uma nota nunca deixa referência velha para trás.
        $alvo  = chave_titulo((string) ($nota['titulo'] ?? ''));
        $volta = [];
        if ($alvo !== '') {
            foreach ($b['anotacoes'] as $outra) {
                if (($outra['id'] ?? '') === $id || !empty($outra['excluida_em'])) {
                    continue;
                }
                foreach (extrair_links((string) ($outra['conteudo'] ?? '')) as $l) {
                    if (chave_titulo($l) === $alvo) {
                        $volta[] = ['id' => $outra['id'], 'titulo' => $outra['titulo'] ?: 'Sem título'];
                        break;
                    }
                }
            }
        }

        json_saida($nota + ['backlinks' => $volta]);

        // no break

    case 'arquivo.enviar':
        $env = $_FILES['arquivo'] ?? null;
        if (!is_array($env) || ($env['error'] ?? 1) !== UPLOAD_ERR_OK) {
            json_saida(['erro' => 'upload_falhou', 'codigo' => $env['error'] ?? null], 400);
        }
        if (($env['size'] ?? 0) > ARQUIVO_BYTES_MAX) {
            json_saida(['erro' => 'grande_demais', 'limite' => ARQUIVO_BYTES_MAX], 413);
        }
        if (bytes_usados() > QUOTA_BYTES) {
            json_saida(['erro' => 'quota_cheia'], 507);
        }

        // O tipo declarado pelo cliente é forjável; vale o que o arquivo é.
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($env['tmp_name']) ?: '';
        if (!isset(MIMES_ACEITOS[$mime])) {
            json_saida(['erro' => 'tipo_nao_aceito', 'tipo' => $mime], 415);
        }

        $ext  = MIMES_ACEITOS[$mime];
        $proc = reencodar_imagem($env['tmp_name'], $mime, IMAGEM_LADO_MAX);
        if ($proc === null) {
            json_saida(['erro' => 'imagem_invalida'], 422);
        }
        [$tmp, $larg, $alt] = $proc;

        // Endereçado pelo conteúdo: a mesma imagem enviada duas vezes ocupa
        // espaço uma vez, e o nome no disco nunca vem do cliente.
        $hash = hash_file('sha256', $tmp);
        $id   = substr($hash, 0, 16);
        $indice = indice_arquivos();

        $destino = caminho_arquivo($hash, $ext);
        if (!is_file($destino)) {
            if (!@rename($tmp, $destino)) {
                @unlink($tmp);
                json_saida(['erro' => 'escrita'], 500);
            }

            $mini = reencodar_imagem($destino, $mime, MINIATURA_LADO);
            if ($mini !== null) {
                @rename($mini[0], caminho_arquivo($hash, $ext, true));
            }
        } else {
            @unlink($tmp);
        }

        $indice[$id] = [
            'hash'      => $hash,
            'ext'       => $ext,
            'mime'      => $mime,
            'bytes'     => filesize($destino) ?: 0,
            'largura'   => $larg,
            'altura'    => $alt,
            'nome'      => mb_substr((string) ($env['name'] ?? 'imagem'), 0, 120),
            'criado_em' => agora(),
        ];
        salvar_indice_arquivos($indice);

        json_saida(['ok' => true, 'id' => $id, 'largura' => $larg, 'altura' => $alt]);

        // no break

    case 'arquivo':
        $id  = texto($_GET, 'id', 32);
        $reg = indice_arquivos()[$id] ?? null;
        if (!is_array($reg)) {
            http_response_code(404);
            exit;
        }

        $mini = ($_GET['t'] ?? '') === '1';
        $arq  = caminho_arquivo($reg['hash'], $reg['ext'], $mini);
        if ($mini && !is_file($arq)) {
            $arq = caminho_arquivo($reg['hash'], $reg['ext']);
        }
        if (!is_file($arq)) {
            http_response_code(404);
            exit;
        }

        // Tipo vem do índice, nunca do que foi enviado. inline é seguro aqui
        // porque só existe imagem reencodada nesta pasta.
        header('Content-Type: ' . $reg['mime']);
        header('Content-Length: ' . filesize($arq));
        header('Content-Disposition: inline; filename="imagem.' . $reg['ext'] . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=31536000, immutable');
        readfile($arq);
        exit;

        // no break

    case 'grafo':
        $vivas = array_values(array_filter(
            $b['anotacoes'],
            static fn ($n) => empty($n['excluida_em'])
        ));

        // Índice título -> id. Título repetido: vence o primeiro, como o Obsidian.
        $porTitulo = [];
        foreach ($vivas as $n) {
            $k = chave_titulo((string) ($n['titulo'] ?? ''));
            if ($k !== '' && !isset($porTitulo[$k])) {
                $porTitulo[$k] = $n['id'];
            }
        }

        $nos = [];
        $arestas = [];
        $fantasmas = [];

        // Matéria é nó de primeira classe. No Obsidian o grafo nasce vazio até
        // você linkar tudo à mão; aqui matéria->nota já é uma ligação real, então
        // o mapa nasce com estrutura em vez de uma nuvem de pontos soltos.
        foreach ($b['materias'] as $m) {
            $nos[] = [
                'id'     => 'm:' . $m['id'],
                'rotulo' => $m['nome'],
                'tipo'   => 'materia',
                'cor'    => $m['cor'] ?? '#8b5cf6',
                'ref'    => $m['id'],
            ];
        }

        foreach ($vivas as $n) {
            $nos[] = [
                'id'      => 'n:' . $n['id'],
                'rotulo'  => ($n['titulo'] ?? '') !== '' ? $n['titulo'] : 'Sem título',
                'tipo'    => 'nota',
                'materia' => $n['materia_id'] ?? null,
                'ref'     => $n['id'],
            ];

            if (!empty($n['materia_id'])) {
                $arestas[] = ['de' => 'm:' . $n['materia_id'], 'para' => 'n:' . $n['id'], 'tipo' => 'materia'];
            }

            foreach (extrair_links((string) ($n['conteudo'] ?? '')) as $bruto) {
                $k = chave_titulo($bruto);

                if (isset($porTitulo[$k])) {
                    if ($porTitulo[$k] !== $n['id']) {
                        $arestas[] = ['de' => 'n:' . $n['id'], 'para' => 'n:' . $porTitulo[$k], 'tipo' => 'link'];
                    }
                    continue;
                }

                // Link para nota que ainda não existe. Vira nó fantasma: é o
                // rascunho do que você ainda vai escrever, e some quando escrever.
                $fid = 'f:' . substr(md5($k), 0, 10);
                $fantasmas[$fid] = ['id' => $fid, 'rotulo' => $bruto, 'tipo' => 'fantasma'];
                $arestas[] = ['de' => 'n:' . $n['id'], 'para' => $fid, 'tipo' => 'link'];
            }
        }

        json_saida([
            'nos'     => array_merge($nos, array_values($fantasmas)),
            'arestas' => $arestas,
        ]);

        // no break

    case 'materia.salvar':
        $d    = corpo();
        $id   = texto($d, 'id', 24);
        $nome = texto($d, 'nome', 120);

        if ($nome === '') {
            json_saida(['erro' => 'nome_vazio'], 422);
        }

        $achou = false;
        foreach ($b['materias'] as $i => $m) {
            if (($m['id'] ?? '') === $id && $id !== '') {
                $b['materias'][$i]['nome'] = $nome;
                $b['materias'][$i]['cor']  = texto($d, 'cor', 9) ?: ($m['cor'] ?? '#8b5cf6');
                $achou = true;
                $id    = $m['id'];
            }
        }

        if (!$achou) {
            $id = novo_id();
            $b['materias'][] = [
                'id'        => $id,
                'nome'      => $nome,
                'cor'       => texto($d, 'cor', 9) ?: '#8b5cf6',
                'criado_em' => agora(),
            ];
        }

        if (!salvar_base($b)) {
            json_saida(['erro' => 'escrita'], 500);
        }
        json_saida(['ok' => true, 'id' => $id]);

        // no break

    case 'materia.excluir':
        $id = texto(corpo(), 'id', 24);

        $b['materias'] = array_values(array_filter(
            $b['materias'],
            static fn ($m) => ($m['id'] ?? '') !== $id
        ));
        // Anotação órfã continua existindo: perder matéria não pode perder texto.
        foreach ($b['anotacoes'] as $i => $n) {
            if (($n['materia_id'] ?? null) === $id) {
                $b['anotacoes'][$i]['materia_id'] = null;
            }
        }

        json_saida(['ok' => salvar_base($b)]);

        // no break

    case 'nota.salvar':
        $d  = corpo();
        $id = texto($d, 'id', 24);

        $titulo   = texto($d, 'titulo', 200);
        $conteudo = (string) ($d['conteudo'] ?? '');
        if (mb_strlen($conteudo) > 400000) {
            json_saida(['erro' => 'grande_demais'], 413);
        }
        $materia = texto($d, 'materia_id', 24) ?: null;

        $achou = false;
        foreach ($b['anotacoes'] as $i => $n) {
            if (($n['id'] ?? '') === $id && $id !== '') {
                $b['anotacoes'][$i]['titulo']        = $titulo;
                $b['anotacoes'][$i]['conteudo']      = $conteudo;
                $b['anotacoes'][$i]['materia_id']    = $materia;
                $b['anotacoes'][$i]['atualizado_em'] = agora();
                $achou = true;
            }
        }

        if (!$achou) {
            $id = novo_id();
            $b['anotacoes'][] = [
                'id'            => $id,
                'titulo'        => $titulo,
                'conteudo'      => $conteudo,
                'materia_id'    => $materia,
                'criado_em'     => agora(),
                'atualizado_em' => agora(),
                'excluida_em'   => null,
            ];
        }

        json_saida(salvar_base($b) ? ['ok' => true, 'id' => $id, 'em' => agora()] : ['erro' => 'escrita']);

        // no break

    case 'nota.excluir':
        $id = texto(corpo(), 'id', 24);

        // Exclusão lógica: dá pra recuperar direto no JSON se você se arrepender.
        foreach ($b['anotacoes'] as $i => $n) {
            if (($n['id'] ?? '') === $id) {
                $b['anotacoes'][$i]['excluida_em'] = agora();
            }
        }

        json_saida(['ok' => salvar_base($b)]);

        // no break

    default:
        json_saida(['erro' => 'acao_desconhecida'], 400);
}
