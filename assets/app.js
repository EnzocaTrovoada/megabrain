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
  materias: document.getElementById('materias'),
  notas: document.getElementById('notas'),
  tituloNotas: document.getElementById('titulo-notas'),
  busca: document.getElementById('busca'),
  titulo: document.getElementById('titulo'),
  editor: document.getElementById('editor'),
  seletor: document.getElementById('materia-da-nota'),
  estado: document.getElementById('estado'),
  vazio: document.getElementById('vazio'),
  backlinks: document.getElementById('backlinks'),
  sugestoes: document.getElementById('sugestoes'),
  leitura: document.getElementById('leitura'),
};

let estado = { materias: [], anotacoes: [] };
let materiaAtiva = null;   // null = todas
let notaAberta = null;     // { id, titulo, conteudo, materia_id }
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

function corDaMateria(id) {
  const m = estado.materias.find((x) => x.id === id);
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

function desenharMaterias() {
  el.materias.textContent = '';

  el.materias.appendChild(item('Todas', {
    ativa: materiaAtiva === null,
    aoClicar: () => { materiaAtiva = null; desenhar(); },
  }));

  estado.materias.forEach((m) => {
    el.materias.appendChild(item(m.nome, {
      cor: m.cor,
      ativa: materiaAtiva === m.id,
      aoClicar: () => { materiaAtiva = m.id; desenhar(); },
      aoTrocarCor: async () => {
        const i = CORES.indexOf(m.cor);
        await api('materia.salvar', { id: m.id, nome: m.nome, cor: CORES[(i + 1) % CORES.length] });
        await carregar();
      },
      aoEditar: async () => {
        const nome = prompt('Nome da matéria', m.nome);
        if (nome === null || !nome.trim() || nome.trim() === m.nome) return;
        await api('materia.salvar', { id: m.id, nome: nome.trim(), cor: m.cor });
        await carregar();
      },
      aoExcluir: async () => {
        if (!confirm('Excluir a matéria "' + m.nome + '"?\nAs anotações dela não são apagadas, ficam sem matéria.')) return;
        await api('materia.excluir', { id: m.id });
        if (materiaAtiva === m.id) materiaAtiva = null;
        await carregar();
      },
    }));
  });
}

function notasVisiveis() {
  const q = el.busca.value.trim().toLowerCase();

  return estado.anotacoes
    .filter((n) => materiaAtiva === null || n.materia_id === materiaAtiva)
    .filter((n) => q === '' || (n.titulo || '').toLowerCase().includes(q))
    .sort((a, b) => (b.atualizado_em || '').localeCompare(a.atualizado_em || ''));
}

function desenharNotas() {
  el.notas.textContent = '';

  const m = estado.materias.find((x) => x.id === materiaAtiva);
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
      cor: n.materia_id ? corDaMateria(n.materia_id) : null,
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
  vazio.textContent = 'sem matéria';
  el.seletor.appendChild(vazio);

  estado.materias.forEach((m) => {
    const o = document.createElement('option');
    o.value = m.id;
    o.textContent = m.nome;
    el.seletor.appendChild(o);
  });

  el.seletor.value = (notaAberta && notaAberta.materia_id) || '';
}

function desenhar() {
  desenharMaterias();
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
  if (lendo) el.leitura.innerHTML = Markdown.renderizar(el.editor.value);
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
    materia_id: el.seletor.value || null,
  };

  // Guarda local antes da rede: se o wifi da faculdade cair, o texto sobrevive.
  guardar('mb_nota_' + notaAberta.id, Object.assign({}, notaAberta, payload));

  try {
    await api('nota.salvar', payload);
    notaAberta.titulo = payload.titulo;
    notaAberta.materia_id = payload.materia_id;
    marcar('salvo', 'salvo');
    await carregar(true);
  } catch (e) {
    sujo = true;
    marcar('falhou', 'sem conexão');
  }
}

// -------------------------------------------------------------- carga

async function carregar(silencioso) {
  try {
    estado = await api('estado');
    guardar('mb_estado', estado);
  } catch (e) {
    const cache = recuperar('mb_estado');
    if (cache) {
      estado = cache;
      if (!silencioso) marcar('falhou', 'offline');
    }
  }
  desenhar();
}

// -------------------------------------------------------------- eventos

el.editor.addEventListener('input', agendar);
el.titulo.addEventListener('input', agendar);
el.seletor.addEventListener('change', () => { sujo = true; salvarJa(); });
el.busca.addEventListener('input', desenharNotas);

document.getElementById('nova-materia').addEventListener('click', async () => {
  const nome = prompt('Nome da matéria');
  if (!nome || !nome.trim()) return;

  await api('materia.salvar', {
    nome: nome.trim(),
    cor: CORES[estado.materias.length % CORES.length],
  });
  await carregar();
});

document.getElementById('nova-nota').addEventListener('click', async () => {
  await salvarJa();
  const r = await api('nota.salvar', {
    titulo: '',
    conteudo: '',
    materia_id: materiaAtiva,
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
  if ((ev.metaKey || ev.ctrlKey) && ev.key === 'e') { ev.preventDefault(); alternarLeitura(); }
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

let lendo = false;

function alternarLeitura(forcar) {
  lendo = forcar === undefined ? !lendo : forcar;

  if (lendo) {
    el.leitura.innerHTML = Markdown.renderizar(el.editor.value);
  }

  el.editor.classList.toggle('oculto', lendo);
  el.leitura.classList.toggle('oculto', !lendo);
  document.getElementById('alternar-ler').classList.toggle('ligado', lendo);

  if (!lendo) el.editor.focus();
}

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
    const r = await api('nota.salvar', { titulo: alvo, conteudo: '', materia_id: materiaAtiva });
    await carregar(true);
    await abrir(r.id);
    alternarLeitura(false);
  }
});

/**
 * Mesmo casamento do servidor: sem acento, sem maiúscula.
  return t.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
 * escrito por escape para o arquivo não depender da codificação em que é salvo.
 */
function normalizarTitulo(t) {
  return t.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

document.getElementById('alternar-ler').addEventListener('click', () => alternarLeitura());

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
      if (no.tipo === 'materia') { materiaAtiva = no.ref; Grafo.fechar(); desenhar(); return; }

      // Nó fantasma: link para nota que ainda não existe. Clicar cria.
      if (no.tipo === 'fantasma' && confirm('Criar a nota "' + no.rotulo + '"?')) {
        const r = await api('nota.salvar', { titulo: no.rotulo, conteudo: '', materia_id: materiaAtiva });
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
if (cacheInicial) { estado = cacheInicial; desenhar(); }
carregar();
