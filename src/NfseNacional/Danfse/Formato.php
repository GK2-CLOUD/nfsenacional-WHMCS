<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Formatacao dos valores do XML para exibicao no DANFS-e.
 *
 * As mascaras foram conferidas contra o DANFS-e oficial da NFS-e 597.
 * Duas divergencias deliberadas em relacao ao oficial estao anotadas
 * nos metodos cep() e ibge().
 *
 * Todo metodo aceita null e devolve TRACO — campo vazio nunca sai em
 * branco, para nao abrir buraco na grade.
 */
class Formato
{
    /** Travessao usado em campo sem valor. */
    public const TRACO = '—';

    public static function texto(?string $v): string
    {
        $v = $v === null ? '' : trim($v);

        return $v === '' ? self::TRACO : $v;
    }

    /**
     * Junta partes com um separador, trocando as vazias por travessao.
     * Se TODAS forem vazias, devolve um unico travessao.
     */
    public static function juntar(string $sep, ?string ...$partes): string
    {
        $temAlgo = false;
        $saida = [];

        foreach ($partes as $p) {
            $p = $p === null ? '' : trim($p);
            if ($p !== '') {
                $temAlgo = true;
            }
            $saida[] = $p === '' ? self::TRACO : $p;
        }

        return $temAlgo ? implode($sep, $saida) : self::TRACO;
    }

    // ─── Numeros ───────────────────────────────────────────────────

    public static function moeda(?string $v): string
    {
        if ($v === null || trim($v) === '') {
            return self::TRACO;
        }

        return 'R$ ' . number_format((float) $v, 2, ',', '.');
    }

    public static function percentual(?string $v): string
    {
        if ($v === null || trim($v) === '') {
            return self::TRACO;
        }

        return number_format((float) $v, 2, ',', '.') . '%';
    }

    /**
     * Soma valores numericos do XML. Devolve null se nenhum existir,
     * para o chamador poder distinguir "zero" de "nao informado".
     */
    public static function somar(?string ...$valores): ?string
    {
        $total = 0.0;
        $achou = false;

        foreach ($valores as $v) {
            if ($v !== null && trim($v) !== '') {
                $total += (float) $v;
                $achou = true;
            }
        }

        return $achou ? number_format($total, 2, '.', '') : null;
    }

    // ─── Datas ─────────────────────────────────────────────────────

    public static function data(?string $v): string
    {
        $dt = self::parseData($v);

        return $dt ? $dt->format('d/m/Y') : self::TRACO;
    }

    public static function dataHora(?string $v): string
    {
        $dt = self::parseData($v);

        return $dt ? $dt->format('d/m/Y H:i:s') : self::TRACO;
    }

    private static function parseData(?string $v): ?\DateTimeImmutable
    {
        if ($v === null || trim($v) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($v));
        } catch (\Exception) {
            return null;
        }
    }

    // ─── Documentos e contato ──────────────────────────────────────

    /**
     * CNPJ (14) ou CPF (11). Qualquer outro tamanho sai como veio —
     * NIF estrangeiro nao tem mascara previsivel.
     */
    public static function documento(?string $v): string
    {
        $d = self::digitos($v);

        if (strlen($d) === 14) {
            return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3)
                . '/' . substr($d, 8, 4) . '-' . substr($d, 12, 2);
        }

        if (strlen($d) === 11) {
            return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3)
                . '-' . substr($d, 9, 2);
        }

        return self::texto($v);
    }

    /**
     * O DANFS-e oficial imprime "87.013-230", com um ponto que nao existe
     * no padrao dos Correios. Seguimos o padrao, nao o oficial.
     */
    public static function cep(?string $v): string
    {
        $d = self::digitos($v);

        if (strlen($d) !== 8) {
            return self::texto($v);
        }

        return substr($d, 0, 5) . '-' . substr($d, 5, 3);
    }

    /**
     * O oficial imprime o IBGE como "41.15200", com ponto apos 2 digitos.
     * E defeito do gerador do governo; imprimimos os 7 digitos limpos.
     */
    public static function ibge(?string $v): string
    {
        return self::texto($v);
    }

    public static function telefone(?string $v): string
    {
        $d = self::digitos($v);

        if (strlen($d) === 11) {
            return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7, 4);
        }

        if (strlen($d) === 10) {
            return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6, 4);
        }

        return self::texto($v);
    }

    // ─── Codigos fiscais ───────────────────────────────────────────

    /** cTribNac: 6 digitos exibidos como 01.03.02 */
    public static function cTribNac(?string $v): string
    {
        $d = self::digitos($v);

        if (strlen($d) !== 6) {
            return self::texto($v);
        }

        return substr($d, 0, 2) . '.' . substr($d, 2, 2) . '.' . substr($d, 4, 2);
    }

    /** cNBS: 9 digitos exibidos como 1.1506.10.00 */
    public static function cNBS(?string $v): string
    {
        $d = self::digitos($v);

        if (strlen($d) !== 9) {
            return self::texto($v);
        }

        return substr($d, 0, 1) . '.' . substr($d, 1, 4) . '.'
            . substr($d, 5, 2) . '.' . substr($d, 7, 2);
    }

    /** Serie da DPS vem zero-preenchida no XML ("00001") e sai como "1". */
    public static function serie(?string $v): string
    {
        if ($v === null || trim($v) === '') {
            return self::TRACO;
        }

        $limpo = ltrim(trim($v), '0');

        return $limpo === '' ? '0' : $limpo;
    }

    /**
     * Chave de acesso: 50 digitos.
     *
     * O DANFS-e oficial imprime corrida. O agrupamento existe como opcao
     * para a calibracao visual da Fase 3 — 50 nao divide por 4, entao
     * grupos de 5 sao os unicos que fecham certo.
     */
    public static function chave(?string $v, int $grupo = 0): string
    {
        $d = self::digitos($v);

        if ($d === '') {
            return self::TRACO;
        }

        if ($grupo <= 0) {
            return $d;
        }

        return trim(chunk_split($d, $grupo, ' '));
    }

    public static function digitos(?string $v): string
    {
        return preg_replace('/\D/', '', (string) $v);
    }
}
