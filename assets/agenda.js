'use strict';

/**
 * Agenda: mês para navegar, dia para usar.
 *
 * O mês inteiro vem numa requisição só e o clique num dia só troca o painel da
 * direita — trocar de dia não pode custar ida ao servidor.
 *
 * Datas andam como texto 'YYYY-MM-DD' de ponta a ponta. Passar por Date e
 * voltar é onde nasce o bug clássico de o dia aparecer deslocado por fuso.
 */
window.Agenda = (function () {

  const SEMANA = ['seg', 'ter', 'qua', 'qui', 'sex', 'sáb', 'dom'];
  const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

  let api = null;
  let mes = null;            // 'YYYY-MM'
  let selecionada = null;    // 'YYYY-MM-DD'
  let dias = {};
  let espacos = [];
  let rotinas = [];

  // ------------------------------------------------------------- datas

  function hoje() {
    const d = new Date();
    return iso(d.getFullYear(), d.getMonth() + 1, d.getDate());
  }

  function iso(a, m, d) {
    return a + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
  }

  function partes(data) {
    const [a, m, d] = data.split('-').map(Number);
    return { a: a, m: m, d: d };
  }

  /** Segunda = 0. getDay() dá domingo = 0, que não é como se lê um calendário. */
  function diaDaSemana(data) {
    const p = partes(data);
    return (new Date(p.a, p.m - 1, p.d).getDay() + 6) % 7;
  }

  function diasNoMes(a, m) {
    return new Date(a, m, 0).getDate();
  }

  function porExtenso(data) {
    const p = partes(data);
    return p.d + ' de ' + MESES[p.m - 1] + ' de ' + p.a;
  }

  // ------------------------------------------------------------ dados

  async function carregarMes(novoMes) {
    mes = novoMes;
    const [a, m] = mes.split('-').map(Number);

    const r = await api('agenda', null, { de: iso(a, m, 1), ate: iso(a, m, diasNoMes(a, m)) });
    dias = r.dias || {};
    espacos = r.espacos || [];
    rotinas = r.rotinas || [];

    desenharMes();
    if (selecionada && selecionada.slice(0, 7) === mes) desenharDia();
  }

  function corDoEspaco(id) {
    const m = espacos.find((x) => x.id === id);
    return m ? (m.cor || '#8b5cf6') : null;
  }

  function nomeDoEspaco(id) {
    const m = espacos.find((x) => x.id === id);
    return m ? m.nome : null;
  }

  // ------------------------------------------------------------- mês

  function desenharMes() {
    const [a, m] = mes.split('-').map(Number);
    document.getElementById('agenda-mes').textContent = MESES[m - 1] + ' de ' + a;

    const grade = document.getElementById('agenda-grade');
    grade.textContent = '';

    SEMANA.forEach((s) => {
      const c = document.createElement('div');
      c.className = 'ag-cabeca';
      c.textContent = s;
      grade.appendChild(c);
    });

    // Espaços até o primeiro dia cair na coluna certa.
    const primeiro = diaDaSemana(iso(a, m, 1));
    for (let i = 0; i < primeiro; i++) {
      grade.appendChild(document.createElement('div'));
    }

    for (let d = 1; d <= diasNoMes(a, m); d++) {
      const data = iso(a, m, d);
      const info = dias[data] || { itens: [], feriados: null };

      const cel = document.createElement('button');
      cel.className = 'ag-dia';
      cel.type = 'button';
      if (data === hoje()) cel.classList.add('hoje');
      if (data === selecionada) cel.classList.add('sel');
      if (info.feriados) cel.classList.add('feriado');

      const n = document.createElement('span');
      n.className = 'ag-num';
      n.textContent = String(d);
      cel.appendChild(n);

      if (info.feriados) {
        cel.title = info.feriados.map((f) => f.nome).join(' · ');
      }

      const pontos = document.createElement('span');
      pontos.className = 'ag-pontos';
      // No máximo quatro pontos: além disso vira sujeira e não informa mais.
      info.itens.slice(0, 4).forEach((it) => {
        const p = document.createElement('i');
        if (it.origem === 'avaliacao') p.className = 'prova';
        else if (it.origem === 'compromisso') p.className = it.concluido ? 'feito' : 'tarefa';
        const cor = corDoEspaco(it.espaco_id);
        if (cor && it.origem !== 'avaliacao') p.style.background = cor;
        pontos.appendChild(p);
      });
      cel.appendChild(pontos);

      cel.addEventListener('click', () => { selecionada = data; desenharMes(); desenharDia(); });
      grade.appendChild(cel);
    }
  }

  // -------------------------------------------------------------- dia

  function desenharDia() {
    const painel = document.getElementById('agenda-dia');
    painel.textContent = '';

    if (!selecionada) return;
    const info = dias[selecionada] || { itens: [], feriados: null };

    const cab = document.createElement('header');
    const h = document.createElement('h3');
    h.textContent = porExtenso(selecionada);
    cab.appendChild(h);

    const sub = document.createElement('span');
    sub.className = 'ag-subtitulo';
    const i = diaDaSemana(selecionada);
    sub.textContent = ['segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira',
                       'sexta-feira', 'sábado', 'domingo'][i];
    cab.appendChild(sub);
    painel.appendChild(cab);

    if (info.feriados) {
      const av = document.createElement('div');
      av.className = 'ag-feriado';
      av.textContent = info.feriados.map((f) => f.nome).join(' · ') + ' — sem aula';
      painel.appendChild(av);
    }

    const lista = document.createElement('ul');
    lista.className = 'ag-itens';

    if (info.itens.length === 0) {
      const li = document.createElement('li');
      li.className = 'ag-vazio';
      li.textContent = 'Nada marcado.';
      lista.appendChild(li);
    }

    info.itens.forEach((it) => lista.appendChild(linhaItem(it)));
    painel.appendChild(lista);

    painel.appendChild(formularioCompromisso());
  }

  function linhaItem(it) {
    const li = document.createElement('li');
    li.className = 'ag-item ' + it.origem;

    const hora = document.createElement('span');
    hora.className = 'ag-hora';
    if (it.dia_inteiro) hora.textContent = 'dia';
    else if (it.hora && it.hora_fim) { hora.textContent = it.hora; hora.title = it.hora + ' às ' + it.hora_fim; }
    else hora.textContent = it.hora || '—';
    li.appendChild(hora);

    const meio = document.createElement('div');
    meio.className = 'ag-meio';

    const t = document.createElement('span');
    t.className = 'ag-titulo';
    t.textContent = it.titulo;
    if (it.concluido) t.classList.add('feito');
    meio.appendChild(t);

    const detalhes = [];
    if (it.origem === 'avaliacao') {
      detalhes.push(it.lancada ? 'nota já lançada' : 'vale ' + it.peso_media + '% da média');
    }
    if (it.local) detalhes.push(it.local);
    if (it.hora_fim) detalhes.push('até ' + it.hora_fim);
    if (it.origem === 'pendencia') detalhes.push('em ' + it.nota);
    const nomeEsp = nomeDoEspaco(it.espaco_id);
    if (nomeEsp) detalhes.push(nomeEsp);

    if (detalhes.length) {
      const d = document.createElement('span');
      d.className = 'ag-detalhe';
      d.textContent = detalhes.join(' · ');
      meio.appendChild(d);
    }
    li.appendChild(meio);

    const cor = corDoEspaco(it.espaco_id);
    if (cor) li.style.borderLeftColor = cor;

    if (it.origem === 'compromisso') {
      li.appendChild(botao(it.concluido ? '↺' : '✓', it.concluido ? 'Desmarcar' : 'Concluir', async () => {
        await api('compromisso.salvar', {
          id: it.ref, titulo: it.titulo, data: selecionada,
          hora: it.hora, espaco_id: it.espaco_id, concluido: !it.concluido,
        });
        await carregarMes(mes);
      }));
      li.appendChild(botao('×', 'Excluir', async () => {
        await api('compromisso.excluir', { id: it.ref });
        await carregarMes(mes);
      }));
    }

    if (it.origem === 'rotina') {
      li.appendChild(botao('×', 'Cancelar só neste dia', async () => {
        await api('rotina.excecao', { rotina_id: it.ref, data: selecionada, acao: 'cancelada' });
        await carregarMes(mes);
      }));
    }

    return li;
  }

  function botao(rotulo, titulo, aoClicar) {
    const b = document.createElement('button');
    b.className = 'ag-acao';
    b.type = 'button';
    b.textContent = rotulo;
    b.title = titulo;
    b.addEventListener('click', aoClicar);
    return b;
  }

  function formularioCompromisso() {
    const f = document.createElement('form');
    f.className = 'ag-novo';

    const txt = document.createElement('input');
    txt.type = 'text';
    txt.placeholder = 'Adicionar ao dia…';
    txt.required = true;

    const inicio = document.createElement('input');
    inicio.type = 'time';
    inicio.title = 'Início';

    const ate = document.createElement('span');
    ate.className = 'ag-ate';
    ate.textContent = 'até';

    const fim = document.createElement('input');
    fim.type = 'time';
    fim.title = 'Fim';

    const rotDia = document.createElement('label');
    rotDia.className = 'ag-dia-inteiro';
    const inteiro = document.createElement('input');
    inteiro.type = 'checkbox';
    rotDia.append(inteiro, document.createTextNode('dia inteiro'));

    // Dia inteiro e horario sao exclusivos: deixar os dois ativos ao mesmo
    // tempo produziria um evento que se contradiz.
    inteiro.addEventListener('change', () => {
      [inicio, fim].forEach((c) => {
        c.disabled = inteiro.checked;
        if (inteiro.checked) c.value = '';
      });
      ate.classList.toggle('desligado', inteiro.checked);
    });

    const ok = document.createElement('button');
    ok.type = 'submit';
    ok.textContent = '+';

    f.append(txt, inicio, ate, fim, rotDia, ok);

    f.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!txt.value.trim()) return;

      if (inicio.value && fim.value && fim.value <= inicio.value) {
        alert('A hora de fim precisa ser depois da de início.');
        return;
      }

      await api('compromisso.salvar', {
        titulo: txt.value.trim(),
        data: selecionada,
        dia_inteiro: inteiro.checked,
        hora: inteiro.checked ? null : (inicio.value || null),
        hora_fim: inteiro.checked ? null : (fim.value || null),
      });

      txt.value = '';
      inicio.value = fim.value = '';
      await carregarMes(mes);
    });

    return f;
  }

  // ---------------------------------------------------------- rotinas

  async function abrirRotinas() {
    const cx = document.getElementById('agenda-rotinas');
    cx.classList.toggle('oculto');
    if (cx.classList.contains('oculto')) return;

    const r = await api('agenda', null, { de: hoje(), ate: hoje() });
    espacos = r.espacos || [];
    desenharRotinas();
  }

  function desenharRotinas() {
    const cx = document.getElementById('agenda-rotinas');
    cx.textContent = '';

    const h = document.createElement('h3');
    h.textContent = 'Rotinas';
    cx.appendChild(h);

    const p = document.createElement('p');
    p.className = 'ag-subtitulo';
    p.textContent = 'Marque "para em feriado" no que segue calendario academico ou de trabalho.';
    cx.appendChild(p);

    const lista = document.createElement('ul');
    lista.className = 'ag-rotinas';

    rotinas.forEach((r) => {
      const li = document.createElement('li');

      const nome = document.createElement('span');
      nome.className = 'ag-titulo';
      nome.textContent = r.titulo;
      li.appendChild(nome);

      const det = document.createElement('span');
      det.className = 'ag-detalhe';
      det.textContent = diasLegiveis(r.dias_semana)
        + (r.hora_inicio ? ' · ' + r.hora_inicio : '')
        + (r.pula_feriado ? ' · para em feriado' : '');
      li.appendChild(det);

      li.appendChild(botao('×', 'Excluir rotina', async () => {
        if (!confirm('Excluir a rotina "' + r.titulo + '"?')) return;
        await api('rotina.excluir', { id: r.id });
        rotinas = rotinas.filter((x) => x.id !== r.id);
        desenharRotinas();
        await carregarMes(mes);
      }));

      lista.appendChild(li);
    });

    cx.appendChild(lista);
    cx.appendChild(formularioRotina());
  }

  function diasLegiveis(mascara) {
    return SEMANA.filter((_, i) => mascara & (1 << i)).join(' ') || '—';
  }

  function formularioRotina() {
    const f = document.createElement('form');
    f.className = 'ag-nova-rotina';

    const nome = document.createElement('input');
    nome.type = 'text';
    nome.placeholder = 'Nome (ex.: Academia)';
    nome.required = true;

    const caixaDias = document.createElement('div');
    caixaDias.className = 'ag-dias';
    const marcados = [];
    SEMANA.forEach((s, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.textContent = s;
      b.addEventListener('click', () => {
        b.classList.toggle('on');
        const bit = 1 << i;
        const p = marcados.indexOf(bit);
        if (p === -1) marcados.push(bit); else marcados.splice(p, 1);
      });
      caixaDias.appendChild(b);
    });

    const hora = document.createElement('input');
    hora.type = 'time';
    hora.title = 'Início';

    const horaFim = document.createElement('input');
    horaFim.type = 'time';
    horaFim.title = 'Fim';

    // Pergunta direta em vez de "é aula?": um projeto de trabalho também para
    // em feriado, e um hábito pessoal não.
    const rotFeriado = document.createElement('label');
    rotFeriado.className = 'ag-dia-inteiro';
    const pulaFeriado = document.createElement('input');
    pulaFeriado.type = 'checkbox';
    rotFeriado.append(pulaFeriado, document.createTextNode('para em feriado'));

    const mat = document.createElement('select');
    const vazio = document.createElement('option');
    vazio.value = '';
    vazio.textContent = 'sem espaço';
    mat.appendChild(vazio);
    espacos.forEach((m) => {
      const o = document.createElement('option');
      o.value = m.id;
      o.textContent = m.nome;
      mat.appendChild(o);
    });

    const ok = document.createElement('button');
    ok.type = 'submit';
    ok.textContent = 'Criar rotina';

    f.append(nome, caixaDias, hora, horaFim, rotFeriado, mat, ok);

    f.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const mascara = marcados.reduce((a, b) => a + b, 0);
      if (!mascara) { alert('Escolha pelo menos um dia da semana.'); return; }

      const r = await api('rotina.salvar', {
        titulo: nome.value.trim(),
        pula_feriado: pulaFeriado.checked,
        dias_semana: mascara,
        hora_inicio: hora.value || null,
        hora_fim: horaFim.value || null,
        espaco_id: mat.value || null,
      });

      rotinas.push({
        id: r.id, titulo: nome.value.trim(), pula_feriado: pulaFeriado.checked,
        dias_semana: mascara, hora_inicio: hora.value || null,
        hora_fim: horaFim.value || null, espaco_id: mat.value || null,
      });

      desenharRotinas();
      await carregarMes(mes);
    });

    return f;
  }

  // ------------------------------------------------------------ público

  return {
    async abrir(opcoes) {
      api = opcoes.api;
      rotinas = opcoes.rotinas || rotinas;

      document.getElementById('agenda').classList.remove('oculto');

      selecionada = selecionada || hoje();
      await carregarMes(selecionada.slice(0, 7));
      desenharDia();
    },

    fechar() {
      document.getElementById('agenda').classList.add('oculto');
      document.getElementById('agenda-rotinas').classList.add('oculto');
    },

    aberto() {
      return !document.getElementById('agenda').classList.contains('oculto');
    },

    async mover(passo) {
      const [a, m] = mes.split('-').map(Number);
      const d = new Date(a, m - 1 + passo, 1);
      await carregarMes(d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0'));
    },

    async irParaHoje() {
      selecionada = hoje();
      await carregarMes(selecionada.slice(0, 7));
      desenharDia();
    },

    alternarRotinas: abrirRotinas,
    definirRotinas(lista) { rotinas = lista || []; },
  };
})();

/**
 * Assinatura no celular. O token só existe em claro neste instante — depois
 * fica só o SHA-256 no servidor, e não há como mostrá-lo de novo. Por isso a
 * URL é exibida numa caixa selecionável em vez de sumir num alerta.
 */
window.Agenda.painelCelular = async function (api) {
  const cx = document.getElementById('agenda-rotinas');
  cx.classList.remove('oculto');
  cx.textContent = '';

  const h = document.createElement('h3');
  h.textContent = 'Assinar no celular';
  cx.appendChild(h);

  const p = document.createElement('p');
  p.className = 'ag-subtitulo';
  p.textContent = 'Gera um link só de leitura. O calendário do celular sincroniza sozinho e avisa da prova na véspera.';
  cx.appendChild(p);

  const lista = document.createElement('ul');
  lista.className = 'ag-rotinas';
  cx.appendChild(lista);

  async function recarregar() {
    const r = await api('feed.listar');
    lista.textContent = '';

    (r.feeds || []).forEach((f) => {
      const li = document.createElement('li');

      const nome = document.createElement('span');
      nome.className = 'ag-titulo';
      nome.textContent = f.nome;
      li.appendChild(nome);

      const det = document.createElement('span');
      det.className = 'ag-detalhe';
      det.textContent = f.acessos
        ? f.acessos + ' sincronizações · última ' + (f.ultimo_acesso || '').slice(0, 10)
        : 'nunca sincronizado';
      li.appendChild(det);

      const x = document.createElement('button');
      x.className = 'ag-acao';
      x.textContent = '×';
      x.title = 'Revogar';
      x.addEventListener('click', async () => {
        if (!confirm('Revogar "' + f.nome + '"? O celular para de sincronizar.')) return;
        await api('feed.revogar', { id: f.id });
        await recarregar();
      });
      li.appendChild(x);

      lista.appendChild(li);
    });
  }

  await recarregar();

  const criar = document.createElement('button');
  criar.className = 'ag-criar-feed';
  criar.textContent = 'Gerar link';
  criar.addEventListener('click', async () => {
    const r = await api('feed.criar', { nome: 'Celular', feriados: true });

    const caixa = document.createElement('div');
    caixa.className = 'ag-link';

    const campo = document.createElement('input');
    campo.type = 'text';
    campo.readOnly = true;
    campo.value = r.url;
    campo.addEventListener('focus', () => campo.select());

    const copiar = document.createElement('button');
    copiar.type = 'button';
    copiar.textContent = 'copiar';
    copiar.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(r.url);
        copiar.textContent = 'copiado';
      } catch (e) {
        campo.select();               // sem permissão de área de transferência
        copiar.textContent = 'Ctrl+C';
      }
    });

    const aviso = document.createElement('p');
    aviso.className = 'ag-subtitulo';
    aviso.textContent = 'Copie agora: este link não aparece de novo. Se vazar, revogue aqui — ele não dá acesso à conta nem permite escrever.';

    caixa.append(campo, copiar);
    cx.append(caixa, aviso);
    criar.remove();

    campo.focus();
    await recarregar();
  });

  cx.appendChild(criar);
};
