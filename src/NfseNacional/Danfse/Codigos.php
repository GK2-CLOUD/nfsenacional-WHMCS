<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Traducao dos codigos do XML para o texto que vai impresso.
 *
 * REGRA: codigo desconhecido devolve o proprio codigo, nunca um palpite.
 * Uma nota so exercita um valor por dominio, entao a suite de testes nao
 * protege dominio nenhum sozinha — cada entrada precisa de fonte.
 *
 * Cada entrada carrega a sua:
 *   @conferido — lido no DANFS-e oficial da NFS-e 597
 *   @modulo    — dominio declarado pelo proprio addon, em ConfigFields
 *                (dropdowns) e no CLAUDE.md do modulo
 *
 * Sem uma das duas, a entrada NAO existe aqui: cai no fallback e imprime
 * o codigo cru. Melhor um "7" na nota do que um rotulo inventado.
 */
class Codigos
{
    /** Situacao da NFS-e (cStat). */
    private const C_STAT = [
        '100' => 'NFS-e Gerada',           // @conferido
    ];

    /**
     * Emitente do documento (tpEmit).
     *
     * So o valor 1 tem fonte. Tomador e intermediario como emitentes
     * existem no padrao, mas nao aparecem em nenhuma fonte local — ficam
     * de fora ate alguem confirmar no manual.
     */
    private const TP_EMIT = [
        '1' => 'Prestador',                // @conferido
    ];

    /** Finalidade (finNFSe). */
    private const FIN_NFSE = [
        '0' => 'NFS-e regular',            // @conferido
    ];

    /**
     * Tipo de tributacao do ISSQN (tribISSQN).
     *
     * Dominio de ConfigFields['exigibilidade_iss'], que alimenta este mesmo
     * elemento no XML. O valor 1 sai com o rotulo do DANFS-e oficial
     * ("Operação Tributável"), nao com o do dropdown ("Exigível"): quem le
     * a nota le o documento fiscal, nao a tela de configuracao.
     */
    private const TRIB_ISSQN = [
        '1' => 'Operação Tributável',              // @conferido
        '2' => 'Não Incidência',                   // @modulo
        '3' => 'Isenção',                          // @modulo
        '4' => 'Exportação',                       // @modulo
        '5' => 'Imunidade',                        // @modulo
        '6' => 'Suspensa por Decisão Judicial',    // @modulo
        '7' => 'Suspensa por Processo Administrativo', // @modulo
    ];

    /**
     * Retencao do ISSQN (tpRetISSQN).
     *
     * 2 e 3 vem do proprio EmissaoService, que trata "> 1" como retido
     * e distingue tomador de intermediario ao gravar retido_iss.
     */
    private const TP_RET_ISSQN = [
        '1' => 'Não Retido',                  // @conferido
        '2' => 'Retido pelo Tomador',         // @modulo
        '3' => 'Retido pelo Intermediário',   // @modulo
    ];

    /** Enquadramento no Simples Nacional (opSimpNac). @modulo: PrestadorMapper */
    private const OP_SIMP_NAC = [
        '1' => 'Não Optante',                 // @modulo
        '2' => 'Optante - MEI',               // @modulo
        '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte', // @conferido
    ];

    /**
     * Regime de apuracao pelo SN (regApTribSN).
     *
     * CONFLITO DE FONTES, deliberadamente nao resolvido:
     * o DANFS-e oficial imprime, para o valor 1, a frase longa abaixo;
     * ConfigFields['reg_ap_trib_sn'] rotula o mesmo 1 como "Competência"
     * e o 2 como "Caixa" — registros diferentes, e nao da para saber se
     * descrevem a mesma coisa.
     *
     * Mantemos so o 1, que tem o texto lido no documento oficial. O 2 cai
     * no fallback e imprime "2" ate alguem conferir no manual. Preferimos
     * um numero na nota a escolher o rotulo errado entre dois candidatos.
     */
    private const REG_AP_TRIB_SN = [
        '1' => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional', // @conferido
    ];

    /** Regime especial de tributacao (regEspTrib). @modulo: ConfigFields */
    private const REG_ESP_TRIB = [
        '0' => 'Nenhum',                      // @modulo
        '1' => 'Estimativa Anual',            // @modulo
        '2' => 'Profissional Autônomo',       // @modulo
        '3' => 'Sociedade de Profissionais',  // @modulo
        '4' => 'Cooperativa',                 // @modulo
        '5' => 'MEI',                         // @modulo
        '6' => 'ME-EPP Simples Nacional',     // @modulo
    ];

    /**
     * Tipo de ambiente (tpAmb).
     *
     * NAO CONFUNDIR com ambGer, que diz QUEM gerou a nota (1 = prefeitura,
     * 2 = SEFIN Nacional) e nada tem a ver com producao/homologacao.
     * Ligar a tarja "SEM VALOR FISCAL" em ambGer carimba toda nota de
     * producao. O DANFS-e oficial imprime os dois campos separadamente.
     */
    private const TP_AMB = [
        '1' => 'Produção',                 // @conferido
        '2' => 'Homologação',              // @modulo: enum Ambiente
    ];

    public static function situacao(?string $c): ?string
    {
        return self::traduzir(self::C_STAT, $c);
    }

    public static function emitente(?string $c): ?string
    {
        return self::traduzir(self::TP_EMIT, $c);
    }

    public static function finalidade(?string $c): ?string
    {
        return self::traduzir(self::FIN_NFSE, $c);
    }

    public static function tributacaoIssqn(?string $c): ?string
    {
        return self::traduzir(self::TRIB_ISSQN, $c);
    }

    public static function retencaoIssqn(?string $c): ?string
    {
        return self::traduzir(self::TP_RET_ISSQN, $c);
    }

    public static function simplesNacional(?string $c): ?string
    {
        return self::traduzir(self::OP_SIMP_NAC, $c);
    }

    public static function regimeApuracaoSn(?string $c): ?string
    {
        return self::traduzir(self::REG_AP_TRIB_SN, $c);
    }

    public static function regimeEspecial(?string $c): ?string
    {
        return self::traduzir(self::REG_ESP_TRIB, $c);
    }

    /**
     * Tipo de ambiente no formato do guia: "1 — Produção".
     * Mantem o codigo visivel, como faz o DANFS-e oficial.
     */
    public static function tipoAmbiente(?string $c): ?string
    {
        if ($c === null || trim($c) === '') {
            return null;
        }

        $c = trim($c);
        $rotulo = self::TP_AMB[$c] ?? null;

        return $rotulo === null ? $c : $c . ' — ' . $rotulo;
    }

    /**
     * Indica se a nota e de homologacao — a unica fonte valida para o
     * bloco condicional da tarja "SEM VALOR FISCAL".
     */
    public static function isHomologacao(?string $tpAmb): bool
    {
        return trim((string) $tpAmb) === '2';
    }

    private static function traduzir(array $mapa, ?string $codigo): ?string
    {
        if ($codigo === null || trim($codigo) === '') {
            return null;
        }

        $codigo = trim($codigo);

        return $mapa[$codigo] ?? $codigo;
    }
}
