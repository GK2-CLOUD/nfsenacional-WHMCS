<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Traducao dos codigos do XML para o texto que vai impresso.
 *
 * REGRA: codigo desconhecido devolve o proprio codigo, nunca um palpite.
 * Uma nota so exercita um valor por dominio; os valores marcados
 * "@conferido" foram lidos no DANFS-e oficial da NFS-e 597. Os demais vem
 * da tabela de dominios do XSD e devem ser confirmados contra o manual
 * oficial antes de virar verdade — por isso o fallback nunca inventa.
 */
class Codigos
{
    /** Situacao da NFS-e (cStat). */
    private const C_STAT = [
        '100' => 'NFS-e Gerada',           // @conferido
    ];

    /** Emitente do documento (tpEmit). */
    private const TP_EMIT = [
        '1' => 'Prestador',                // @conferido
        '2' => 'Tomador',
        '3' => 'Intermediário',
    ];

    /** Finalidade (finNFSe). */
    private const FIN_NFSE = [
        '0' => 'NFS-e regular',            // @conferido
        '3' => 'NFS-e de substituição',
        '4' => 'NFS-e de ajuste',
    ];

    /** Tipo de tributacao do ISSQN (tribISSQN). */
    private const TRIB_ISSQN = [
        '1' => 'Operação Tributável',      // @conferido
        '2' => 'Exportação de serviço',
        '3' => 'Não Incidência',
        '4' => 'Imunidade',
    ];

    /** Retencao do ISSQN (tpRetISSQN). */
    private const TP_RET_ISSQN = [
        '1' => 'Não Retido',               // @conferido
        '2' => 'Retido pelo Tomador',
        '3' => 'Retido pelo Intermediário',
    ];

    /** Enquadramento no Simples Nacional (opSimpNac). */
    private const OP_SIMP_NAC = [
        '1' => 'Não Optante',
        '2' => 'Optante - MEI',
        '3' => 'Optante - Microempresa ou Empresa de Pequeno Porte', // @conferido
    ];

    /** Regime de apuracao pelo SN (regApTribSN). */
    private const REG_AP_TRIB_SN = [
        // @conferido
        '1' => 'Regime de apuração dos tributos federais e municipal pelo Simples Nacional',
        '2' => 'Regime de apuração dos tributos federais pelo SN e ISSQN por fora do SN',
        '3' => 'Regime de apuração dos tributos federais e municipal por fora do Simples Nacional',
    ];

    /** Regime especial de tributacao (regEspTrib). */
    private const REG_ESP_TRIB = [
        '0' => 'Nenhum',
        '1' => 'Ato Cooperado',
        '2' => 'Estimativa',
        '3' => 'Microempresa Municipal',
        '4' => 'Notário ou Registrador',
        '5' => 'Profissional Autônomo',
        '6' => 'Sociedade de Profissionais',
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
        '2' => 'Homologação',
    ];

    /** Ambiente gerador (ambGer) — ver aviso acima. */
    private const AMB_GER = [
        '1' => 'Prefeitura',
        '2' => 'SEFIN Nacional',           // @conferido
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

    public static function ambienteGerador(?string $c): ?string
    {
        return self::traduzir(self::AMB_GER, $c);
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
