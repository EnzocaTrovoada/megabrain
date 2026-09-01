<?php

declare(strict_types=1);

/**
 * Usuários, convites e papéis.
 *
 * O id NÃO é o apelido de login. O id vira nome de pasta em dados/usuarios/,
 * e apelido é coisa que se troca — se fossem a mesma coisa, renomear o login
 * moveria a pasta com todas as anotações dentro.
 *
 * Sem cadastro aberto: só entra quem tem convite. Isso corta captcha, e-mail
 * transacional e política antiabuso — três coisas que sozinhas custariam mais
 * que o resto do sistema junto.
 */

const PAPEL_DONO   = 'dono';
const PAPEL_MEMBRO = 'membro';

const QUOTA_DONO   = 524288000;   // 500 MB
const QUOTA_MEMBRO = 209715200;   // 200 MB

const CONVITE_DIAS = 7;

/** @return array<string, array<string, mixed>> indexado pelo id */
function usuarios(): array
{
    migrar_usuarios();

    return ler_json(caminho('usuarios.json'), []);
}

function salvar_usuarios(array $u): bool
{
    return escrever_json(caminho('usuarios.json'), $u);
}

/**
 * Traz a senha única antiga para a tabela de usuários.
 *
 * O id continua sendo "principal" de propósito: é o nome da pasta que já tem
 * as anotações. Mudar o id aqui obrigaria a mover dados reais.
 */
function migrar_usuarios(): void
{
    if (is_file(caminho('usuarios.json'))) {
        return;
    }

    $c = ler_json(caminho('config.json'), []);
    if (!isset($c['senha_hash'])) {
        return;
    }

    escrever_json(caminho('usuarios.json'), [
        USUARIO_PADRAO => [
            'id'          => USUARIO_PADRAO,
            'apelido'     => USUARIO_PADRAO,
            'nome'        => 'Dono',
            'senha_hash'  => $c['senha_hash'],
            'papel'       => PAPEL_DONO,
            'quota_bytes' => QUOTA_DONO,
            'ativo'       => true,
            'criado_em'   => $c['criado_em'] ?? agora(),
        ],
    ]);
}

function apelido_valido(string $a): bool
{
    return (bool) preg_match('/^[a-z0-9_-]{3,24}$/', $a);
}

function usuario_por_apelido(string $apelido): ?array
{
    foreach (usuarios() as $u) {
        if (($u['apelido'] ?? '') === mb_strtolower(trim($apelido))) {
            return $u;
        }
    }

    return null;
}

function usuario_por_id(string $id): ?array
{
    return usuarios()[$id] ?? null;
}

function quantos_usuarios(): int
{
    return count(array_filter(usuarios(), static fn ($u) => !empty($u['ativo'])));
}

/**
 * Confere apelido e senha. Devolve o id, ou null.
 *
 * Quando só existe um usuário, o apelido é opcional: o sistema nasceu com uma
 * senha só e não faz sentido obrigar a lembrar de um login que nunca foi
 * escolhido. Com dois ou mais, passa a ser obrigatório.
 */
function autenticar(string $apelido, string $senha): ?string
{
    $lista = array_values(array_filter(usuarios(), static fn ($u) => !empty($u['ativo'])));

    if ($apelido === '' && count($lista) === 1) {
        $u = $lista[0];
    } else {
        $u = usuario_por_apelido($apelido);
    }

    if ($u === null || empty($u['ativo'])) {
        // Gasta o mesmo tempo de um hash real: sem isto, dá para descobrir
        // quais apelidos existem só cronometrando a resposta.
        password_verify($senha, '$2y$10$usuarioinexistenteusuarioinexistenteusuarioinexistente');

        return null;
    }

    return password_verify($senha, (string) $u['senha_hash']) ? (string) $u['id'] : null;
}

function quota_do_usuario(?string $id = null): int
{
    $u = usuario_por_id($id ?? usuario_atual());

    return (int) ($u['quota_bytes'] ?? QUOTA_MEMBRO);
}

function eh_dono(?string $id = null): bool
{
    $u = usuario_por_id($id ?? usuario_atual());

    return ($u['papel'] ?? PAPEL_MEMBRO) === PAPEL_DONO;
}

// ---------------------------------------------------------------- convites

function convites(): array
{
    return ler_json(caminho('convites.json'), []);
}

/** Devolve o código em claro. Ele não é guardado: no disco fica só o hash. */
function criar_convite(string $criadoPor, string $nota = ''): string
{
    $codigo = strtoupper(bin2hex(random_bytes(4)));
    $lista  = convites();

    $lista[hash('sha256', $codigo)] = [
        'id'         => novo_id(),
        'criado_por' => $criadoPor,
        'nota'       => mb_substr($nota, 0, 60),
        'criado_em'  => agora(),
        'expira_em'  => gmdate('c', time() + CONVITE_DIAS * 86400),
        'usado_por'  => null,
    ];

    escrever_json(caminho('convites.json'), $lista);

    return $codigo;
}

/**
 * Cria o usuário se o convite valer. Devolve o id, ou uma mensagem de erro.
 *
 * O convite é queimado na mesma escrita que cria o usuário — se fossem dois
 * passos, duas pessoas com o mesmo código poderiam entrar no intervalo.
 */
function usar_convite(string $codigo, string $apelido, string $nome, string $senha): string|array
{
    $apelido = mb_strtolower(trim($apelido));

    if (!apelido_valido($apelido)) {
        return ['erro' => 'O usuário deve ter de 3 a 24 caracteres: letras minúsculas, números, hífen ou sublinhado.'];
    }
    if (mb_strlen($senha) < 10) {
        return ['erro' => 'A senha precisa ter pelo menos 10 caracteres.'];
    }
    if (usuario_por_apelido($apelido) !== null) {
        return ['erro' => 'Esse usuário já existe.'];
    }

    $lista = convites();
    $chave = hash('sha256', strtoupper(trim($codigo)));
    $c     = $lista[$chave] ?? null;

    if (!is_array($c) || !empty($c['usado_por']) || strtotime((string) $c['expira_em']) < time()) {
        usleep(400000);

        return ['erro' => 'Convite inválido, já usado ou vencido.'];
    }

    $id = novo_id();
    $us = usuarios();

    $us[$id] = [
        'id'          => $id,
        'apelido'     => $apelido,
        'nome'        => mb_substr(trim($nome) ?: $apelido, 0, 60),
        'senha_hash'  => password_hash($senha, PASSWORD_DEFAULT),
        'papel'       => PAPEL_MEMBRO,
        'quota_bytes' => QUOTA_MEMBRO,
        'ativo'       => true,
        'criado_em'   => agora(),
    ];

    if (!salvar_usuarios($us)) {
        return ['erro' => 'Não consegui gravar. Confira as permissões da pasta de dados.'];
    }

    $lista[$chave]['usado_por'] = $id;
    $lista[$chave]['usado_em']  = agora();
    escrever_json(caminho('convites.json'), $lista);

    return $id;
}

function trocar_senha(string $id, string $atual, string $nova): true|array
{
    $us = usuarios();
    $u  = $us[$id] ?? null;

    if ($u === null) {
        return ['erro' => 'Usuário não encontrado.'];
    }
    if (!password_verify($atual, (string) $u['senha_hash'])) {
        usleep(400000);

        return ['erro' => 'Senha atual incorreta.'];
    }
    if (mb_strlen($nova) < 10) {
        return ['erro' => 'A nova senha precisa ter pelo menos 10 caracteres.'];
    }

    $us[$id]['senha_hash'] = password_hash($nova, PASSWORD_DEFAULT);

    if (!salvar_usuarios($us)) {
        return ['erro' => 'Não consegui gravar.'];
    }

    // Derruba as outras sessões e recria a desta aba: se a senha foi trocada
    // por suspeita, deixar sessão antiga viva anularia a troca.
    derrubar_sessoes($id);
    criar_sessao($id);

    return true;
}
