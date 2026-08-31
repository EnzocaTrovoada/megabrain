<?php

declare(strict_types=1);

namespace SegundoCerebro\Servico;

use RuntimeException;

/**
 * Avaliador de fórmula do usuário. Shunting-yard para RPN, sem eval().
 *
 * Aceita só o que está na whitelist. Qualquer identificador que não seja função
 * conhecida nem variável declarada é erro, nunca zero silencioso: fórmula que
 * calcula errado sem avisar é pior do que fórmula que não roda.
 *
 * Separador de argumentos é ";" (convenção de planilha em pt-BR); "," também vale.
 *
 *   (P1 + P2) / 2
 *   se(PROVAS >= 5; PROVAS * 0.6 + TRAB * 0.4; PROVAS * 0.6)
 *   min(P1 + BONUS; 10)
 */
final class Expressao
{
    private const MAX_TOKENS = 500;

    /** operador => [precedência, associatividade à esquerda] */
    private const OPERADORES = [
        '<'  => [1, true],
        '<=' => [1, true],
        '>'  => [1, true],
        '>=' => [1, true],
        '==' => [1, true],
        '!=' => [1, true],
        '+'  => [2, true],
        '-'  => [2, true],
        '*'  => [3, true],
        '/'  => [3, true],
        'u-' => [4, false],
    ];

    /** função => aridade (null = variável, mínimo 1) */
    private const FUNCOES = [
        'min'   => null,
        'max'   => null,
        'se'    => 3,
        'abs'   => 1,
        'arred' => 2,
        'teto'  => 1,
        'piso'  => 1,
    ];

    /**
     * @param  array<string, float> $vars
     * @throws RuntimeException
     */
    public function avaliar(string $expr, array $vars = []): float
    {
        $normalizadas = [];
        foreach ($vars as $k => $v) {
            $normalizadas[strtoupper($k)] = (float) $v;
        }

        return $this->executar($this->paraRpn($this->tokenizar($expr)), $normalizadas);
    }

    /**
     * Confere se a fórmula é monótona não-decrescente em cada variável.
     * Amostragem, não prova: pega o erro comum sem custar um provador de teoremas.
     *
     * @param array<string, float> $maximos valor máximo plausível de cada variável
     */
    public function pareceMonotona(string $expr, array $maximos, int $amostras = 60): bool
    {
        $nomes = array_keys($maximos);
        if ($nomes === []) {
            return true;
        }

        $semente = 20260821;
        mt_srand($semente);

        for ($i = 0; $i < $amostras; $i++) {
            $ponto = [];
            foreach ($maximos as $nome => $max) {
                $ponto[$nome] = (mt_rand(0, 1000) / 1000) * (float) $max;
            }

            try {
                $base = $this->avaliar($expr, $ponto);
            } catch (RuntimeException) {
                return false;
            }

            foreach ($nomes as $nome) {
                $max  = (float) $maximos[$nome];
                $alto = $ponto;
                $alto[$nome] = min($max, $ponto[$nome] + $max * 0.1);

                if ($alto[$nome] <= $ponto[$nome]) {
                    continue;
                }

                try {
                    if ($this->avaliar($expr, $alto) < $base - 1e-9) {
                        return false;
                    }
                } catch (RuntimeException) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return list<array{0: string, 1: string}> */
    private function tokenizar(string $expr): array
    {
        $tokens = [];
        $n      = strlen($expr);
        $i      = 0;

        while ($i < $n) {
            $c = $expr[$i];

            if (ctype_space($c)) {
                $i++;
                continue;
            }

            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($expr[$i + 1]))) {
                $j = $i;
                while ($j < $n && (ctype_digit($expr[$j]) || $expr[$j] === '.')) {
                    $j++;
                }
                $bruto = substr($expr, $i, $j - $i);
                if (substr_count($bruto, '.') > 1) {
                    throw new RuntimeException(sprintf('número mal formado "%s"', $bruto));
                }
                $tokens[] = ['num', $bruto];
                $i        = $j;
                continue;
            }

            if (ctype_alpha($c) || $c === '_') {
                $j = $i;
                while ($j < $n && (ctype_alnum($expr[$j]) || $expr[$j] === '_')) {
                    $j++;
                }
                $tokens[] = ['id', substr($expr, $i, $j - $i)];
                $i        = $j;
                continue;
            }

            $dois = substr($expr, $i, 2);
            if (in_array($dois, ['<=', '>=', '==', '!='], true)) {
                $tokens[] = ['op', $dois];
                $i       += 2;
                continue;
            }

            if (str_contains('+-*/<>', $c)) {
                $tokens[] = ['op', $c];
                $i++;
                continue;
            }

            if ($c === '(' || $c === ')') {
                $tokens[] = [$c === '(' ? 'abre' : 'fecha', $c];
                $i++;
                continue;
            }

            if ($c === ';' || $c === ',') {
                $tokens[] = ['sep', ';'];
                $i++;
                continue;
            }

            throw new RuntimeException(sprintf('caractere não permitido "%s"', $c));
        }

        if ($tokens === []) {
            throw new RuntimeException('fórmula vazia');
        }
        if (count($tokens) > self::MAX_TOKENS) {
            throw new RuntimeException('fórmula longa demais');
        }

        return $tokens;
    }

    /**
     * @param  list<array{0: string, 1: string}> $tokens
     * @return list<array{0: string, 1: mixed, 2?: int}>
     */
    private function paraRpn(array $tokens): array
    {
        $saida  = [];
        $pilha  = [];
        $args   = [];   // contagem de argumentos da função aberta
        $antes  = null; // token anterior, para detectar menos unário

        foreach ($tokens as $idx => [$tipo, $valor]) {
            $unario = $tipo === 'op'
                && $valor === '-'
                && ($antes === null || in_array($antes[0], ['op', 'abre', 'sep'], true));

            if ($tipo === 'num') {
                $saida[] = ['num', (float) $valor];
            } elseif ($tipo === 'id') {
                $prox = $tokens[$idx + 1][0] ?? null;
                if ($prox === 'abre') {
                    $pilha[] = ['fn', strtolower($valor)];
                    $args[]  = 1;
                } else {
                    $saida[] = ['var', strtoupper($valor)];
                }
            } elseif ($tipo === 'sep') {
                while ($pilha !== [] && end($pilha)[0] !== 'abre') {
                    $saida[] = array_pop($pilha);
                }
                if ($pilha === []) {
                    throw new RuntimeException('";" fora de uma função');
                }
                if ($args === []) {
                    throw new RuntimeException('";" fora de uma função');
                }
                $args[count($args) - 1]++;
            } elseif ($tipo === 'op') {
                $op = $unario ? 'u-' : $valor;
                [$prec, $esq] = self::OPERADORES[$op];

                while ($pilha !== []) {
                    $topo = end($pilha);
                    if ($topo[0] !== 'op') {
                        break;
                    }
                    [$pTopo, ] = self::OPERADORES[$topo[1]];
                    if ($pTopo > $prec || ($pTopo === $prec && $esq)) {
                        $saida[] = array_pop($pilha);
                    } else {
                        break;
                    }
                }
                $pilha[] = ['op', $op];
            } elseif ($tipo === 'abre') {
                $pilha[] = ['abre', '('];
            } else { // fecha
                while ($pilha !== [] && end($pilha)[0] !== 'abre') {
                    $saida[] = array_pop($pilha);
                }
                if ($pilha === []) {
                    throw new RuntimeException('parêntese fechado sem abrir');
                }
                array_pop($pilha);

                if ($pilha !== [] && end($pilha)[0] === 'fn') {
                    $fn      = array_pop($pilha);
                    $saida[] = ['fn', $fn[1], (int) array_pop($args)];
                }
            }

            $antes = [$tipo, $valor];
        }

        while ($pilha !== []) {
            $topo = array_pop($pilha);
            if ($topo[0] === 'abre') {
                throw new RuntimeException('parêntese aberto sem fechar');
            }
            $saida[] = $topo;
        }

        return $saida;
    }

    /**
     * @param  list<array{0: string, 1: mixed, 2?: int}> $rpn
     * @param  array<string, float>                      $vars
     */
    private function executar(array $rpn, array $vars): float
    {
        $p = [];

        foreach ($rpn as $item) {
            $tipo = $item[0];

            if ($tipo === 'num') {
                $p[] = (float) $item[1];
                continue;
            }

            if ($tipo === 'var') {
                $nome = (string) $item[1];
                if (!array_key_exists($nome, $vars)) {
                    throw new RuntimeException(sprintf('variável "%s" não existe nesta matéria', $nome));
                }
                $p[] = $vars[$nome];
                continue;
            }

            if ($tipo === 'op') {
                $op = (string) $item[1];

                if ($op === 'u-') {
                    $p[] = -$this->tirar($p);
                    continue;
                }

                $b = $this->tirar($p);
                $a = $this->tirar($p);

                $p[] = match ($op) {
                    '+'  => $a + $b,
                    '-'  => $a - $b,
                    '*'  => $a * $b,
                    '/'  => abs($b) < 1e-12
                        ? throw new RuntimeException('divisão por zero')
                        : $a / $b,
                    '<'  => $a < $b ? 1.0 : 0.0,
                    '<=' => $a <= $b ? 1.0 : 0.0,
                    '>'  => $a > $b ? 1.0 : 0.0,
                    '>=' => $a >= $b ? 1.0 : 0.0,
                    '==' => abs($a - $b) < 1e-9 ? 1.0 : 0.0,
                    '!=' => abs($a - $b) >= 1e-9 ? 1.0 : 0.0,
                    default => throw new RuntimeException(sprintf('operador "%s" desconhecido', $op)),
                };
                continue;
            }

            // função
            $nome  = (string) $item[1];
            $aridade = (int) ($item[2] ?? 0);

            if (!array_key_exists($nome, self::FUNCOES)) {
                throw new RuntimeException(sprintf('função "%s" não existe', $nome));
            }

            $esperada = self::FUNCOES[$nome];
            if ($esperada !== null && $aridade !== $esperada) {
                throw new RuntimeException(
                    sprintf('"%s" espera %d argumentos, recebeu %d', $nome, $esperada, $aridade)
                );
            }

            $argumentos = [];
            for ($i = 0; $i < $aridade; $i++) {
                array_unshift($argumentos, $this->tirar($p));
            }

            $p[] = match ($nome) {
                'min'   => min($argumentos),
                'max'   => max($argumentos),
                'se'    => $argumentos[0] != 0.0 ? $argumentos[1] : $argumentos[2],
                'abs'   => abs($argumentos[0]),
                'arred' => round($argumentos[0], (int) $argumentos[1]),
                'teto'  => ceil($argumentos[0]),
                'piso'  => floor($argumentos[0]),
                default => throw new RuntimeException(sprintf('função "%s" não implementada', $nome)),
            };
        }

        if (count($p) !== 1) {
            throw new RuntimeException('fórmula incompleta');
        }

        return (float) $p[0];
    }

    /** @param list<float> $p */
    private function tirar(array &$p): float
    {
        if ($p === []) {
            throw new RuntimeException('faltou operando');
        }

        return (float) array_pop($p);
    }
}
