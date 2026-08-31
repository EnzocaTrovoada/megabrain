'use strict';

/**
 * Markdown -> HTML, subconjunto suficiente para anotação de aula.
 *
 * A ordem importa: escapamos &, < e > ANTES de qualquer coisa. Depois disso
 * nada que veio do texto pode virar marcação, então não há como injetar HTML —
 * é a mesma garantia que um sanitizador daria, sem a dependência.
 *
 * Suporta: # títulos, **negrito**, *itálico*, ~~riscado~~, `código`, blocos ```,
 * listas, citações, tabelas, linha ---, [texto](url), ![](arquivo:ID) e
 * [[wikilinks]].
 */
window.Markdown = (function () {

  function escapar(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function urlSegura(u) {
    // javascript:, data: e vbscript: em href são o vetor clássico. Só passa
    // o que é claramente inofensivo.
    const limpa = u.trim();
    if (/^arquivo:[0-9a-f]{4,32}$/i.test(limpa)) return limpa;
    if (/^(https?:\/\/|mailto:|#|\.?\/)/i.test(limpa)) return limpa;
    return null;
  }

  function urlDoArquivo(ref, miniatura) {
    const id = ref.slice('arquivo:'.length);
    return 'api.php?a=arquivo&amp;id=' + id + (miniatura ? '&amp;t=1' : '');
  }

  function embutido(texto) {
    let t = texto;

    // Imagem antes de link: a sintaxe do link é sufixo da da imagem.
    t = t.replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, (m, alt, src) => {
      const s = urlSegura(src);
      if (!s) return m;
      const url = s.startsWith('arquivo:') ? urlDoArquivo(s, false) : s;
      return '<img src="' + url + '" alt="' + alt + '" loading="lazy">';
    });

    t = t.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, txt, href) => {
      const h = urlSegura(href);
      if (!h) return m;
      const externo = /^https?:/i.test(h);
      return '<a href="' + h + '"' + (externo ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + txt + '</a>';
    });

    // [[Nota]] e [[Nota|apelido]] — o clique é tratado pelo app, não pelo href.
    t = t.replace(/\[\[([^\]|\n]+)(?:\|([^\]\n]+))?\]\]/g, (m, alvo, apelido) => {
      const rotulo = (apelido || alvo).trim();
      return '<a class="wikilink" data-nota="' + alvo.trim().replace(/"/g, '&quot;') + '">' + rotulo + '</a>';
    });

    t = t.replace(/`([^`\n]+)`/g, '<code>$1</code>');
    t = t.replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    t = t.replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    t = t.replace(/~~([^~\n]+)~~/g, '<del>$1</del>');

    return t;
  }

  function tabela(linhas) {
    const celulas = (l) => l.replace(/^\||\|$/g, '').split('|').map((c) => c.trim());

    let html = '<table><thead><tr>';
    celulas(linhas[0]).forEach((c) => { html += '<th>' + embutido(c) + '</th>'; });
    html += '</tr></thead><tbody>';

    for (let i = 2; i < linhas.length; i++) {
      html += '<tr>';
      celulas(linhas[i]).forEach((c) => { html += '<td>' + embutido(c) + '</td>'; });
      html += '</tr>';
    }

    return html + '</tbody></table>';
  }

  function renderizar(fonte) {
    const linhas = escapar(fonte || '').split('\n');
    let html = '';
    let i = 0;

    while (i < linhas.length) {
      const l = linhas[i];

      // ``` bloco de código: nada dentro é interpretado
      if (/^\s*```/.test(l)) {
        const idioma = l.replace(/^\s*```/, '').trim();
        const corpo = [];
        i++;
        while (i < linhas.length && !/^\s*```/.test(linhas[i])) corpo.push(linhas[i++]);
        i++;
        html += '<pre' + (idioma ? ' data-idioma="' + idioma + '"' : '') + '><code>'
              + corpo.join('\n') + '</code></pre>';
        continue;
      }

      if (/^\s*(---+|\*\*\*+)\s*$/.test(l)) { html += '<hr>'; i++; continue; }

      const tit = l.match(/^(#{1,6})\s+(.*)$/);
      if (tit) {
        const n = tit[1].length;
        html += '<h' + n + '>' + embutido(tit[2]) + '</h' + n + '>';
        i++;
        continue;
      }

      // tabela: cabeçalho + linha de separação com hífens
      if (/^\s*\|.*\|\s*$/.test(l) && i + 1 < linhas.length && /^\s*\|[\s:|-]+\|\s*$/.test(linhas[i + 1])) {
        const bloco = [];
        while (i < linhas.length && /^\s*\|.*\|\s*$/.test(linhas[i])) bloco.push(linhas[i++]);
        html += tabela(bloco);
        continue;
      }

      // Citação procura &gt;, não >: a essa altura o texto já foi escapado, e
      // o > é o único caractere que é sintaxe de markdown E precisa de escape.
      if (/^\s*&gt;\s?/.test(l)) {
        const bloco = [];
        while (i < linhas.length && /^\s*&gt;\s?/.test(linhas[i])) {
          bloco.push(linhas[i++].replace(/^\s*&gt;\s?/, ''));
        }
        html += '<blockquote>' + embutido(bloco.join(' ')) + '</blockquote>';
        continue;
      }

      const marcador = l.match(/^(\s*)([-*+]|\d+\.)\s+/);
      if (marcador) {
        const ordenada = /\d/.test(marcador[2]);
        const tag = ordenada ? 'ol' : 'ul';
        html += '<' + tag + '>';
        while (i < linhas.length) {
          const m = linhas[i].match(/^(\s*)([-*+]|\d+\.)\s+(.*)$/);
          if (!m || (/\d/.test(m[2]) !== ordenada)) break;

          let item = m[3];
          // [ ] e [x] no começo viram caixa de seleção, como no Obsidian.
          const tarefa = item.match(/^\[( |x|X)\]\s+(.*)$/);
          if (tarefa) {
            const marcado = tarefa[1].toLowerCase() === 'x';
            item = '<input type="checkbox" disabled' + (marcado ? ' checked' : '') + '> '
                 + (marcado ? '<s>' + embutido(tarefa[2]) + '</s>' : embutido(tarefa[2]));
          } else {
            item = embutido(item);
          }

          html += '<li>' + item + '</li>';
          i++;
        }
        html += '</' + tag + '>';
        continue;
      }

      if (l.trim() === '') { i++; continue; }

      // parágrafo: junta até a próxima linha em branco ou bloco novo
      const par = [];
      while (
        i < linhas.length
        && linhas[i].trim() !== ''
        && !/^(\s*```|#{1,6}\s|\s*&gt;|\s*([-*+]|\d+\.)\s|\s*\|)/.test(linhas[i])
        && !/^\s*(---+|\*\*\*+)\s*$/.test(linhas[i])
      ) {
        par.push(linhas[i++]);
      }
      if (par.length) html += '<p>' + embutido(par.join(' ')) + '</p>';
      else i++;
    }

    return html;
  }

  return { renderizar: renderizar };
})();
