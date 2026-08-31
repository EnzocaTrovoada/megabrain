# Megabrain — segundo cérebro acadêmico

Anotações em markdown organizadas por matéria, com login, rodando em hospedagem
compartilhada. Sem framework, sem build, sem banco: deploy é copiar arquivo.

**A raiz deste repositório é o `public_html` do domínio.** É o que permite o
deploy por git da Hostinger clonar direto, sem pasta duplicada no caminho.

```
index.php               instalação, login e casca do app
api.php                 API JSON (uma ação por requisição)
app/nucleo.php          sessão, armazenamento, limites  (bloqueado por .htaccess)
assets/                 app.css, app.js, grafo.js, markdown.js, ícones
manifest.json           permite "adicionar à tela de início" no celular
.htaccess

src/Servico/            núcleo de cálculo de média (ainda sem tela)
testes/ e bin/          testes do cálculo — bloqueados por .htaccess

../dados/               criado sozinho, UM NÍVEL ACIMA — fora do público
  config.json           hash da sua senha
  base.json             matérias e anotações
  arquivos/             imagens enviadas
  sessoes.json          sessões ativas
  tentativas.json       controle de força bruta
```

## Subir na Hostinger

Por git (recomendado): aponte o deploy do hPanel para este repositório e
clone dentro do `public_html`. A pasta `dados/` fica fora do repositório de
propósito, então `git pull` nunca encosta nos seus dados.

Por FTP: envie o conteúdo da raiz do repositório para o `public_html`.

Nos dois casos, uma vez só:

1. Apague o `default.php` que a Hostinger deixa lá — ele responde na raiz e
   esconde o app.
2. Apague `bin/diagnostico.php` do servidor: ele expõe caminho absoluto,
   versão do PHP e extensões.
3. Abra o domínio. Aparece a tela de instalação, pedindo um código.
4. O código está em `dados/CODIGO-DE-INSTALACAO.txt`, gerado pelo servidor na
   primeira visita. Abra pelo Gerenciador de Arquivos da Hostinger, informe o
   código e escolha sua senha.

O código **não está no código-fonte** de propósito: assim este repositório pode
ser público sem publicar junto a chave que abre a instalação de quem clonar. Só
quem tem acesso aos arquivos do servidor consegue lê-lo — o mesmo nível de
acesso de quem deveria poder instalar.

Instalou, o arquivo se apaga sozinho e a tela de instalação some para sempre.

## Segurança

- Senha com `password_hash` (Argon2id/bcrypt conforme o servidor).
- Sessão é um token opaco de 32 bytes em cookie `httpOnly` + `Secure` +
  `SameSite=Lax`. No disco fica só o **SHA-256** dele: quem ler o arquivo não
  consegue se passar por você. Nada de JWT.
- Expiração deslizante de 30 dias, com teto absoluto de 90.
- 8 tentativas de senha erradas por IP em 15 minutos e a porta fecha.
- Toda escrita exige o token CSRF da sessão no header `X-CSRF`.
- CSP restrita: sem script inline, sem recurso externo.
- `display_errors` desligado por código — a Hostinger entrega ligado, e stack
  trace na tela vaza caminho absoluto do servidor.
- `dados/` fica fora do `public_html`. Se o servidor não permitir, cai para
  dentro com `.htaccess` negando tudo — e a tela de instalação avisa.

## Ligar notas e o mapa mental

Escreva `[[Nome da outra nota]]` dentro de uma anotação. Ao digitar `[[` aparece
a lista das suas notas; `↑` `↓` navegam, `Enter` ou `Tab` escolhem.

O casamento **ignora acento e maiúscula**: `[[ciclo de krebs]]` acha
"Ciclo de Krebs". Em português, exigir acento exato faria metade dos links
falhar em silêncio. Também aceita `[[Título|como quero que apareça]]`.

Quem aponta para a nota aberta aparece na barra de **backlinks**, no rodapé.
Ela é calculada na leitura, não gravada — então renomear uma nota nunca deixa
referência velha para trás.

O botão **◍** (ou `Ctrl+G`) abre o mapa. Nós são notas, arestas são links, e o
tamanho do nó é proporcional a quantas referências ele recebe — mesma lógica do
Obsidian. Duas diferenças, ambas de propósito:

- **Matéria também é nó.** No Obsidian o grafo nasce vazio até você linkar tudo
  à mão. Aqui matéria→nota já é uma ligação real, então o mapa nasce com
  estrutura em vez de uma nuvem de pontos soltos.
- **Nó vazado é fantasma:** você escreveu `[[algo]]` que ainda não existe.
  Clicar cria a nota. É a sua lista do que falta escrever, desenhada.

Arraste os nós, role para aproximar, clique para abrir. "Só em volta desta nota"
mostra o grafo local com profundidade ajustável.

## Ler e imagens

O botão **◑** (ou `Ctrl+E`) cicla entre três modos:

| Modo | Ícone | O que mostra |
|---|---|---|
| escrever | ◑ | só o editor, markdown cru |
| dividido | ◐ | editor e leitura lado a lado, atualizando enquanto você digita |
| ler | ● | só o resultado renderizado |

No celular o ciclo pula o dividido — não cabem duas colunas em 375px.

O dividido existe porque `textarea` não renderiza imagem dentro de si. Para ver
a foto do quadro enquanto escreve, ela precisa aparecer ao lado. (O Obsidian
mostra inline porque não usa `textarea`; fazer igual aqui significa trocar o
editor por `contenteditable`, o que está na lista mas não é barato.)

No modo leitura os `[[wikilinks]]` viram clicáveis — link para nota que ainda
não existe oferece criar.

Suportado: `#` títulos, `**negrito**`, `*itálico*`, `~~riscado~~`, `` `código` ``,
blocos ```` ``` ````, listas, `- [ ]` e `- [x]` como caixas, `>` citação,
tabelas com `|`, `---`, `[texto](url)`, `![](arquivo:ID)` e `[[wikilinks]]`.

**Cole uma imagem no editor (`Ctrl+V`) ou arraste** — ela sobe sozinha e a
referência é inserida. No markdown fica `![](arquivo:ID)`, nunca uma URL: se a
rota mudar um dia, nenhuma nota antiga quebra.

O renderizador **escapa o HTML antes de interpretar o markdown**, então texto de
nota não tem como virar marcação executável — a mesma garantia de um
sanitizador, sem a dependência.

## Uso

| Atalho | O quê |
|---|---|
| `Ctrl/Cmd + S` | salvar agora |
| `Ctrl/Cmd + E` | cicla escrever / dividido / ler |
| `Ctrl/Cmd + K` | ir para a busca |
| `Ctrl/Cmd + G` | abrir o mapa mental |
| `Ctrl/Cmd + V` | cola imagem, que sobe sozinha |
| `[[` no editor | sugere notas para linkar |
| `Tab` no editor | indenta (não pula de campo) |

Na barra lateral, cada matéria tem `✎` para renomear e `×` para excluir, e a
bolinha colorida cicla a cor ao ser clicada.

Salvamento é automático 700 ms após parar de digitar, e também ao trocar de
nota, sair do campo ou mandar o app para segundo plano — essa última é a que
importa no celular, onde o navegador congela temporizadores em aba escondida.

Cada nota é copiada para o `localStorage` antes de ir para a rede. Se o wifi da
faculdade cair no meio, o texto sobrevive e o indicador mostra "sem conexão".

No celular, "adicionar à tela de início" instala como app (tela cheia, sem
barra de endereço).

## Imagens: como são tratadas

Só entram JPEG, PNG e WEBP, e **toda imagem é reencodada pelo GD**. É aí que
mora a segurança: um "png" com PHP embutido não sobrevive a decodificar e
recodificar. De brinde, some o EXIF (que carrega coordenada de GPS) e a foto de
4000px do celular é reduzida para 1600px no maior lado — o que importa quando o
wifi é o da faculdade.

O nome no disco é o **SHA-256 do conteúdo**, em pastas de dois níveis. Isso torna
path traversal impossível (o nome nunca vem do cliente), evita diretório com
milhares de entradas, e dá deduplicação de graça: a mesma imagem enviada duas
vezes ocupa espaço uma vez. Uma miniatura de 360px é gerada junto.

Nada é servido direto pelo LiteSpeed: tudo passa por `api.php`, que confere a
sessão antes de entregar o byte.

Limites: 12 MB por arquivo, 500 MB no total (`ARQUIVO_BYTES_MAX` e `QUOTA_BYTES`
em `app/nucleo.php`).

## O que ainda não tem

- **Canvas** — a tela infinita com imagens e setas. Formato escolhido:
  [JSON Canvas 1.0](https://jsoncanvas.org/), aberto e MIT, o mesmo do Obsidian
- Busca dentro do conteúdo (hoje procura só no título)
- Funcionar offline de verdade (falta service worker)
- Tags `#assunto` como segunda dimensão além da matéria
- Tela da calculadora de média — o motor existe e está testado, falta a UI
- Calendário, avaliações e feed iCal
- Anexar PDF (slides do professor)
- Personalização de cores e tipografia
- Multiusuário (hoje é uma senha só)

## Backup

Baixe `dados/base.json`. É o arquivo inteiro: matérias, anotações e conteúdo.
Sem exportador, sem formato proprietário.

---

# Núcleo de cálculo de média

PHP puro, sem banco e sem HTTP. Ainda não tem tela; roda pelos testes.

```bash
php bin/testar.php
```

14 casos, todos conferidos à mão. Sai com código 1 se algum falhar.

## Os três números

| | O que é | Pra que serve |
|---|---|---|
| `media_consolidada` | pendentes contam como zero | onde você está **de fato** hoje |
| `media_parcial` | só o que já foi lançado, renormalizado | como você está **indo** |
| `media_maxima` | pendentes contam como nota cheia | se ainda dá pra passar |

`situacao`: `garantida`, `em_aberto` ou `impossivel`.
`necessaria_razao` é o desempenho uniforme mínimo nas pendentes (0..1);
`por_avaliacao` diz quanto falta em **cada** pendente assumindo zero nas outras.

## Montando uma matéria

Um nó é um grupo (tem `filhos` e uma `regra`) ou uma avaliação
(`tipo: 'avaliacao'`). O peso vive no **filho**; o pai decide como combinar.

```php
[
  'escala_maxima'   => 10.0,
  'media_aprovacao' => 7.0,
  'raiz' => [
    'nome'  => 'Bioquímica II',
    'regra' => 'media_ponderada',
    'filhos' => [
      [
        'nome' => 'Provas', 'peso' => 50.0,
        'regra' => 'soma_pontos', 'pontos_totais' => 20.0,
        'filhos' => [
          ['tipo'=>'avaliacao','titulo'=>'P1','nota_maxima'=>10.0,'nota_obtida'=>7.0],
          ['tipo'=>'avaliacao','titulo'=>'P2','nota_maxima'=>10.0,'nota_obtida'=>null],
        ],
      ],
      // ... Trabalhos, peso 50
    ],
  ],
]
```

### Regras

| Regra | Faz | Campos extras |
|---|---|---|
| `soma_pontos` | `Σ obtida / pontos_totais` | `pontos_totais` (opcional) |
| `media_simples` | média das razões | — |
| `media_ponderada` | `Σ razão·peso / Σ peso` | `peso` nos filhos |
| `melhores_n` | fica com as N melhores | `manter_n` |
| `maior_entre` | a maior razão (substitutiva) | — |
| `soma_bonus` | 1º filho + bônus, com teto | `peso` nos bônus, `teto` |
| `expressao` | fórmula sua | `expressao`, `pontos_totais` |

`pontos_totais` opcional resolve "os trabalhos juntos valem 10, mas ainda não
sei quantos serão". Se ficar `null`, deriva da soma dos filhos.

Extras de qualquer grupo: `nota_minima` (trava independente da média, na escala
da matéria) e `teto` (limite da razão, 0..1).

Status de avaliação: `pendente`, `lancada`, `dispensada`.
**Dispensada não é zero nem pendente** — sai inteira da conta.

### Fórmulas

Variáveis são os `apelido` dos filhos, em três formas: `X` (nota bruta),
`X_R` (razão 0..1), `X_MAX` (máximo). Funções: `min max se abs arred teto piso`.
Separador de argumentos: `;`.

```
se(PROVAS >= 5; PROVAS*0.6 + TRAB*0.4; PROVAS*0.6)
min(P1 + BONUS; 10)
```

Sem `eval()`: shunting-yard próprio com whitelist. Variável desconhecida é erro,
nunca zero silencioso.

## A invariante

Toda regra nativa é **monótona não-decrescente**: nenhuma nota que sobe pode
derrubar a média. É isso que permite inverter a média por busca binária e
responder "preciso de quanto?" para qualquer estrutura, sem álgebra caso a caso.

Só `expressao` pode quebrar isso. O motor amostra a média em 26 pontos e
confere que ela nunca desce; se descer, avisa e **desliga** a nota necessária
em vez de mostrar um número errado.

Quando aparecer uma disciplina com regra esquisita, adicione um caso em
`testes/fixtures.php` **antes** de confiar no que a tela mostrar.
