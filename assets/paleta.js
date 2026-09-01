'use strict';

/**
 * Paleta de comandos: uma caixa que acha qualquer coisa e leva até lá.
 *
 * A busca é no servidor porque o conteúdo das notas não fica no cliente — a
 * lista lateral traz só títulos, de propósito, para não baixar o acervo
 * inteiro a cada carga.
 *
 * O realce sai por textContent em três pedaços (antes, achado, depois) em vez
 * de innerHTML com <mark>. Trecho de nota é conteúdo do usuário; montar HTML
 * com ele seria abrir a porta que o resto do sistema fecha.
 */
window.Paleta = (function () {

  let api = null, acoes = [], aoEscolher = null;
  let itens = [];       // { rotulo, detalhe, grupo, executar }
  let ativo = 0;
  let timer = null;
  let ultimoTermo = '';

  const cx = () => document.getElementById('paleta');
  const campo = () => document.getElementById('paleta-campo');
  const lista = () => document.getElementById('paleta-lista');

  function abrir() {
    cx().classList.remove('oculto');
    campo().value = '';
    campo().focus();
    ultimoTermo = '';
    montar({ notas: [], espacos: [], pendencias: [] }, '');
  }

  function fechar() {
    cx().classList.add('oculto');
    clearTimeout(timer);
  }

  function aberta() {
    return !cx().classList.contains('oculto');
  }

  /** Divide o texto no ponto do achado, sem construir HTML. */
  function comRealce(texto, realce) {
    const frag = document.createDocumentFragment();

    if (!realce) {
      frag.appendChild(document.createTextNode(texto));
      return frag;
    }

    const [ini, tam] = realce;
    // O trecho pode ter ganho um "…" na frente; o offset veio do texto cru.
    const desloc = texto.startsWith('…') ? 1 : 0;
    const a = ini + desloc;

    if (a < 0 || a + tam > texto.length) {
      frag.appendChild(document.createTextNode(texto));
      return frag;
    }

    frag.appendChild(document.createTextNode(texto.slice(0, a)));
    const forte = document.createElement('mark');
    forte.textContent = texto.slice(a, a + tam);
    frag.appendChild(forte);
    frag.appendChild(document.createTextNode(texto.slice(a + tam)));

    return frag;
  }

  function montar(r, termo) {
    itens = [];

    // Sem termo, mostra o que dá para fazer. Paleta vazia não ensina nada.
    if (!termo) {
      acoes.forEach((a) => itens.push({ rotulo: a.rotulo, detalhe: a.atalho || '', grupo: 'Ir para', executar: a.executar }));
    }

    (r.espacos || []).forEach((e) => itens.push({
      rotulo: e.nome, detalhe: e.tipo, grupo: 'Espaços', cor: e.cor,
      executar: () => aoEscolher({ tipo: 'espaco', id: e.id }),
    }));

    (r.notas || []).forEach((n) => itens.push({
      rotulo: n.titulo, detalhe: n.trecho, realce: n.realce, grupo: 'Anotações',
      executar: () => aoEscolher({ tipo: 'nota', id: n.id }),
    }));

    (r.pendencias || []).forEach((p) => itens.push({
      rotulo: p.texto, detalhe: 'em ' + p.nota, grupo: 'Pendências', urgente: p.urgente,
      executar: () => aoEscolher({ tipo: 'nota', id: p.nota_id }),
    }));

    ativo = 0;
    desenhar(termo);
  }

  function desenhar(termo) {
    const ul = lista();
    ul.textContent = '';

    if (itens.length === 0) {
      const li = document.createElement('li');
      li.className = 'pl-vazio';
      li.textContent = termo.length < 2 ? 'Digite ao menos 2 letras.' : 'Nada encontrado.';
      ul.appendChild(li);
      return;
    }

    let grupoAtual = null;
    itens.forEach((it, i) => {
      if (it.grupo !== grupoAtual) {
        grupoAtual = it.grupo;
        const cab = document.createElement('li');
        cab.className = 'pl-grupo';
        cab.textContent = grupoAtual;
        ul.appendChild(cab);
      }

      const li = document.createElement('li');
      li.className = 'pl-item';
      if (i === ativo) li.classList.add('ativo');
      if (it.urgente) li.classList.add('urgente');

      if (it.cor) {
        const p = document.createElement('span');
        p.className = 'ponto';
        p.style.background = it.cor;
        li.appendChild(p);
      }

      const meio = document.createElement('div');
      meio.className = 'pl-meio';

      const r = document.createElement('span');
      r.className = 'pl-rotulo';
      r.textContent = it.rotulo;
      meio.appendChild(r);

      if (it.detalhe) {
        const d = document.createElement('span');
        d.className = 'pl-detalhe';
        d.appendChild(comRealce(it.detalhe, it.realce));
        meio.appendChild(d);
      }

      li.appendChild(meio);
      li.addEventListener('mousedown', (ev) => { ev.preventDefault(); escolher(i); });
      li.addEventListener('mousemove', () => { if (ativo !== i) { ativo = i; desenhar(termo); } });
      ul.appendChild(li);
    });

    const sel = ul.querySelector('.pl-item.ativo');
    if (sel) sel.scrollIntoView({ block: 'nearest' });
  }

  function escolher(i) {
    const it = itens[i];
    if (!it) return;
    fechar();
    it.executar();
  }

  async function procurar(termo) {
    if (termo.trim().length < 2) {
      montar({ notas: [], espacos: [], pendencias: [] }, termo);
      return;
    }

    try {
      const r = await api('buscar', null, { q: termo });
      // Resposta atrasada de um termo antigo não pode sobrescrever a atual.
      if (r.termo !== ultimoTermo) return;
      montar(r, termo);
    } catch (e) {
      montar({ notas: [], espacos: [], pendencias: [] }, termo);
    }
  }

  function instalar() {
    campo().addEventListener('input', () => {
      const t = campo().value;
      ultimoTermo = t;
      clearTimeout(timer);
      timer = setTimeout(() => procurar(t), 160);
    });

    campo().addEventListener('keydown', (ev) => {
      const itensNav = itens.length;
      if (ev.key === 'Escape') { ev.preventDefault(); fechar(); }
      if (ev.key === 'Enter') { ev.preventDefault(); escolher(ativo); }
      if (ev.key === 'ArrowDown') { ev.preventDefault(); ativo = (ativo + 1) % itensNav; desenhar(campo().value); }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); ativo = (ativo - 1 + itensNav) % itensNav; desenhar(campo().value); }
    });

    cx().addEventListener('mousedown', (ev) => {
      if (ev.target === cx()) fechar();
    });
  }

  return {
    iniciar(opcoes) {
      api = opcoes.api;
      acoes = opcoes.acoes || [];
      aoEscolher = opcoes.aoEscolher;
      instalar();
    },
    abrir: abrir,
    fechar: fechar,
    aberta: aberta,
  };
})();
