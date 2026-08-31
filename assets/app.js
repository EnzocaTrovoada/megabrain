'use strict';

/**
 * Megabrain — cliente.
 *
 * Sem framework e sem build: o deploy é copiar arquivo. Tudo que entra na tela
 * vai por textContent, nunca innerHTML, então conteúdo de nota não vira HTML.
 */

const CSRF = JSON.parse(document.getElementById('bootstrap').textContent).csrf;

const CORES = ['#8b5cf6', '#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#ec4899', '#14b8a6'];

const el = {
  app: document.getElementById('app'),
  espacos: document.getElementById('espacos'),
  notas: document.getElementById('notas'),
  tituloNotas: document.getElementById('titulo-notas'),
  busca: document.getElementById('busca'),
  titulo: document.getElementById('titulo'),
  editor: document.getElementById('editor'),
  seletor: document.getElementById('espaco-da-nota'),
  estado: document.getElementById('estado'),
  vazio: document.getElementById('vazio'),
  backlinks: document.getElementById('backlinks'),
  sugestoes: document.getElementById('sugestoes'),
  leitura: document.getElementById('leitura'),
};

let estado = { espacos: [], anotacoes: [] };

/**
 * O localStorage pode ter estado gravado por uma versão anterior, quando o
 * agrupamento se chamava "matéria". Sem converter, a primeira renderização
 * estoura em espacos.forEach e a tela fica em branco na atualização — que é
 * exatamente o pior momento para quebrar.
 */
function normalizarEstado(e) {
  if (!e || typeof e !== 'object') return { espacos: [], anotacoes: [] };

  const espacos = Array.isArray(e.espacos) ? e.espacos
    : (Array.isArray(e.materias) ? e.materias : []);

  const anotacoes = (Array.isArray(e.anotacoes) ? e.anotacoes : []).map((n) => (
    n && n.espaco_id === undefined && n.materia_id !== undefined
      ? Object.assign({}, n, { espaco_id: n.materia_id })
      : n
  ));

  return { espacos: espacos, anotacoes: anotacoes };
}
let espacoAtivo = null;   // null = todas
let notaAberta = null;     // { id, titulo, conteudo, espaco_id }
let sujo = false;
let timer = null;

// --------------------------------------------------------------- rede

async function api(acao, corpo, params) {
  const opcoes = { method: corpo ? 'POST' : 'GET', headers: {} };
  if (corpo) {
    opcoes.headers['Content-Type'] = 'application/json';
    opcoes.headers['X-CSRF'] = CSRF;
    opcoes.body = JSON.stringify(corpo);
  }

  // Parâmetros vão separados: concatenar "nota&id=x" na ação faria o
  // encodeURIComponent escapar o & e o servidor receberia tudo como um nome só.
  const url = new URLSearchParams({ a: acao });
  if (params) {
    Object.keys(params).forEach((k) => url.append(k, params[k]));
  }

  const r = await fetch('api.php?' + url.toString(), opcoes);

  // Sessão caiu: recarregar joga na tela de login em vez de falhar em silêncio.
  if (r.status === 401) {
    location.reload();
    throw new Error('sem sessao');
  }
  if (!r.ok) throw new Error('http ' + r.status);

  return r.json();
}

function guardar(chave, valor) {
  try { localStorage.setItem(chave, JSON.stringify(valor)); } catch (e) { /* modo privado, cota */ }
}

function recuperar(chave) {
  try { return JSON.parse(localStorage.getItem(chave) || 'null'); } catch (e) { return null; }
}

// -------------------------------------------------------------- listas

function corDoEspaco(id) {
  const m = estado.espacos.find((x) => x.id === id);
  return m ? m.cor : '#3a3a46';
}

function item(texto, opcoes) {
  const li = document.createElement('li');

  if (opcoes.cor) {
    const p = document.createElement('span');
    p.className = 'ponto';
    p.style.background = opcoes.cor;
    if (opcoes.aoTrocarCor) {
      p.classList.add('clicavel');
      p.title = 'Trocar a cor';
      p.addEventListener('click', (ev) => { ev.stopPropagation(); opcoes.aoTrocarCor(); });
    }
    li.appendChild(p);
  }

  const rot = document.createElement('span');
  rot.className = 'rotulo';
  rot.textContent = texto;
  li.appendChild(rot);

  if (opcoes.aoEditar) {
    const e = document.createElement('button');
    e.className = 'x';
    e.textContent = '✎';
    e.title = 'Renomear';
    e.addEventListener('click', (ev) => { ev.stopPropagation(); opcoes.aoEditar(); });
    li.appendChild(e);
  }

  if (opcoes.aoExcluir) {
    const x = document.createElement('button');
    x.className = 'x';
    x.textContent = '×';
    x.title = 'Excluir';
    x.addEventListener('click', (ev) => { ev.stopPropagation(); opcoes.aoExcluir(); });
    li.appendChild(x);
  }

  if (opcoes.ativa) li.classList.add('ativa');
  if (opcoes.aoClicar) li.addEventListener('click', opcoes.aoClicar);

  return li;
}

function desenharEspacos() {
  el.espacos.textContent = '';

  el.espacos.appendChild(item('Todos', {
    ativa: espacoAtivo === null,
    aoClicar: () => { espacoAtivo = null; desenhar(); },
  }));

  estado.espacos.forEach((m) => {
    el.espacos.appendChild(item(m.nome, {
      cor: m.cor,
      ativa: espacoAtivo === m.id,
      aoClicar: () => { espacoAtivo = m.id; desenhar(); },
      aoTrocarCor: async () => {
        const i = CORES.indexOf(m.cor);
        await api('espaco.salvar', { id: m.id, nome: m.nome, cor: CORES[(i + 1) % CORES.length] });
        await carregar();
      },
      aoEditar: async () => {
        const nome = prompt('Nome do espaço', m.nome);
        if (nome === null || !nome.trim() || nome.trim() === m.nome) return;
        await api('espaco.salvar', { id: m.id, nome: nome.trim(), cor: m.cor });
        await carregar();
      },
      aoExcluir: async () => {
        if (!confirm('Excluir o espaço "' + m.nome + '"?\nAs anotações dela não são apagadas, ficam sem espaço.')) return;
        await api('espaco.excluir', { id: m.id });
        if (espacoAtivo === m.id) espacoAtivo = null;
        await carregar();
      },
    }));
  });
}

function notasVisiveis() {
  const q = el.busca.value.trim().toLowerCase();

  return estado.anotacoes
    .filter((n) => espacoAtivo === null || n.espaco_id === espacoAtivo)
    .filter((n) => q === '' || (n.titulo || '').toLowerCase().includes(q))
    .sort((a, b) => (b.atualizado_em || '').localeCompare(a.atualizado_em || ''));
}

function desenharNotas() {
  el.notas.textContent = '';

  const m = estado.espacos.find((x) => x.id === espacoAtivo);
  el.tituloNotas.textContent = m ? m.nome : 'Anotações';

  const lista = notasVisiveis();

  if (lista.length === 0) {
    const li = document.createElement('li');
    li.className = 'nenhum';
    li.textContent = el.busca.value.trim() ? 'Nada encontrado.' : 'Nenhuma anotação ainda.';
    el.notas.appendChild(li);
    return;
  }

  lista.forEach((n) => {
    el.notas.appendChild(item(n.titulo || 'Sem título', {
      cor: n.espaco_id ? corDoEspaco(n.espaco_id) : null,
      ativa: notaAberta && notaAberta.id === n.id,
      aoClicar: () => abrir(n.id),
      aoExcluir: async () => {
        if (!confirm('Excluir "' + (n.titulo || 'Sem título') + '"?')) return;
        await api('nota.excluir', { id: n.id });
        if (notaAberta && notaAberta.id === n.id) fechar();
        await carregar();
      },
    }));
  });
}

function desenharSeletor() {
  el.seletor.textContent = '';

  const vazio = document.createElement('option');
  vazio.value = '';
  vazio.textContent = 'sem espaço';
  el.seletor.appendChild(vazio);

  estado.espacos.forEach((m) => {
    const o = document.createElement('option');
    o.value = m.id;
    o.textContent = m.nome;
    el.seletor.appendChild(o);
  });

  el.seletor.value = (notaAberta && notaAberta.espaco_id) || '';
}

function desenhar() {
  desenharEspacos();
  desenharNotas();
  desenharSeletor();

  const temNota = notaAberta !== null;
  document.querySelector('.area-editor').classList.toggle('oculto', !temNota);
  el.vazio.classList.toggle('oculto', temNota);
  el.titulo.classList.toggle('oculto', !temNota);
  el.seletor.classList.toggle('oculto', !temNota);
  if (!temNota) el.backlinks.classList.add('oculto');
}

// -------------------------------------------------------------- edição

async function abrir(id) {
  await salvarJa();

  const cache = recuperar('mb_nota_' + id);
  if (cache) aplicar(cache);

  try {
    const n = await api('nota', null, { id: id });
    aplicar(n);
    guardar('mb_nota_' + id, n);
  } catch (e) {
    if (!cache) marcar('falhou', 'offline');
  }

  el.app.classList.remove('aberto');
  if (window.innerWidth > 760) el.editor.focus();
}

function aplicar(n) {
  notaAberta = n;
  el.titulo.value = n.titulo || '';
  el.editor.value = n.conteudo || '';
  sujo = false;
  marcar('', '');
  desenharBacklinks(n.backlinks || []);
  // Trocar de nota no modo leitura mantém o modo, mas o conteúdo é outro.
  if (modo !== 'escrever') renderizarPreview();
  desenhar();
}

function desenharBacklinks(lista) {
  el.backlinks.textContent = '';
  el.backlinks.classList.toggle('oculto', lista.length === 0);
  if (lista.length === 0) return;

  const rot = document.createElement('span');
  rot.className = 'backlinks-rotulo';
  rot.textContent = lista.length === 1 ? '1 nota aponta pra cá:' : lista.length + ' notas apontam pra cá:';
  el.backlinks.appendChild(rot);

  lista.forEach((b) => {
    const a = document.createElement('button');
    a.className = 'backlink';
    a.textContent = b.titulo;
    a.addEventListener('click', () => abrir(b.id));
    el.backlinks.appendChild(a);
  });
}

function fechar() {
  notaAberta = null;
  el.titulo.value = '';
  el.editor.value = '';
  sujo = false;
  desenhar();
}

function marcar(classe, texto) {
  el.estado.className = 'estado ' + classe;
  el.estado.textContent = texto;
}

function agendar() {
  sujo = true;
  marcar('', 'editando…');
  clearTimeout(timer);
  timer = setTimeout(salvarJa, 700);
}

async function salvarJa() {
  if (!sujo || !notaAberta) return;

  clearTimeout(timer);
  sujo = false;
  marcar('salvando', 'salvando…');

  const payload = {
    id: notaAberta.id,
    titulo: el.titulo.value,
    conteudo: el.editor.value,
    espaco_id: el.seletor.value || null,
  };

  // Guarda local antes da rede: se o wifi da faculdade cair, o texto sobrevive.
  guardar('mb_nota_' + notaAberta.id, Object.assign({}, notaAberta, payload));

  try {
    await api('nota.salvar', payload);
    notaAberta.titulo = payload.titulo;
    notaAberta.espaco_id = payload.espaco_id;
    marcar('salvo', 'salvo');
    await carregar(true);
    // O texto salvo pode ter criado ou concluído uma caixa "- [ ]".
    if (typeof carregarPendencias === 'function') carregarPendencias();
  } catch (e) {
    sujo = true;
    marcar('falhou', 'sem conexão');
  }
}

// -------------------------------------------------------------- carga

async function carregar(silencioso) {
  try {
    estado = normalizarEstado(await api('estado'));
    guardar('mb_estado', estado);
  } catch (e) {
    const cache = recuperar('mb_estado');
    if (cache) {
      estado = normalizarEstado(cache);
      if (!silencioso) marcar('falhou', 'offline');
    }
  }
  desenhar();
}

// -------------------------------------------------------------- eventos

el.editor.addEventListener('input', agendar);
el.editor.addEventListener('input', agendarPreview);
el.titulo.addEventListener('input', agendar);
el.seletor.addEventListener('change', () => { sujo = true; salvarJa(); });
el.busca.addEventListener('input', desenharNotas);

document.getElementById('novo-espaco').addEventListener('click', async () => {
  const nome = prompt('Nome do espaço (matéria, projeto, área da vida…)');
  if (!nome || !nome.trim()) return;

  // Só disciplina ganha árvore de notas e cálculo de média. O resto é
  // agrupamento puro — que é o caso da maioria dos projetos.
  const resp = (prompt('Tipo: 1 disciplina · 2 projeto · 3 pessoal', '2') || '2').trim();
  const tipo = { '1': 'disciplina', '2': 'projeto', '3': 'pessoal' }[resp] || 'projeto';

  await api('espaco.salvar', {
    nome: nome.trim(),
    tipo: tipo,
    cor: CORES[estado.espacos.length % CORES.length],
  });
  await carregar();
});

document.getElementById('nova-nota').addEventListener('click', async () => {
  await salvarJa();
  const r = await api('nota.salvar', {
    titulo: '',
    conteudo: '',
    espaco_id: espacoAtivo,
  });
  await carregar(true);
  await abrir(r.id);
  el.titulo.focus();
});

document.getElementById('menu').addEventListener('click', () => {
  el.app.classList.toggle('aberto');
});

el.app.addEventListener('click', (ev) => {
  if (el.app.classList.contains('aberto') && !ev.target.closest('.lateral') && !ev.target.closest('#menu')) {
    el.app.classList.remove('aberto');
  }
});

// No celular o navegador congela timers quando o app vai pro fundo, então o
// debounce sozinho perde texto. Salvar na troca de visibilidade cobre isso.
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'hidden') salvarJa();
});
window.addEventListener('pagehide', salvarJa);
el.editor.addEventListener('blur', salvarJa);
el.titulo.addEventListener('blur', salvarJa);

document.addEventListener('keydown', (ev) => {
  if ((ev.metaKey || ev.ctrlKey) && ev.key === 's') { ev.preventDefault(); salvarJa(); }
  if ((ev.metaKey || ev.ctrlKey) && ev.key === 'k') { ev.preventDefault(); el.busca.focus(); el.busca.select(); }
  if ((ev.metaKey || ev.ctrlKey) && ev.key === 'e') { ev.preventDefault(); proximoModo(); }
});

// Tab dentro do editor indenta em vez de pular pro próximo campo.
el.editor.addEventListener('keydown', (ev) => {
  if (ev.key !== 'Tab') return;
  // Com a lista de sugestões aberta, Tab escolhe o link. stopPropagation não
  // basta: listeners no mesmo elemento rodam de qualquer jeito.
  if (!el.sugestoes.classList.contains('oculto')) return;
  ev.preventDefault();
  const i = el.editor.selectionStart;
  const f = el.editor.selectionEnd;
  el.editor.value = el.editor.value.slice(0, i) + '  ' + el.editor.value.slice(f);
  el.editor.selectionStart = el.editor.selectionEnd = i + 2;
  agendar();
});

// ------------------------------------------------------ ler vs escrever

/**
 * Três modos: escrever, dividido e ler.
 *
 * O dividido existe porque textarea não renderiza imagem dentro de si — para
 * ver a foto do quadro enquanto digita, ela tem que aparecer ao lado. No
 * celular não cabem duas colunas, então lá o ciclo pula o dividido.
 */
const BOTAO_MODO = {
  escrever: { icone: '◑', dica: 'Ver ao lado (Ctrl+E)' },
  dividido: { icone: '◐', dica: 'Só leitura (Ctrl+E)' },
  ler:      { icone: '●', dica: 'Voltar a escrever (Ctrl+E)' },
};

let modo = 'escrever';
let timerPreview = null;

function ehEstreito() {
  // Largura 0 acontece em contexto sem janela real; celular nenhum mede zero,
  // então nesse caso não vale tratar como tela estreita.
  return window.innerWidth > 0 && window.innerWidth <= 760;
}

function renderizarPreview() {
  el.leitura.innerHTML = Markdown.renderizar(el.editor.value);
}

function aplicarModo(novo) {
  modo = novo;

  const mostraEditor  = modo !== 'ler';
  const mostraLeitura = modo !== 'escrever';

  if (mostraLeitura) renderizarPreview();

  el.editor.classList.toggle('oculto', !mostraEditor);
  el.leitura.classList.toggle('oculto', !mostraLeitura);
  document.querySelector('.area-editor').classList.toggle('dividido', modo === 'dividido');

  const b = document.getElementById('alternar-ler');
  b.textContent = BOTAO_MODO[modo].icone;
  b.title = BOTAO_MODO[modo].dica;
  b.classList.toggle('ligado', modo !== 'escrever');

  if (modo === 'escrever') el.editor.focus();

  guardar('mb_modo', modo);
}

function proximoModo() {
  const ciclo = ehEstreito() ? ['escrever', 'ler'] : ['escrever', 'dividido', 'ler'];
  aplicarModo(ciclo[(ciclo.indexOf(modo) + 1) % ciclo.length]);
}

/** No dividido o preview acompanha a digitação, com folga para não pesar. */
function agendarPreview() {
  if (modo === 'escrever') return;
  clearTimeout(timerPreview);
  timerPreview = setTimeout(renderizarPreview, 150);
}

// Girar o celular pode tirar a largura que o dividido precisa.
window.addEventListener('resize', () => {
  if (modo === 'dividido' && ehEstreito()) aplicarModo('ler');
});

// Wikilink no modo leitura: abre a nota pelo título, ou oferece criar.
el.leitura.addEventListener('click', async (ev) => {
  const a = ev.target.closest('a.wikilink');
  if (!a) return;
  ev.preventDefault();

  const alvo = (a.dataset.nota || '').trim();
  const chave = normalizarTitulo(alvo);
  const achada = estado.anotacoes.find((n) => normalizarTitulo(n.titulo || '') === chave);

  if (achada) return abrir(achada.id);

  if (confirm('A nota "' + alvo + '" ainda não existe. Criar?')) {
    const r = await api('nota.salvar', { titulo: alvo, conteudo: '', espaco_id: espacoAtivo });
    await carregar(true);
    await abrir(r.id);
    aplicarModo('escrever');
  }
});

/**
 * Mesmo casamento do servidor: sem acento, sem maiuscula.
 * O intervalo ̀-ͯ e o dos acentos combinantes que o NFD separa.
 * O arquivo e UTF-8 e o .gitattributes o mantem assim. Se um dia a codificacao
 * for mexida, e esta linha que quebra primeiro.
 */
function normalizarTitulo(t) {
  return t.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

document.getElementById('alternar-ler').addEventListener('click', proximoModo);

// ------------------------------------------------------ colar imagem

async function enviarImagem(arquivo) {
  const marcador = '![enviando ' + (arquivo.name || 'imagem') + '…]()';
  inserirNoEditor(marcador);
  marcar('salvando', 'enviando…');

  const fd = new FormData();
  fd.append('arquivo', arquivo);

  try {
    const r = await fetch('api.php?a=arquivo.enviar', {
      method: 'POST',
      headers: { 'X-CSRF': CSRF },
      body: fd,
    });
    const d = await r.json();

    if (!r.ok || !d.ok) {
      throw new Error(d.erro || 'falhou');
    }

    // Referência estável por id, nunca URL: se a rota mudar, nada quebra.
    el.editor.value = el.editor.value.replace(marcador, '![](arquivo:' + d.id + ')');
    sujo = true;
    await salvarJa();
  } catch (e) {
    const motivo = {
      tipo_nao_aceito: 'só entra JPEG, PNG ou WEBP',
      grande_demais: 'imagem grande demais',
      quota_cheia: 'sem espaço',
      imagem_invalida: 'não consegui ler essa imagem',
    }[e.message] || 'falhou o envio';

    el.editor.value = el.editor.value.replace(marcador, '');
    marcar('falhou', motivo);
  }
}

function inserirNoEditor(txt) {
  const i = el.editor.selectionStart;
  el.editor.value = el.editor.value.slice(0, i) + txt + el.editor.value.slice(el.editor.selectionEnd);
  el.editor.selectionStart = el.editor.selectionEnd = i + txt.length;
}

el.editor.addEventListener('paste', (ev) => {
  const itens = [...(ev.clipboardData ? ev.clipboardData.files : [])];
  const img = itens.find((f) => f.type.startsWith('image/'));
  if (!img) return;
  ev.preventDefault();
  enviarImagem(img);
});

el.editor.addEventListener('dragover', (ev) => ev.preventDefault());
el.editor.addEventListener('drop', (ev) => {
  const img = [...ev.dataTransfer.files].find((f) => f.type.startsWith('image/'));
  if (!img) return;
  ev.preventDefault();
  enviarImagem(img);
});

// ------------------------------------------------- autocomplete de [[ ]]

let sugestaoAtiva = -1;

/** Devolve o trecho digitado depois de um "[[" ainda não fechado, ou null. */
function trechoDeLink() {
  const ate = el.editor.value.slice(0, el.editor.selectionStart);
  const abre = ate.lastIndexOf('[[');
  if (abre === -1) return null;

  const depois = ate.slice(abre + 2);
  // Fechou, ou pulou linha: não está mais escrevendo um link.
  if (depois.includes(']]') || depois.includes('\n')) return null;

  return { inicio: abre + 2, texto: depois };
}

function fecharSugestoes() {
  el.sugestoes.classList.add('oculto');
  el.sugestoes.textContent = '';
  sugestaoAtiva = -1;
}

function atualizarSugestoes() {
  const t = trechoDeLink();
  if (!t) return fecharSugestoes();

  const q = t.texto.trim().toLowerCase();
  const candidatas = estado.anotacoes
    .filter((n) => n.id !== (notaAberta && notaAberta.id))
    .filter((n) => (n.titulo || '').toLowerCase().includes(q))
    .slice(0, 7);

  if (candidatas.length === 0) return fecharSugestoes();

  el.sugestoes.textContent = '';
  candidatas.forEach((n, i) => {
    const li = document.createElement('li');
    li.textContent = n.titulo || 'Sem título';
    if (i === 0) li.classList.add('ativa');
    li.addEventListener('mousedown', (ev) => { ev.preventDefault(); aplicarSugestao(n.titulo); });
    el.sugestoes.appendChild(li);
  });

  sugestaoAtiva = 0;
  el.sugestoes.classList.remove('oculto');
}

function aplicarSugestao(titulo) {
  const t = trechoDeLink();
  if (!t) return;

  const v = el.editor.value;
  const fim = el.editor.selectionStart;
  el.editor.value = v.slice(0, t.inicio) + titulo + ']]' + v.slice(fim);
  const cursor = t.inicio + titulo.length + 2;
  el.editor.selectionStart = el.editor.selectionEnd = cursor;

  fecharSugestoes();
  agendar();
}

el.editor.addEventListener('input', atualizarSugestoes);
el.editor.addEventListener('blur', fecharSugestoes);

el.editor.addEventListener('keydown', (ev) => {
  if (el.sugestoes.classList.contains('oculto')) return;

  const itens = el.sugestoes.querySelectorAll('li');
  if (ev.key === 'Escape') { ev.preventDefault(); return fecharSugestoes(); }
  if (ev.key === 'Enter' || ev.key === 'Tab') {
    ev.preventDefault();
    ev.stopPropagation();
    return aplicarSugestao(itens[sugestaoAtiva].textContent);
  }
  if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
    ev.preventDefault();
    itens[sugestaoAtiva].classList.remove('ativa');
    sugestaoAtiva = (sugestaoAtiva + (ev.key === 'ArrowDown' ? 1 : itens.length - 1)) % itens.length;
    itens[sugestaoAtiva].classList.add('ativa');
  }
}, true);

// ---------------------------------------------------------- mapa mental

async function abrirGrafo() {
  await salvarJa();

  let dados;
  try {
    dados = await api('grafo');
  } catch (e) {
    marcar('falhou', 'sem conexão');
    return;
  }

  Grafo.abrir(dados, {
    foco: notaAberta ? 'n:' + notaAberta.id : null,
    local: document.getElementById('grafo-local').checked,
    aoAbrir: async (no) => {
      if (no.tipo === 'nota') { Grafo.fechar(); await abrir(no.ref); return; }
      if (no.tipo === 'espaco') { espacoAtivo = no.ref; Grafo.fechar(); desenhar(); return; }

      // Nó fantasma: link para nota que ainda não existe. Clicar cria.
      if (no.tipo === 'fantasma' && confirm('Criar a nota "' + no.rotulo + '"?')) {
        const r = await api('nota.salvar', { titulo: no.rotulo, conteudo: '', espaco_id: espacoAtivo });
        Grafo.fechar();
        await carregar(true);
        await abrir(r.id);
      }
    },
  });
}

document.getElementById('abrir-grafo').addEventListener('click', abrirGrafo);
document.getElementById('fechar-grafo').addEventListener('click', () => Grafo.fechar());

document.getElementById('grafo-local').addEventListener('change', (ev) => {
  document.getElementById('grafo-prof-caixa').classList.toggle('desligado', !ev.target.checked);
  Grafo.modo(ev.target.checked, parseInt(document.getElementById('grafo-prof').value, 10));
});

document.getElementById('grafo-prof').addEventListener('change', (ev) => {
  Grafo.modo(document.getElementById('grafo-local').checked, parseInt(ev.target.value, 10));
});

document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape' && Grafo.aberto()) Grafo.fechar();
  if ((ev.metaKey || ev.ctrlKey) && ev.key === 'g') { ev.preventDefault(); abrirGrafo(); }
});

// --------------------------------------------------------------- início

const cacheInicial = recuperar('mb_estado');
if (cacheInicial) { estado = normalizarEstado(cacheInicial); desenhar(); }

// Retoma o modo da última sessão, mas nunca cai no dividido em tela estreita.
const modoSalvo = recuperar('mb_modo');
aplicarModo(
  (modoSalvo === 'dividido' && ehEstreito()) ? 'ler'
    : (BOTAO_MODO[modoSalvo] ? modoSalvo : 'escrever')
);

carregar();

// ---------------------------------------------------------- agenda

async function abrirAgenda() {
  await salvarJa();
  try {
    await Agenda.abrir({ api: api });
  } catch (e) {
    marcar('falhou', 'sem conexão');
  }
}

document.getElementById('abrir-agenda').addEventListener('click', abrirAgenda);
document.getElementById('fechar-agenda').addEventListener('click', () => Agenda.fechar());
document.getElementById('ag-anterior').addEventListener('click', () => Agenda.mover(-1));
document.getElementById('ag-proximo').addEventListener('click', () => Agenda.mover(1));
document.getElementById('ag-hoje').addEventListener('click', () => Agenda.irParaHoje());
document.getElementById('ag-rotinas').addEventListener('click', () => Agenda.alternarRotinas());
document.getElementById('ag-celular').addEventListener('click', () => Agenda.painelCelular(api));

document.addEventListener('keydown', (ev) => {
  if (ev.key === 'Escape' && Agenda.aberto()) Agenda.fechar();
  if ((ev.metaKey || ev.ctrlKey) && ev.key === 'j') { ev.preventDefault(); abrirAgenda(); }
});

// ------------------------------------------------------- pendências

/**
 * As caixas "- [ ]" espalhadas pelas anotações, reunidas num lugar só.
 *
 * Não há lista de tarefas separada de propósito: a tarefa nasce dentro da
 * anotação da aula, no contexto dela. Marcar aqui reescreve o "[ ]" na nota —
 * a anotação continua sendo a fonte da verdade, não um registro paralelo.
 *
 *   - [ ] revisar Krebs
 *   - [ ] ! entregar relatório        (o ! marca urgente)
 *   - [ ] ! prova @2026-09-10         (o @data joga na agenda)
 */
let pendencias = [];

async function carregarPendencias() {
  try {
    const r = await api('pendencias');
    pendencias = r.pendencias || [];
  } catch (e) {
    pendencias = [];
  }
  desenharPendencias();
}

function desenharPendencias(todas) {
  const ul = document.getElementById('pendencias');
  ul.textContent = '';

  const lista = todas ? pendencias : pendencias.slice(0, 6);

  if (lista.length === 0) {
    const li = document.createElement('li');
    li.className = 'nenhum';
    li.textContent = 'Nada pendente.';
    ul.appendChild(li);
    return;
  }

  const hojeStr = new Date().toISOString().slice(0, 10);

  lista.forEach((p) => {
    const li = document.createElement('li');
    li.className = 'pendencia';
    if (p.urgente) li.classList.add('urgente');

    const cx = document.createElement('input');
    cx.type = 'checkbox';
    cx.title = 'Concluir';
    cx.addEventListener('click', async (ev) => {
      ev.stopPropagation();
      try {
        await api('pendencia.marcar', {
          nota_id: p.nota_id, linha: p.linha, bruto: p.bruto, feita: true,
        });
      } catch (e) {
        // 409: a nota mudou desde que a lista carregou.
        marcar('falhou', 'nota mudou');
      }
      await carregarPendencias();
      if (notaAberta && notaAberta.id === p.nota_id) await abrir(p.nota_id);
    });
    li.appendChild(cx);

    const rot = document.createElement('span');
    rot.className = 'rotulo';
    rot.textContent = p.texto;
    li.appendChild(rot);

    if (p.prazo) {
      const pz = document.createElement('span');
      pz.className = 'prazo';
      if (p.prazo < hojeStr) pz.classList.add('atrasado');
      pz.textContent = p.prazo.slice(8) + '/' + p.prazo.slice(5, 7);
      li.appendChild(pz);
    }

    li.title = 'em ' + p.nota;
    li.addEventListener('click', () => abrir(p.nota_id));
    ul.appendChild(li);
  });

  if (!todas && pendencias.length > lista.length) {
    const li = document.createElement('li');
    li.className = 'nenhum';
    li.textContent = '+ ' + (pendencias.length - lista.length) + ' pendentes';
    li.addEventListener('click', () => desenharPendencias(true));
    ul.appendChild(li);
  }
}

document.getElementById('ver-pendencias').addEventListener('click', () => desenharPendencias(true));

carregarPendencias();
