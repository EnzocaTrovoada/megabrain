<?php

declare(strict_types=1);

require __DIR__ . '/app/nucleo.php';

cabecalhos_seguranca();

$erro = null;
$modo = !instalado() ? 'instalar' : (autenticado() ? 'app' : 'entrar');

// Chegou por link de convite: vai direto para o cadastro.
if ($modo === 'entrar' && isset($_GET['convite'])) {
    $modo = 'registrar';
}
if ($modo === 'entrar' && isset($_GET['recuperar'])) {
    $modo = 'recuperar';
}
if ($modo === 'entrar' && isset($_GET['esqueci'])) {
    $modo = 'esqueci';
}

$avisoOk = null;

// Gera o código já na primeira visita, para ele existir no disco quando a
// pessoa for procurá-lo. Chamar de novo é inofensivo: só relê o arquivo.
if ($modo === 'instalar') {
    codigo_setup();
}

// ------------------------------------------------------------------- POST

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'instalar' && !instalado()) {
        $r = instalar((string) ($_POST['codigo'] ?? ''), (string) ($_POST['senha'] ?? ''));
        if ($r === true) {
            criar_sessao();
            header('Location: ./');
            exit;
        }
        $erro = $r;
        $modo = 'instalar';
    } elseif ($acao === 'entrar' && instalado()) {
        $espera = tentativas_bloqueado();
        if ($espera > 0) {
            $erro = "Tentativas demais. Espere {$espera} minutos.";
        } else {
            $uid = autenticar((string) ($_POST['apelido'] ?? ''), (string) ($_POST['senha'] ?? ''));
            if ($uid !== null) {
                limpar_tentativas();
                criar_sessao($uid);
                header('Location: ./');
                exit;
            }
            registrar_tentativa();
            usleep(500000);
            // Mensagem unica: dizer "usuario nao existe" entregaria quais
            // apelidos estao cadastrados para quem estiver tentando adivinhar.
            $erro = 'Usuário ou senha incorretos.';
        }
        $modo = 'entrar';
    } elseif ($acao === 'registrar' && instalado()) {
        $r = usar_convite(
            (string) ($_POST['convite'] ?? ''),
            (string) ($_POST['apelido'] ?? ''),
            (string) ($_POST['nome'] ?? ''),
            (string) ($_POST['senha'] ?? '')
        );

        if (is_string($r)) {
            criar_sessao($r);
            header('Location: ./');
            exit;
        }

        $erro = $r['erro'];
        $modo = 'registrar';
    } elseif ($acao === 'pedir-recuperacao' && instalado()) {
        $r = pedir_recuperacao_do_dono();
        if ($r === true) {
            $avisoOk = 'Pronto. O código está no arquivo dados/'
                . ARQUIVO_RECUPERACAO . ' no servidor. Vale ' . RECUPERACAO_MINUTOS . ' minutos.';
            $modo = 'recuperar';
        } else {
            $erro = $r['erro'];
            $modo = 'esqueci';
        }
    } elseif ($acao === 'recuperar' && instalado()) {
        $r = usar_recuperacao((string) ($_POST['codigo'] ?? ''), (string) ($_POST['senha'] ?? ''));

        if (is_string($r)) {
            criar_sessao($r);
            header('Location: ./');
            exit;
        }

        $erro = $r['erro'];
        $modo = 'recuperar';
    } elseif ($acao === 'sair') {
        destruir_sessao();
        header('Location: ./');
        exit;
    }
}

// Sobe a cada mudança em CSS/JS: o LiteSpeed cacheia estático por dias e sem
// isto você continuaria vendo a versão velha depois do upload.
$versao = '16';

// Ícone do site. Procura o PNG nos dois lugares onde ele costuma ser largado e
// cai no SVG do repositório se não achar nenhum — assim reenviar o index.php
// nunca apaga um ícone que foi trocado direto no servidor.
$icone = 'assets/icone.svg';
foreach (['assets/Sprite-0001.png', 'Sprite-0001.png'] as $candidato) {
    if (is_file(__DIR__ . '/' . $candidato)) {
        $icone = $candidato;
        break;
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d0d0f">
<title>Megabrain</title>
<link rel="icon" href="<?= e($icone) ?>?v=<?= e($versao) ?>">
<?php /* Sem apple-touch-icon de propósito: ele exige PNG de 180px ou mais, e um
         sprite de 16x16 apareceria borrado na tela de início do iPhone. */ ?>
<link rel="manifest" href="manifest.json?v=<?= e($versao) ?>">
<link rel="stylesheet" href="assets/app.css?v=<?= e($versao) ?>">
</head>
<body class="modo-<?= e($modo) ?>">

<?php if ($modo === 'instalar'): ?>

  <main class="portao">
    <form method="post" class="cartao">
      <h1>Megabrain</h1>
      <p class="sub">Primeira vez aqui. Defina a senha que você vai usar sempre.</p>

      <?php if ($erro !== null): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>

      <input type="hidden" name="acao" value="instalar">

      <label for="codigo">Código de instalação</label>
      <input id="codigo" name="codigo" autocomplete="off" required autofocus>
      <p class="dica">
        Está no arquivo <code><?= e(ARQUIVO_CODIGO_SETUP) ?></code>, dentro da pasta
        <code>dados/</code> no servidor. Abra pelo Gerenciador de Arquivos da Hostinger.
      </p>

      <label for="senha">Sua senha</label>
      <input id="senha" name="senha" type="password" autocomplete="new-password" required minlength="10">
      <p class="dica">Mínimo 10 caracteres. Não dá pra recuperar — anote no seu gerenciador de senhas.</p>

      <button type="submit">Instalar</button>

      <?php if (!dados_fora_do_publico()): ?>
        <p class="aviso">
          Seus dados vão ficar dentro do <code>public_html</code>, protegidos só por
          <code>.htaccess</code>. Funciona, mas o ideal é a pasta <code>dados/</code>
          ficar um nível acima. Dá pra corrigir depois.
        </p>
      <?php endif; ?>
    </form>
  </main>

<?php elseif ($modo === 'entrar'): ?>

  <main class="portao">
    <form method="post" class="cartao">
      <h1>Megabrain</h1>
      <?php if ($erro !== null): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>

      <input type="hidden" name="acao" value="entrar">

<?php if (quantos_usuarios() > 1): ?>
      <label for="apelido">Usuário</label>
      <input id="apelido" name="apelido" autocomplete="username" required autofocus>
<?php endif; ?>

      <label for="senha">Senha</label>
      <input id="senha" name="senha" type="password" autocomplete="current-password" required
             <?= quantos_usuarios() > 1 ? '' : 'autofocus' ?>>

      <button type="submit">Entrar</button>

      <p class="dica">
        <a href="?esqueci=1">Esqueci a senha</a>
        · Tem um convite? <a href="?convite=">Criar conta</a>.
      </p>
    </form>
  </main>

<?php elseif ($modo === 'esqueci'): ?>

  <main class="portao">
    <form method="post" class="cartao">
      <h1>Esqueci a senha</h1>

      <?php if ($erro !== null): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>

      <p class="sub">Não há envio de e-mail aqui, de propósito — e-mail em
      hospedagem compartilhada falha calado. São dois caminhos:</p>

      <p class="dica"><strong>Se você é membro:</strong> peça ao dono. Ele gera
      um link de troca de senha no painel dele e te manda.</p>

      <p class="dica"><strong>Se você é o dono:</strong> o botão abaixo grava um
      código dentro da pasta <code>dados/</code> no servidor. Abra pelo
      Gerenciador de Arquivos da Hostinger. Só quem alcança os arquivos do
      servidor consegue ler — que é o mesmo acesso de quem já poderia trocar a
      senha na mão.</p>

      <input type="hidden" name="acao" value="pedir-recuperacao">
      <button type="submit">Gerar código no servidor</button>

      <p class="dica">
        <a href="?recuperar=">Já tenho um código</a> · <a href="./">Voltar</a>
      </p>
    </form>
  </main>

<?php elseif ($modo === 'recuperar'): ?>

  <main class="portao">
    <form method="post" class="cartao">
      <h1>Nova senha</h1>

      <?php if ($erro !== null): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>
      <?php if ($avisoOk !== null): ?><p class="sub"><?= e($avisoOk) ?></p><?php endif; ?>

      <input type="hidden" name="acao" value="recuperar">

      <label for="codigo">Código de recuperação</label>
      <input id="codigo" name="codigo" autocomplete="off" required autofocus
             value="<?= e((string) ($_GET['recuperar'] ?? '')) ?>">

      <label for="senha">Nova senha</label>
      <input id="senha" name="senha" type="password" autocomplete="new-password" required minlength="10">
      <p class="dica">Mínimo 10 caracteres. Todas as outras sessões caem.</p>

      <button type="submit">Trocar senha</button>

      <p class="dica"><a href="./">Voltar</a></p>
    </form>
  </main>

<?php elseif ($modo === 'registrar'): ?>

  <main class="portao">
    <form method="post" class="cartao">
      <h1>Criar conta</h1>
      <p class="sub">Só com convite. Peça um a quem já usa.</p>

      <?php if ($erro !== null): ?><p class="erro"><?= e($erro) ?></p><?php endif; ?>

      <input type="hidden" name="acao" value="registrar">

      <label for="convite">Código do convite</label>
      <input id="convite" name="convite" autocomplete="off" required
             value="<?= e((string) ($_GET['convite'] ?? '')) ?>"
             <?= ($_GET['convite'] ?? '') === '' ? 'autofocus' : '' ?>>

      <label for="nome">Seu nome</label>
      <input id="nome" name="nome" autocomplete="name" maxlength="60">

      <label for="apelido">Usuário</label>
      <input id="apelido" name="apelido" autocomplete="username" required
             pattern="[a-z0-9_-]{3,24}" <?= ($_GET['convite'] ?? '') !== '' ? 'autofocus' : '' ?>>
      <p class="dica">Minúsculas, números, hífen ou sublinhado. De 3 a 24 caracteres.</p>

      <label for="senha">Senha</label>
      <input id="senha" name="senha" type="password" autocomplete="new-password" required minlength="10">
      <p class="dica">Mínimo 10 caracteres. Não dá pra recuperar — anote no seu gerenciador de senhas.</p>

      <button type="submit">Criar conta</button>

      <p class="dica"><a href="./">Já tenho conta</a></p>
    </form>
  </main>

<?php else: ?>

  <div class="app" id="app">
    <aside class="lateral" id="lateral">
      <div class="lateral-topo">
        <strong>Megabrain</strong>
        <div class="sair"><button type="button" id="abrir-conta" title="Sua conta">conta</button><form method="post"><input type="hidden" name="acao" value="sair"><button type="submit" title="Sair">sair</button></form></div>
      </div>

      <input type="search" id="busca" placeholder="Buscar…" autocomplete="off">

      <div class="secao">
        <div class="secao-topo"><span>Espaços</span><button id="novo-espaco" title="Novo espaço">+</button></div>
        <ul id="espacos"></ul>
      </div>

      <div class="secao">
        <div class="secao-topo"><span>Pendências</span><button id="ver-pendencias" title="Ver todas">↗</button></div>
        <ul id="pendencias"></ul>
      </div>

      <div class="secao cresce">
        <div class="secao-topo">
          <span id="titulo-notas">Anotações</span>
          <select id="ordem-notas" title="Ordenar">
            <option value="recentes">recentes</option>
            <option value="alfabetica">A-Z</option>
            <option value="antigas">antigas</option>
          </select>
          <button id="nova-nota" title="Nova anotação">+</button>
        </div>
        <ul id="notas"></ul>
      </div>
    </aside>

    <main class="painel" id="painel">
      <header class="barra">
        <button id="menu" class="so-mobile" title="Menu">☰</button>
        <input type="text" id="titulo" placeholder="Título da anotação" autocomplete="off">
        <select id="espaco-da-nota" title="Espaço"></select>
        <button id="alternar-ler" class="icone" title="Ler / editar (Ctrl+E)">◑</button>
        <button id="abrir-hoje" class="icone" title="Hoje (Ctrl+H)">☀</button>
        <button id="abrir-agenda" class="icone" title="Agenda (Ctrl+J)">▦</button>
        <button id="abrir-grafo" class="icone" title="Mapa mental (Ctrl+G)">◍</button>
        <span id="estado" class="estado"></span>
      </header>

      <div class="area-editor">
        <textarea id="editor" placeholder="Escreva aqui. Markdown funciona: # título, **negrito**, - lista, `código`.&#10;&#10;Use [[nome de outra nota]] para ligar — é isso que desenha o mapa mental.&#10;Cole uma imagem (Ctrl+V) que ela sobe sozinha." spellcheck="true"></textarea>
        <article id="leitura" class="leitura oculto"></article>
        <ul id="sugestoes" class="sugestoes oculto"></ul>
      </div>

      <footer id="backlinks" class="backlinks oculto"></footer>
      <div id="contador" class="contador oculto"></div>

      <section id="vazio" class="hoje"></section>
    </main>
  </div>

  <div id="conta" class="paleta oculto">
    <div class="paleta-caixa conta-caixa">
      <header class="conta-topo">
        <strong>Conta</strong>
        <button id="fechar-conta" title="Fechar">×</button>
      </header>
      <div id="conta-corpo" class="conta-corpo"></div>
    </div>
  </div>

  <div id="paleta" class="paleta oculto">
    <div class="paleta-caixa">
      <input id="paleta-campo" type="text" placeholder="Buscar em tudo…" autocomplete="off" spellcheck="false">
      <ul id="paleta-lista" class="paleta-lista"></ul>
      <footer class="paleta-rodape">↑ ↓ navegar · Enter abrir · Esc fechar</footer>
    </div>
  </div>

  <div id="agenda" class="agenda oculto">
    <div class="agenda-barra">
      <strong>Agenda</strong>
      <button id="ag-anterior" title="Mês anterior">‹</button>
      <span id="agenda-mes"></span>
      <button id="ag-proximo" title="Próximo mês">›</button>
      <button id="ag-hoje">hoje</button>
      <button id="ag-rotinas">rotinas</button>
      <button id="ag-celular">calendário</button>
      <button id="ag-captura">iPhone</button>
      <button id="fechar-agenda" title="Fechar">×</button>
    </div>
    <div class="agenda-corpo">
      <div class="agenda-mes-caixa">
        <div id="agenda-grade" class="agenda-grade"></div>
        <div id="agenda-rotinas" class="agenda-rotinas oculto"></div>
      </div>
      <section id="agenda-dia" class="agenda-dia"></section>
    </div>
  </div>

  <div id="grafo" class="grafo oculto">
    <canvas id="tela-grafo"></canvas>
    <div class="grafo-barra">
      <strong>Mapa mental</strong>
      <label><input type="checkbox" id="grafo-local"> só em volta desta nota</label>
      <label id="grafo-prof-caixa" class="desligado">
        profundidade
        <select id="grafo-prof">
          <option value="1">1</option>
          <option value="2" selected>2</option>
          <option value="3">3</option>
        </select>
      </label>
      <span class="grafo-dica">arraste os nós · role pra aproximar · clique pra abrir</span>
      <button id="fechar-grafo" title="Fechar">×</button>
    </div>
  </div>

  <script type="application/json" id="bootstrap"><?= json_encode(['csrf' => csrf()], JSON_UNESCAPED_UNICODE) ?></script>
  <script src="assets/markdown.js?v=<?= e($versao) ?>"></script>
  <script src="assets/paleta.js?v=<?= e($versao) ?>"></script>
  <script src="assets/conta.js?v=<?= e($versao) ?>"></script>
  <script src="assets/agenda.js?v=<?= e($versao) ?>"></script>
  <script src="assets/grafo.js?v=<?= e($versao) ?>"></script>
  <script src="assets/app.js?v=<?= e($versao) ?>"></script>

<?php endif; ?>

</body>
</html>
