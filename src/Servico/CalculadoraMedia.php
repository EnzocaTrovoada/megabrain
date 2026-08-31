<?php

declare(strict_types=1);

namespace SegundoCerebro\Servico;

/**
 * Avalia a árvore de notas de uma matéria e responde as três perguntas que importam:
 * como estou indo, onde estou de fato, e quanto ainda preciso tirar.
 *
 * Não conhece banco, HTTP nem sessão de propósito: recebe array, devolve array.
 * É a única peça do projeto onde errar cedo contamina todo o resto.
 *
 * INVARIANTE: toda regra nativa é monótona não-decrescente em cada nota de entrada.
 * É ela que permite inverter a média por busca binária e responder "preciso de
 * quanto?" para qualquer estrutura que o usuário monte, sem álgebra caso a caso.
 * Só 'expressao' pode quebrá-la, e por isso ela é verificada por amostragem.
 */
final class CalculadoraMedia
{
    public const REGRAS = [
        'soma_pontos',
        'media_simples',
        'media_ponderada',
        'melhores_n',
        'maior_entre',
        'soma_bonus',
        'expressao',
    ];

    private const ITER_BUSCA         = 60;
    private const AMOSTRAS_MONOTONIA = 25;
    private const EPS                = 1e-9;

    /** @var list<string> */
    private array $avisos = [];

    /** @var list<string> */
    private array $erros = [];

    /** @var array<string, array<string, mixed>> */
    private array $coleta = [];

    /**
     * @param  array<string, mixed> $materia
     * @return array<string, mixed>
     */
    public function calcular(array $materia): array
    {
        $this->avisos = [];
        $this->erros  = [];

        $escala = (float) ($materia['escala_maxima'] ?? 10.0);
        $alvo   = (float) ($materia['media_aprovacao'] ?? 7.0);
        $raiz   = $materia['raiz'] ?? null;

        if (!is_array($raiz) || $escala <= 0) {
            $this->erros[] = 'Matéria sem estrutura de avaliação válida.';

            return $this->resposta(null, null, null, null, 'indefinida');
        }

        $this->validar($raiz, '0');

        $zero = static fn (): float => 0.0;
        $um   = static fn (): float => 1.0;

        [$consolidada, $detConsolidada] = $this->media($raiz, $escala, false, $zero);
        [$parcial,     $detParcial]     = $this->media($raiz, $escala, true, $zero);
        [$teto,        $detTeto]        = $this->media($raiz, $escala, false, $um);

        $pendentes = [];
        $this->coletarPendentes($raiz, '0', $pendentes);

        $monotona = $this->verificarMonotonia($raiz, $escala);
        if (!$monotona) {
            $this->avisos[] = 'A estrutura não é monótona: existe nota que, ao subir, '
                . 'derruba a média. O cálculo de nota necessária foi desativado.';
        }

        $necessaria = null;
        $situacao   = 'indefinida';

        if ($consolidada !== null && $teto !== null) {
            if ($consolidada >= $alvo - self::EPS) {
                $situacao   = 'garantida';
                $necessaria = 0.0;
            } elseif ($teto < $alvo - self::EPS) {
                $situacao = 'impossivel';
            } else {
                $situacao = 'em_aberto';
                if ($monotona) {
                    $necessaria = $this->inverter($raiz, $escala, $alvo);
                }
            }
        }

        $porAvaliacao = [];
        if ($monotona && $situacao === 'em_aberto') {
            foreach ($pendentes as $caminho => $folha) {
                $r   = $this->inverterSozinha($raiz, $escala, $alvo, $caminho);
                $max = (float) ($folha['nota_maxima'] ?? 0.0);

                $porAvaliacao[] = [
                    'caminho'     => $caminho,
                    'id'          => $folha['id'] ?? null,
                    'titulo'      => $folha['titulo'] ?? '(sem título)',
                    'nota_maxima' => $max,
                    'razao'       => $r,
                    'nota'        => $r === null ? null : round($r * $max, 4),
                    'viavel'      => $r !== null,
                ];
            }
        }

        $travas   = $this->conferirTravas($raiz, '0', $escala, $detConsolidada, $detTeto);
        $detalhes = $this->fundirDetalhes($detConsolidada, $detParcial, $detTeto, $escala);

        return $this->resposta(
            $consolidada,
            $parcial,
            $teto,
            $necessaria,
            $situacao,
            $porAvaliacao,
            $travas,
            $detalhes,
            $escala,
            $alvo,
            $monotona,
            count($pendentes)
        );
    }

    // ---------------------------------------------------------------- avaliação

    /**
     * @param  array<string, mixed> $raiz
     * @return array{0: ?float, 1: array<string, array<string, mixed>>}
     */
    private function media(array $raiz, float $escala, bool $ignorarPendentes, callable $res): array
    {
        $this->coleta = [];
        $r = $this->avaliar($raiz, '0', $ignorarPendentes, $res);

        return [
            $r['razao'] === null ? null : $r['razao'] * $escala,
            $this->coleta,
        ];
    }

    /**
     * Devolve razao (0..1, ou null quando não computável), mais os pontos brutos
     * para que um pai em 'soma_pontos' possa somar filhos e netos na mesma moeda.
     *
     * @param  array<string, mixed> $no
     * @return array{razao: ?float, pontos: float, pontos_max: float}
     */
    private function avaliar(array $no, string $caminho, bool $ignorarPendentes, callable $res): array
    {
        $r = ($no['tipo'] ?? 'grupo') === 'avaliacao'
            ? $this->avaliarFolha($no, $caminho, $ignorarPendentes, $res)
            : $this->avaliarGrupo($no, $caminho, $ignorarPendentes, $res);

        $this->coleta[$caminho] = $r + [
            'nome' => $no['nome'] ?? $no['titulo'] ?? '(sem nome)',
            'tipo' => $no['tipo'] ?? 'grupo',
        ];

        return $r;
    }

    /**
     * @param  array<string, mixed> $no
     * @return array{razao: ?float, pontos: float, pontos_max: float}
     */
    private function avaliarFolha(array $no, string $caminho, bool $ignorarPendentes, callable $res): array
    {
        $max    = (float) ($no['nota_maxima'] ?? 0.0);
        $obtida = $no['nota_obtida'] ?? null;
        $status = $no['status'] ?? ($obtida === null ? 'pendente' : 'lancada');

        // Dispensada não é zero nem pendente: sai da conta inteira.
        if ($status === 'dispensada') {
            return ['razao' => null, 'pontos' => 0.0, 'pontos_max' => 0.0];
        }

        if ($obtida !== null) {
            $obtida = (float) $obtida;

            return [
                'razao'      => $max > 0 ? $obtida / $max : null,
                'pontos'     => $obtida,
                'pontos_max' => $max,
            ];
        }

        if ($ignorarPendentes) {
            return ['razao' => null, 'pontos' => 0.0, 'pontos_max' => 0.0];
        }

        $razao = $this->limitar((float) $res($no, $caminho));

        return ['razao' => $razao, 'pontos' => $razao * $max, 'pontos_max' => $max];
    }

    /**
     * @param  array<string, mixed> $no
     * @return array{razao: ?float, pontos: float, pontos_max: float}
     */
    private function avaliarGrupo(array $no, string $caminho, bool $ignorarPendentes, callable $res): array
    {
        $filhos = [];

        foreach (array_values($no['filhos'] ?? []) as $i => $filho) {
            $r         = $this->avaliar($filho, $caminho . '.' . $i, $ignorarPendentes, $res);
            $r['peso'] = (float) ($filho['peso'] ?? 1.0);
            $r['idx']  = $i;
            $r['no']   = $filho;
            $filhos[]  = $r;
        }

        $vivos = array_values(array_filter($filhos, static fn (array $f): bool => $f['razao'] !== null));

        if ($vivos === []) {
            return ['razao' => null, 'pontos' => 0.0, 'pontos_max' => 0.0];
        }

        $somaMax = (float) array_sum(array_column($vivos, 'pontos_max'));

        // No modo parcial o total declarado é ignorado de propósito: a parcial
        // mede só o que já foi lançado, senão ela colapsaria na consolidada.
        $declarado = $no['pontos_totais'] ?? null;
        $pontosMax = (!$ignorarPendentes && $declarado !== null)
            ? (float) $declarado
            : $somaMax;

        $razao = $this->aplicarRegra($no, $vivos, $pontosMax, $caminho, $ignorarPendentes);

        if ($razao !== null && isset($no['teto'])) {
            $razao = min($razao, (float) $no['teto']);
        }

        $razao = $razao === null ? null : $this->limitar($razao);

        return [
            'razao'      => $razao,
            'pontos'     => $razao === null ? 0.0 : $razao * $pontosMax,
            'pontos_max' => $pontosMax,
        ];
    }

    /**
     * @param  array<string, mixed>       $no
     * @param  list<array<string, mixed>> $vivos
     */
    private function aplicarRegra(
        array $no,
        array $vivos,
        float $pontosMax,
        string $caminho,
        bool $ignorarPendentes = false
    ): ?float {
        $regra  = $no['regra'] ?? 'media_ponderada';
        $razoes = array_map(static fn (array $f): float => (float) $f['razao'], $vivos);
        $pesos  = array_map(static fn (array $f): float => (float) $f['peso'], $vivos);

        switch ($regra) {
            case 'soma_pontos':
                if ($pontosMax <= 0) {
                    return null;
                }

                return (float) array_sum(array_column($vivos, 'pontos')) / $pontosMax;

            case 'media_simples':
                return array_sum($razoes) / count($razoes);

            case 'melhores_n':
                $n = (int) ($no['manter_n'] ?? count($razoes));
                $n = max(1, min($n, count($razoes)));
                rsort($razoes);
                $mantidas = array_slice($razoes, 0, $n);

                return array_sum($mantidas) / count($mantidas);

            case 'maior_entre':
                return max($razoes);

            case 'soma_bonus':
                // O primeiro filho é a base; os demais somam até peso% da escala.
                // Sem a base a conta não significa nada, então não inventamos um número.
                if (($vivos[0]['idx'] ?? 0) !== 0) {
                    return null;
                }

                $base  = $razoes[0];
                $bonus = 0.0;
                for ($i = 1, $n = count($razoes); $i < $n; $i++) {
                    $bonus += $razoes[$i] * ($pesos[$i] / 100.0);
                }

                return $base + $bonus;

            case 'expressao':
                return $this->avaliarExpressao($no, $vivos, $pontosMax, $caminho, $ignorarPendentes);

            case 'media_ponderada':
            default:
                $total = array_sum($pesos);
                if ($total <= 0) {
                    return array_sum($razoes) / count($razoes);
                }

                $acc = 0.0;
                foreach ($razoes as $i => $razao) {
                    $acc += $razao * $pesos[$i];
                }

                return $acc / $total;
        }
    }

    /**
     * @param  array<string, mixed>       $no
     * @param  list<array<string, mixed>> $vivos
     */
    private function avaliarExpressao(
        array $no,
        array $vivos,
        float $pontosMax,
        string $caminho,
        bool $ignorarPendentes = false
    ): ?float {
        $expr = (string) ($no['expressao'] ?? '');
        if ($expr === '' || $pontosMax <= 0) {
            return null;
        }

        $vars = [];
        foreach ($vivos as $f) {
            $apelido = $f['no']['apelido'] ?? null;
            if (!is_string($apelido) || $apelido === '') {
                continue;
            }
            $apelido                 = strtoupper($apelido);
            $vars[$apelido]          = (float) $f['pontos'];      // nota bruta, como o professor escreve
            $vars[$apelido . '_R']   = (float) $f['razao'];       // razão 0..1, quando for mais prático
            $vars[$apelido . '_MAX'] = (float) $f['pontos_max'];
        }

        try {
            $valor = (new Expressao())->avaliar($expr, $vars);
        } catch (\RuntimeException $e) {
            // Na parcial faltam variáveis por definição (as pendentes saíram da conta).
            // Isso não é erro do usuário: a parcial simplesmente não existe aqui.
            if (!$ignorarPendentes) {
                $this->erros[] = sprintf(
                    'Fórmula inválida em "%s": %s',
                    (string) ($no['nome'] ?? $caminho),
                    $e->getMessage()
                );
            }

            return null;
        }

        return $valor / $pontosMax;
    }

    // ------------------------------------------------------------------ inversão

    /** Menor desempenho uniforme nas pendentes que atinge o alvo. */
    private function inverter(array $raiz, float $escala, float $alvo): ?float
    {
        return $this->buscar(
            fn (float $x): ?float => $this->media($raiz, $escala, false, static fn (): float => $x)[0],
            $alvo
        );
    }

    /** Quanto preciso NESTA avaliação assumindo zero em todas as outras pendentes. */
    private function inverterSozinha(array $raiz, float $escala, float $alvo, string $alvoCaminho): ?float
    {
        return $this->buscar(
            fn (float $x): ?float => $this->media(
                $raiz,
                $escala,
                false,
                static fn (array $folha, string $c): float => $c === $alvoCaminho ? $x : 0.0
            )[0],
            $alvo
        );
    }

    /** Busca binária sobre f monótona em [0,1]. Devolve null se nem f(1) alcança o alvo. */
    private function buscar(callable $f, float $alvo): ?float
    {
        $topo = $f(1.0);
        if ($topo === null || $topo < $alvo - self::EPS) {
            return null;
        }

        $base = $f(0.0);
        if ($base !== null && $base >= $alvo - self::EPS) {
            return 0.0;
        }

        $lo = 0.0;
        $hi = 1.0;

        for ($i = 0; $i < self::ITER_BUSCA; $i++) {
            $meio = ($lo + $hi) / 2.0;
            $v    = $f($meio);

            if ($v !== null && $v >= $alvo) {
                $hi = $meio;
            } else {
                $lo = $meio;
            }
        }

        return $hi;
    }

    /**
     * Amostra a média em [0,1] e confere que ela nunca desce. Não é prova formal,
     * mas pega o erro real: fórmula livre que pune nota alta.
     */
    private function verificarMonotonia(array $raiz, float $escala): bool
    {
        $anterior = null;

        for ($i = 0; $i <= self::AMOSTRAS_MONOTONIA; $i++) {
            $x = $i / self::AMOSTRAS_MONOTONIA;
            $v = $this->media($raiz, $escala, false, static fn (): float => $x)[0];

            if ($v === null) {
                continue;
            }
            if ($anterior !== null && $v < $anterior - 1e-6) {
                return false;
            }
            $anterior = $v;
        }

        return true;
    }

    // ----------------------------------------------------------------- validação

    /** @param array<string, mixed> $no */
    private function validar(array $no, string $caminho): void
    {
        $nome = (string) ($no['nome'] ?? $no['titulo'] ?? $caminho);

        if (($no['tipo'] ?? 'grupo') === 'avaliacao') {
            $max = (float) ($no['nota_maxima'] ?? 0.0);
            $obt = $no['nota_obtida'] ?? null;

            if ($max <= 0) {
                $this->erros[] = sprintf('"%s": nota máxima precisa ser maior que zero.', $nome);
            }
            if ($obt !== null && (float) $obt < 0) {
                $this->erros[] = sprintf('"%s": nota negativa.', $nome);
            }
            if ($obt !== null && (float) $obt > $max + self::EPS) {
                $this->erros[] = sprintf('"%s": nota %.2f acima do máximo %.2f.', $nome, (float) $obt, $max);
            }

            return;
        }

        $regra  = $no['regra'] ?? 'media_ponderada';
        $filhos = array_values($no['filhos'] ?? []);

        if (!in_array($regra, self::REGRAS, true)) {
            $this->erros[] = sprintf('"%s": regra desconhecida "%s".', $nome, (string) $regra);
        }
        if ($filhos === []) {
            $this->avisos[] = sprintf('"%s" não tem nenhuma avaliação dentro.', $nome);

            return;
        }

        if ($regra === 'media_ponderada') {
            $soma = 0.0;
            foreach ($filhos as $f) {
                $soma += (float) ($f['peso'] ?? 1.0);
            }
            // 100 é convenção, não obrigação: normalizamos e avisamos.
            if (abs($soma - 100.0) > 0.01 && abs($soma - (float) count($filhos)) > 0.01) {
                $this->avisos[] = sprintf(
                    '"%s": os pesos somam %.2f em vez de 100. A média foi normalizada.',
                    $nome,
                    $soma
                );
            }
        }

        if ($regra === 'expressao' && ($no['pontos_totais'] ?? null) === null) {
            $this->avisos[] = sprintf(
                '"%s": fórmula sem "pontos_totais". Assumindo a soma dos filhos como valor máximo.',
                $nome
            );
        }

        $apelidos = [];
        foreach ($filhos as $i => $filho) {
            $ap = $filho['apelido'] ?? null;
            if (is_string($ap) && $ap !== '') {
                $ap = strtoupper($ap);
                if (isset($apelidos[$ap])) {
                    $this->erros[] = sprintf('"%s": apelido "%s" repetido entre irmãos.', $nome, $ap);
                }
                $apelidos[$ap] = true;
            }
            $this->validar($filho, $caminho . '.' . $i);
        }
    }

    /**
     * Trava de nota mínima: vale mesmo com a média alta ("média 8 mas 4 nas provas").
     * A mínima é expressa na escala da matéria, não em razão.
     *
     * @param  array<string, mixed> $no
     * @return list<array<string, mixed>>
     */
    private function conferirTravas(array $no, string $caminho, float $escala, array $det, array $detTeto): array
    {
        $fora = [];
        $min  = $no['nota_minima'] ?? null;

        if ($min !== null && ($det[$caminho]['razao'] ?? null) !== null) {
            $atual  = $det[$caminho]['razao'] * $escala;
            $maximo = $detTeto[$caminho]['razao'] ?? null;
            $maximo = $maximo === null ? null : $maximo * $escala;

            if ($atual < (float) $min - self::EPS) {
                $fora[] = [
                    'caminho'      => $caminho,
                    'nome'         => (string) ($no['nome'] ?? $caminho),
                    'minima'       => (float) $min,
                    'atual'        => round($atual, 4),
                    'ainda_viavel' => $maximo !== null && $maximo >= (float) $min - self::EPS,
                ];
            }
        }

        foreach (array_values($no['filhos'] ?? []) as $i => $filho) {
            $fora = array_merge(
                $fora,
                $this->conferirTravas($filho, $caminho . '.' . $i, $escala, $det, $detTeto)
            );
        }

        return $fora;
    }

    /**
     * @param array<string, mixed>                $no
     * @param array<string, array<string, mixed>> $saida
     */
    private function coletarPendentes(array $no, string $caminho, array &$saida): void
    {
        if (($no['tipo'] ?? 'grupo') === 'avaliacao') {
            $status = $no['status'] ?? (($no['nota_obtida'] ?? null) === null ? 'pendente' : 'lancada');
            if ($status !== 'dispensada' && ($no['nota_obtida'] ?? null) === null) {
                $saida[$caminho] = $no;
            }

            return;
        }

        foreach (array_values($no['filhos'] ?? []) as $i => $filho) {
            $this->coletarPendentes($filho, $caminho . '.' . $i, $saida);
        }
    }

    // --------------------------------------------------------------------- saída

    /** @return array<string, array<string, mixed>> */
    private function fundirDetalhes(array $cons, array $parc, array $teto, float $escala): array
    {
        $out = [];

        foreach ($cons as $caminho => $c) {
            $out[$caminho] = [
                'nome'        => $c['nome'],
                'tipo'        => $c['tipo'],
                'pontos'      => round((float) $c['pontos'], 4),
                'pontos_max'  => round((float) $c['pontos_max'], 4),
                'consolidada' => $c['razao'] === null ? null : round($c['razao'] * $escala, 4),
                'parcial'     => ($parc[$caminho]['razao'] ?? null) !== null
                    ? round($parc[$caminho]['razao'] * $escala, 4)
                    : null,
                'teto'        => ($teto[$caminho]['razao'] ?? null) !== null
                    ? round($teto[$caminho]['razao'] * $escala, 4)
                    : null,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function resposta(
        ?float $consolidada,
        ?float $parcial,
        ?float $teto,
        ?float $necessaria,
        ?string $situacao,
        array $porAvaliacao = [],
        array $travas = [],
        array $detalhes = [],
        float $escala = 10.0,
        float $alvo = 7.0,
        bool $monotona = true,
        int $pendentes = 0
    ): array {
        return [
            'escala'            => $escala,
            'alvo'              => $alvo,
            'media_consolidada' => $consolidada === null ? null : round($consolidada, 4),
            'media_parcial'     => $parcial === null ? null : round($parcial, 4),
            'media_maxima'      => $teto === null ? null : round($teto, 4),
            'situacao'          => $situacao,
            'necessaria_razao'  => $necessaria === null ? null : round($necessaria, 6),
            'necessaria_pct'    => $necessaria === null ? null : round($necessaria * 100, 2),
            'por_avaliacao'     => $porAvaliacao,
            'travas'            => $travas,
            'pendentes'         => $pendentes,
            'monotona'          => $monotona,
            'detalhes'          => $detalhes,
            'avisos'            => $this->avisos,
            'erros'             => $this->erros,
        ];
    }

    private function limitar(float $x): float
    {
        return max(0.0, min(1.0, $x));
    }
}
