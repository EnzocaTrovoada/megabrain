<?php

declare(strict_types=1);

/**
 * Recuperação de senha sem e-mail.
 *
 * SMTP em hospedagem compartilhada é a peça que mais falha em silêncio:
 * credencial errada, porta bloqueada, mensagem caindo em spam. Numa instalação
 * de meia dúzia de pessoas isso é muita máquina para pouco caso de uso.
 *
 * São dois caminhos, e nenhum depende de rede externa:
 *
 *   - Membro esqueceu: pede ao dono, que gera um link e manda por onde quiser.
 *   - Dono esqueceu: o servidor grava um código num arquivo dentro de dados/,
 *     que só quem tem acesso ao servidor consegue ler. Mesmo padrão do código
 *     de instalação, que ele já conhece.
 *
 * A máquina de token abaixo é a mesma que um envio por e-mail usaria — trocar
 * a entrega depois não mexe em nada disto.
 */

const RECUPERACAO_MINUTOS = 30;
const ARQUIVO_RECUPERACAO = 'RECUPERAR-SENHA.txt';
const RECUPERACAO_POR_HORA = 5;

function recuperacoes(): array
{
    return ler_json(caminho('recuperacoes.json'), []);
}

/**
 * Cria um token de troca de senha. Devolve o código em claro — ele não é
 * guardado: no disco fica só o hash.
 */
function criar_recuperacao(string $usuarioId, string $origem): string
{
    $codigo = strtoupper(bin2hex(random_bytes(5)));
    $lista  = recuperacoes();

    // Faxina: token vencido não precisa continuar ocupando espaço nem risco.
    foreach ($lista as $k => $r) {
        if (strtotime((string) ($r['expira_em'] ?? '')) < time()) {
            unset($lista[$k]);
        }
    }

    $lista[hash('sha256', $codigo)] = [
        'usuario_id' => $usuarioId,
        'origem'     => $origem,
        'criado_em'  => agora(),
        'expira_em'  => gmdate('c', time() + RECUPERACAO_MINUTOS * 60),
    ];

    escrever_json(caminho('recuperacoes.json'), $lista);

    return $codigo;
}

/** Quantos pedidos partiram deste IP na última hora. */
function recuperacoes_recentes(): int
{
    $log   = ler_json(caminho('recuperacoes-log.json'), []);
    $corte = time() - 3600;
    $ip    = impressao_ip();

    return count(array_filter(
        $log,
        static fn ($t) => ($t['ts'] ?? 0) >= $corte && ($t['ip'] ?? '') === $ip
    ));
}

function registrar_pedido_recuperacao(): void
{
    $log   = ler_json(caminho('recuperacoes-log.json'), []);
    $corte = time() - 3600;

    $log = array_values(array_filter($log, static fn ($t) => ($t['ts'] ?? 0) >= $corte));
    $log[] = ['ts' => time(), 'ip' => impressao_ip()];

    escrever_json(caminho('recuperacoes-log.json'), $log);
}

/**
 * O dono esqueceu a senha: grava o código num arquivo dentro de dados/.
 *
 * Quem consegue ler esse arquivo já tem acesso aos arquivos do servidor — ou
 * seja, ao mesmo nível de acesso de quem poderia trocar a senha na mão. Não se
 * está entregando nada que já não estivesse ao alcance.
 */
function pedir_recuperacao_do_dono(): true|array
{
    if (recuperacoes_recentes() >= RECUPERACAO_POR_HORA) {
        return ['erro' => 'Pedidos demais. Espere uma hora.'];
    }

    $dono = null;
    foreach (usuarios() as $u) {
        if (($u['papel'] ?? '') === PAPEL_DONO && !empty($u['ativo'])) {
            $dono = $u;
            break;
        }
    }

    if ($dono === null) {
        return ['erro' => 'Nenhum dono ativo nesta instalação.'];
    }

    registrar_pedido_recuperacao();
    $codigo = criar_recuperacao((string) $dono['id'], 'arquivo');

    @file_put_contents(
        caminho(ARQUIVO_RECUPERACAO),
        "Recuperacao de senha do Megabrain\n"
        . "=================================\n\n"
        . 'CODIGO: ' . $codigo . "\n\n"
        . 'Usuario: @' . ($dono['apelido'] ?? '') . "\n"
        . 'Vale por ' . RECUPERACAO_MINUTOS . " minutos, uma vez so.\n"
        . "Depois de trocar a senha, este arquivo e apagado sozinho.\n"
    );

    return true;
}

/**
 * Troca a senha usando o código. Devolve o id do usuário, ou erro.
 *
 * Todas as sessões daquela pessoa caem junto. Se a senha foi trocada porque
 * alguém tomou a conta, deixar a sessão do invasor viva anularia a troca.
 */
function usar_recuperacao(string $codigo, string $nova): string|array
{
    if (mb_strlen($nova) < 10) {
        return ['erro' => 'A nova senha precisa ter pelo menos 10 caracteres.'];
    }

    $lista = recuperacoes();
    $chave = hash('sha256', strtoupper(trim($codigo)));
    $r     = $lista[$chave] ?? null;

    if (!is_array($r) || strtotime((string) $r['expira_em']) < time()) {
        usleep(400000);

        return ['erro' => 'Código inválido ou vencido.'];
    }

    $us = usuarios();
    $id = (string) $r['usuario_id'];

    if (!isset($us[$id])) {
        return ['erro' => 'Usuário não existe mais.'];
    }

    $us[$id]['senha_hash'] = password_hash($nova, PASSWORD_DEFAULT);
    if (!salvar_usuarios($us)) {
        return ['erro' => 'Não consegui gravar.'];
    }

    unset($lista[$chave]);
    escrever_json(caminho('recuperacoes.json'), $lista);

    derrubar_sessoes($id);
    @unlink(caminho(ARQUIVO_RECUPERACAO));

    return $id;
}

/** Encerra todas as sessões de um usuário. */
function derrubar_sessoes(string $usuarioId): void
{
    $todas = sessoes();

    foreach ($todas as $k => $s) {
        if (($s['usuario_id'] ?? USUARIO_PADRAO) === $usuarioId) {
            unset($todas[$k]);
        }
    }

    escrever_json(caminho('sessoes.json'), $todas);
}
