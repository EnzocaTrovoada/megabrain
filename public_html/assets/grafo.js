'use strict';

/**
 * Mapa mental — grafo dirigido por forças em canvas, sem biblioteca.
 *
 * Mesma ideia do Obsidian: nó é nota, aresta é link, e o tamanho do nó é
 * proporcional a quantas referências ele recebe. A diferença é que matéria
 * também é nó, então o mapa já nasce com estrutura em vez de pontos soltos.
 *
 * A repulsão é O(n²) de propósito. Barnes-Hut só compensa perto de mil nós, e
 * até lá custa complexidade que nada aqui precisa.
 */
window.Grafo = (function () {

  const FORCA_REPULSAO = 5200;
  const FORCA_MOLA = 0.012;
  const FORCA_CENTRO = 0.006;
  const DISTANCIA_LINK = 90;
  const ATRITO = 0.86;
  const PARADA = 0.06;   // energia abaixo disso: congela e para de desenhar

  let tela, ctx, caixa;
  let nos = [], arestas = [], porId = new Map();
  let camera = { x: 0, y: 0, z: 1 };
  let energia = 1, rodando = false, quadro = null;
  let arrastando = null, panorama = null, mouseBaixo = null, sobre = null;
  let aoAbrir = null;
  let foco = null, profundidade = 2, local = false;
  let dados = { nos: [], arestas: [] };

  // ------------------------------------------------------------- montagem

  function preparar(bruto) {
    const grau = new Map();
    bruto.arestas.forEach((a) => {
      grau.set(a.de, (grau.get(a.de) || 0) + 1);
      grau.set(a.para, (grau.get(a.para) || 0) + 1);
    });

    let visiveis = bruto.nos;
    let ligacoes = bruto.arestas;

    if (local && foco) {
      const vizinhos = new Map([[foco, 0]]);
      for (let nivel = 0; nivel < profundidade; nivel++) {
        bruto.arestas.forEach((a) => {
          if (vizinhos.has(a.de) && !vizinhos.has(a.para)) vizinhos.set(a.para, nivel + 1);
          if (vizinhos.has(a.para) && !vizinhos.has(a.de)) vizinhos.set(a.de, nivel + 1);
        });
      }
      visiveis = bruto.nos.filter((n) => vizinhos.has(n.id));
      ligacoes = bruto.arestas.filter((a) => vizinhos.has(a.de) && vizinhos.has(a.para));
    }

    const antes = porId;
    porId = new Map();

    nos = visiveis.map((n, i) => {
      const velho = antes.get(n.id);
      const g = grau.get(n.id) || 0;
      const ang = (i / Math.max(1, visiveis.length)) * Math.PI * 2;

      const no = Object.assign({}, n, {
        grau: g,
        r: n.tipo === 'materia' ? 9 + Math.sqrt(g) * 2.4 : 4.5 + Math.sqrt(g) * 2.2,
        x: velho ? velho.x : Math.cos(ang) * (120 + Math.random() * 90),
        y: velho ? velho.y : Math.sin(ang) * (120 + Math.random() * 90),
        vx: 0, vy: 0,
      });

      porId.set(n.id, no);
      return no;
    });

    arestas = ligacoes
      .map((a) => ({ de: porId.get(a.de), para: porId.get(a.para), tipo: a.tipo }))
      .filter((a) => a.de && a.para);

    energia = 1;

    // Primeiro quadro na hora, sem esperar o rAF: se o documento estiver em
    // segundo plano o navegador segura o rAF e o mapa apareceria em branco.
    desenhar();
    ligar();
  }

  // --------------------------------------------------------------- física

  function passo() {
    for (let i = 0; i < nos.length; i++) {
      const a = nos[i];
      for (let j = i + 1; j < nos.length; j++) {
        const b = nos[j];
        let dx = b.x - a.x, dy = b.y - a.y;
        let d2 = dx * dx + dy * dy;
        if (d2 < 1) { d2 = 1; dx = (Math.random() - 0.5); dy = (Math.random() - 0.5); }
        if (d2 > 640000) continue;             // longe demais: ignora

        const f = FORCA_REPULSAO / d2;
        const d = Math.sqrt(d2);
        const fx = (dx / d) * f, fy = (dy / d) * f;
        a.vx -= fx; a.vy -= fy;
        b.vx += fx; b.vy += fy;
      }
    }

    arestas.forEach((e) => {
      const dx = e.para.x - e.de.x, dy = e.para.y - e.de.y;
      const d = Math.sqrt(dx * dx + dy * dy) || 1;
      const f = (d - DISTANCIA_LINK) * FORCA_MOLA;
      const fx = (dx / d) * f, fy = (dy / d) * f;
      e.de.vx += fx; e.de.vy += fy;
      e.para.vx -= fx; e.para.vy -= fy;
    });

    let soma = 0;
    nos.forEach((n) => {
      n.vx -= n.x * FORCA_CENTRO;
      n.vy -= n.y * FORCA_CENTRO;

      if (n === arrastando) { n.vx = n.vy = 0; return; }

      n.vx *= ATRITO; n.vy *= ATRITO;
      n.x += n.vx; n.y += n.vy;
      soma += Math.abs(n.vx) + Math.abs(n.vy);
    });

    energia = nos.length ? soma / nos.length : 0;
  }

  // ------------------------------------------------------------- desenho

  function corDoNo(n) {
    if (n.tipo === 'materia') return n.cor || '#8b5cf6';
    if (n.tipo === 'fantasma') return '#4a4a56';
    if (n.materia) {
      const m = porId.get('m:' + n.materia);
      if (m) return m.cor || '#8b5cf6';
    }
    return '#7d7d8c';
  }

  function desenhar() {
    const l = caixa.clientWidth, a = caixa.clientHeight;
    const dpr = window.devicePixelRatio || 1;

    if (tela.width !== l * dpr || tela.height !== a * dpr) {
      tela.width = l * dpr; tela.height = a * dpr;
      tela.style.width = l + 'px'; tela.style.height = a + 'px';
    }

    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, l, a);
    ctx.translate(l / 2 + camera.x, a / 2 + camera.y);
    ctx.scale(camera.z, camera.z);

    arestas.forEach((e) => {
      ctx.beginPath();
      ctx.moveTo(e.de.x, e.de.y);
      ctx.lineTo(e.para.x, e.para.y);
      const aceso = sobre && (e.de === sobre || e.para === sobre);
      ctx.strokeStyle = aceso ? 'rgba(139,92,246,.85)' : 'rgba(255,255,255,.11)';
      ctx.lineWidth = (aceso ? 1.6 : e.tipo === 'materia' ? 1.1 : 0.8) / camera.z;
      ctx.stroke();
    });

    nos.forEach((n) => {
      const cor = corDoNo(n);
      ctx.beginPath();
      ctx.arc(n.x, n.y, n.r, 0, Math.PI * 2);

      if (n.tipo === 'fantasma') {
        ctx.strokeStyle = cor;
        ctx.lineWidth = 1.4 / camera.z;
        ctx.stroke();
      } else {
        ctx.fillStyle = cor;
        ctx.fill();
      }

      if (n === sobre || n.id === foco) {
        ctx.beginPath();
        ctx.arc(n.x, n.y, n.r + 4 / camera.z, 0, Math.PI * 2);
        ctx.strokeStyle = '#fff';
        ctx.lineWidth = 1.5 / camera.z;
        ctx.stroke();
      }

      // Rótulo só quando cabe: em zoom baixo vira sopa de letras ilegível.
      const mostrar = n === sobre || n.tipo === 'materia' || camera.z > 0.85 || n.grau >= 4;
      if (mostrar) {
        const t = 11 / camera.z;
        ctx.font = (n.tipo === 'materia' ? '600 ' : '') + t + 'px system-ui, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillStyle = n === sobre ? '#fff' : 'rgba(230,230,234,.72)';
        const rotulo = n.rotulo.length > 28 ? n.rotulo.slice(0, 27) + '…' : n.rotulo;
        ctx.fillText(rotulo, n.x, n.y + n.r + t + 2 / camera.z);
      }
    });
  }

  function laco() {
    if (!rodando) return;

    const ativo = energia > PARADA || arrastando || panorama;
    if (ativo) passo();
    desenhar();

    // Grafo parado não precisa de 60 quadros por segundo. Sem isto o celular
    // continuaria redesenhando a mesma imagem até a bateria acabar.
    if (!ativo) { rodando = false; quadro = null; return; }

    quadro = requestAnimationFrame(laco);
  }

  function ligar() {
    if (!rodando) { rodando = true; quadro = requestAnimationFrame(laco); }
  }

  // ---------------------------------------------------------- interação

  function paraMundo(ev) {
    const r = tela.getBoundingClientRect();
    return {
      x: (ev.clientX - r.left - r.width / 2 - camera.x) / camera.z,
      y: (ev.clientY - r.top - r.height / 2 - camera.y) / camera.z,
    };
  }

  function noEm(p) {
    for (let i = nos.length - 1; i >= 0; i--) {
      const n = nos[i];
      const dx = p.x - n.x, dy = p.y - n.y;
      if (dx * dx + dy * dy <= (n.r + 7) * (n.r + 7)) return n;
    }
    return null;
  }

  function instalarEventos() {
    tela.addEventListener('pointerdown', (ev) => {
      tela.setPointerCapture(ev.pointerId);
      const p = paraMundo(ev);
      const n = noEm(p);
      mouseBaixo = { x: ev.clientX, y: ev.clientY, moveu: false, no: n };

      if (n) { arrastando = n; } else { panorama = { x: ev.clientX - camera.x, y: ev.clientY - camera.y }; }
      energia = Math.max(energia, 0.5);
      ligar();
    });

    tela.addEventListener('pointermove', (ev) => {
      const p = paraMundo(ev);

      if (mouseBaixo && (Math.abs(ev.clientX - mouseBaixo.x) > 3 || Math.abs(ev.clientY - mouseBaixo.y) > 3)) {
        mouseBaixo.moveu = true;
      }

      if (arrastando) { arrastando.x = p.x; arrastando.y = p.y; energia = Math.max(energia, 0.4); ligar(); return; }
      if (panorama) { camera.x = ev.clientX - panorama.x; camera.y = ev.clientY - panorama.y; ligar(); return; }

      const antes = sobre;
      sobre = noEm(p);
      tela.style.cursor = sobre ? 'pointer' : 'grab';
      if (antes !== sobre) ligar();
    });

    tela.addEventListener('pointerup', (ev) => {
      // Clique sem arrasto abre; com arrasto foi só reposicionar.
      if (mouseBaixo && !mouseBaixo.moveu && mouseBaixo.no && aoAbrir) {
        aoAbrir(mouseBaixo.no);
      }
      arrastando = null; panorama = null; mouseBaixo = null;
      try { tela.releasePointerCapture(ev.pointerId); } catch (e) { /* já solto */ }
    });

    tela.addEventListener('wheel', (ev) => {
      ev.preventDefault();
      const r = tela.getBoundingClientRect();
      const mx = ev.clientX - r.left - r.width / 2;
      const my = ev.clientY - r.top - r.height / 2;
      const antes = camera.z;
      camera.z = Math.min(3, Math.max(0.18, camera.z * (ev.deltaY < 0 ? 1.12 : 0.89)));
      // Zoom ancorado no cursor: sem isto o grafo foge da tela ao aproximar.
      camera.x -= (mx - camera.x) * (camera.z / antes - 1);
      camera.y -= (my - camera.y) * (camera.z / antes - 1);
      ligar();
    }, { passive: false });
  }

  // ------------------------------------------------------------- público

  return {
    abrir(bruto, opcoes) {
      dados = bruto;
      opcoes = opcoes || {};
      aoAbrir = opcoes.aoAbrir || null;
      foco = opcoes.foco || null;
      local = !!(opcoes.foco && opcoes.local);

      caixa = document.getElementById('grafo');
      tela = document.getElementById('tela-grafo');
      if (!ctx) { ctx = tela.getContext('2d'); instalarEventos(); }

      caixa.classList.remove('oculto');
      camera = { x: 0, y: 0, z: 1 };
      preparar(dados);
    },

    modo(ehLocal, prof) {
      local = ehLocal && !!foco;
      if (prof) profundidade = prof;
      preparar(dados);
    },

    fechar() {
      rodando = false;
      if (quadro) cancelAnimationFrame(quadro);
      quadro = null;
      document.getElementById('grafo').classList.add('oculto');
    },

    aberto() {
      return !document.getElementById('grafo').classList.contains('oculto');
    },
  };
})();
