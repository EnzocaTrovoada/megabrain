'use strict';

/**
 * Painel da conta: quem você é, quanto espaço usou, trocar senha.
 * Se você for o dono, também os convites e as pessoas que entraram.
 */
window.Conta = (function () {

  let api = null;

  const cx = () => document.getElementById('conta');
  const corpo = () => document.getElementById('conta-corpo');

  function mb(bytes) {
    return (bytes / 1048576).toFixed(bytes < 10485760 ? 1 : 0) + ' MB';
  }

  function secao(titulo) {
    const s = document.createElement('section');
    s.className = 'conta-bloco';
    const h = document.createElement('h3');
    h.textContent = titulo;
    s.appendChild(h);
    corpo().appendChild(s);
    return s;
  }

  function campo(rotulo, tipo) {
    const l = document.createElement('label');
    l.textContent = rotulo;
    const i = document.createElement('input');
    i.type = tipo;
    if (tipo === 'password') i.autocomplete = 'new-password';
    l.appendChild(i);
    return { rotulo: l, campo: i };
  }

  function aviso(pai, texto, erro) {
    const p = document.createElement('p');
    p.className = erro ? 'erro' : 'ag-subtitulo';
    p.textContent = texto;
    pai.appendChild(p);
    return p;
  }

  async function desenhar() {
    corpo().textContent = '';

    const c = await api('conta');

    // ------------------------------------------------------- identidade
    const id = secao('Sua conta');

    const linha = document.createElement('p');
    linha.className = 'conta-eu';
    linha.textContent = (c.nome || c.apelido) + '  ·  @' + c.apelido
      + '  ·  ' + (c.dono ? 'dono' : 'membro');
    id.appendChild(linha);

    const uso = document.createElement('div');
    uso.className = 'conta-uso';
    const barra = document.createElement('span');
    // Nunca passa de 100%: barra estourando parece defeito, não aviso.
    barra.style.width = Math.min(100, (c.usado / c.quota) * 100) + '%';
    if (c.usado / c.quota > 0.9) barra.classList.add('cheio');
    uso.appendChild(barra);
    id.appendChild(uso);

    aviso(id, mb(c.usado) + ' de ' + mb(c.quota) + ' em imagens · '
      + c.anotacoes + ' anotações · ' + c.espacos + ' espaços');

    // ---------------------------------------------------------- senha
    const sn = secao('Trocar senha');
    const atual = campo('Senha atual', 'password');
    const nova  = campo('Nova senha (mínimo 10)', 'password');
    sn.append(atual.rotulo, nova.rotulo);

    const bt = document.createElement('button');
    bt.className = 'ag-criar-feed';
    bt.textContent = 'Trocar';
    bt.addEventListener('click', async () => {
      try {
        await api('conta.senha', { atual: atual.campo.value, nova: nova.campo.value });
        atual.campo.value = nova.campo.value = '';
        aviso(sn, 'Senha trocada.');
      } catch (e) {
        aviso(sn, 'Não deu: confira a senha atual e o tamanho da nova.', true);
      }
    });
    sn.appendChild(bt);

    if (!c.dono) return;

    // -------------------------------------------------------- convites
    const cv = secao('Convites');
    aviso(cv, 'Quem tem o código cria a conta e passa a ter o próprio espaço, separado do seu. Vence em 7 dias.');

    const lista = document.createElement('ul');
    lista.className = 'ag-rotinas';
    cv.appendChild(lista);

    const pessoas = secao('Pessoas');
    const listaP = document.createElement('ul');
    listaP.className = 'ag-rotinas';
    pessoas.appendChild(listaP);

    async function recarregar() {
      const r = await api('convite.listar');

      lista.textContent = '';
      if ((r.convites || []).length === 0) {
        const li = document.createElement('li');
        li.className = 'ag-detalhe';
        li.textContent = 'Nenhum convite ainda.';
        lista.appendChild(li);
      }

      (r.convites || []).forEach((k) => {
        const li = document.createElement('li');

        const t = document.createElement('span');
        t.className = 'ag-titulo';
        t.textContent = k.nota || 'sem anotação';
        li.appendChild(t);

        const d = document.createElement('span');
        d.className = 'ag-detalhe';
        d.textContent = k.usado_por ? 'usado por @' + k.usado_por
          : (k.vencido ? 'vencido' : 'aguardando');
        li.appendChild(d);

        if (!k.usado_por) {
          const x = document.createElement('button');
          x.className = 'ag-acao';
          x.textContent = '×';
          x.title = 'Apagar convite';
          x.addEventListener('click', async () => {
            await api('convite.revogar', { id: k.id });
            await recarregar();
          });
          li.appendChild(x);
        }

        lista.appendChild(li);
      });

      listaP.textContent = '';
      (r.usuarios || []).forEach((u) => {
        const li = document.createElement('li');

        const t = document.createElement('span');
        t.className = 'ag-titulo';
        t.textContent = (u.nome || u.apelido) + ' @' + u.apelido;
        if (!u.ativo) t.style.textDecoration = 'line-through';
        li.appendChild(t);

        const d = document.createElement('span');
        d.className = 'ag-detalhe';
        d.textContent = u.papel + (u.ativo ? '' : ' · desativado');
        li.appendChild(d);

        if (u.papel !== 'dono' && u.ativo) {
          const x = document.createElement('button');
          x.className = 'ag-acao';
          x.textContent = '×';
          x.title = 'Desativar';
          x.addEventListener('click', async () => {
            if (!confirm('Desativar @' + u.apelido + '?\nA pessoa perde o acesso. Os dados dela ficam no servidor.')) return;
            await api('usuario.desativar', { id: u.id });
            await recarregar();
          });
          li.appendChild(x);
        }

        listaP.appendChild(li);
      });
    }

    await recarregar();

    const gerar = document.createElement('button');
    gerar.className = 'ag-criar-feed';
    gerar.textContent = 'Criar convite';
    gerar.addEventListener('click', async () => {
      const nota = prompt('Para quem é? (só pra você lembrar)') || '';
      const r = await api('convite.criar', { nota: nota });

      const caixa = document.createElement('div');
      caixa.className = 'ag-link';

      const c1 = document.createElement('input');
      c1.type = 'text';
      c1.readOnly = true;
      c1.value = location.origin + location.pathname + '?convite=' + r.codigo;
      c1.addEventListener('focus', () => c1.select());

      const cp = document.createElement('button');
      cp.type = 'button';
      cp.textContent = 'copiar';
      cp.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(c1.value);
          cp.textContent = 'copiado';
        } catch (e) {
          c1.select();
          cp.textContent = 'Ctrl+C';
        }
      });

      caixa.append(c1, cp);
      cv.appendChild(caixa);
      // O código não é guardado em claro: some daqui, some de vez.
      aviso(cv, 'Mande esse link. O código é ' + r.codigo + ' e não aparece de novo.');

      await recarregar();
      c1.focus();
    });
    cv.appendChild(gerar);
  }

  return {
    iniciar(opcoes) {
      api = opcoes.api;

      document.getElementById('fechar-conta').addEventListener('click', () => cx().classList.add('oculto'));
      cx().addEventListener('mousedown', (ev) => {
        if (ev.target === cx()) cx().classList.add('oculto');
      });
    },

    async abrir() {
      cx().classList.remove('oculto');
      corpo().textContent = 'carregando…';
      try {
        await desenhar();
      } catch (e) {
        corpo().textContent = 'Não consegui carregar a conta.';
      }
    },

    aberta() {
      return !cx().classList.contains('oculto');
    },
  };
})();
