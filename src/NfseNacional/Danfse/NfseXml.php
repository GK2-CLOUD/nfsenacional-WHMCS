<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Leitura do XML da NFS-e Nacional.
 *
 * O documento usa namespace default (http://www.sped.fazenda.gov.br/nfse) e
 * aninha o DPS assinado dentro do proprio infNFSe, redeclarando o mesmo
 * namespace. Por isso todo acesso passa por DOMXPath com o prefixo 'n'
 * registrado — SimpleXML sem namespace nao enxerga nada aqui.
 *
 * ATENCAO aos dois blocos IBSCBS: um em infNFSe (valores apurados pelo Fisco)
 * e outro em infDPS (o que o contribuinte declarou). Sao diferentes. Use
 * sempre os caminhos absolutos das constantes, nunca '//n:IBSCBS'.
 */
class NfseXml
{
    public const NS = 'http://www.sped.fazenda.gov.br/nfse';

    // Caminhos base — evitam a ambiguidade entre infNFSe e infDPS
    public const INF_NFSE = '/n:NFSe/n:infNFSe';
    public const EMIT     = self::INF_NFSE . '/n:emit';
    public const IBSCBS   = self::INF_NFSE . '/n:IBSCBS';
    public const INF_DPS  = self::INF_NFSE . '/n:DPS/n:infDPS';
    public const PREST    = self::INF_DPS . '/n:prest';
    public const TOMA     = self::INF_DPS . '/n:toma';
    public const SERV     = self::INF_DPS . '/n:serv';
    public const VAL_DPS  = self::INF_DPS . '/n:valores';

    private \DOMDocument $dom;
    private \DOMXPath $xpath;
    private string $raw;

    private function __construct(string $xml)
    {
        $this->raw = $xml;

        $anterior = libxml_use_internal_errors(true);
        $this->dom = new \DOMDocument();
        $ok = $this->dom->loadXML($xml);
        $erros = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if (!$ok) {
            $detalhe = $erros ? trim($erros[0]->message) : 'motivo desconhecido';
            throw new \RuntimeException('XML da NFS-e invalido: ' . $detalhe);
        }

        $this->xpath = new \DOMXPath($this->dom);
        $this->xpath->registerNamespace('n', self::NS);

        if ($this->v(self::INF_NFSE . '/n:nNFSe') === null) {
            throw new \RuntimeException('XML nao parece uma NFS-e Nacional: nNFSe ausente.');
        }
    }

    public static function fromXml(string $xml): self
    {
        return new self($xml);
    }

    /**
     * Cria a partir do conteudo de tblnfsenacional.xml_retorno,
     * que guarda o nfseXmlGZipB64 exatamente como veio da SEFIN.
     */
    public static function fromGzipB64(string $b64): self
    {
        $bin = base64_decode(trim($b64), true);
        if ($bin === false) {
            throw new \RuntimeException('xml_retorno nao e base64 valido.');
        }

        $xml = @gzdecode($bin);
        if ($xml === false) {
            // Toleramos registro gravado ja descomprimido
            $xml = $bin;
        }

        return self::fromXml($xml);
    }

    /**
     * Valor textual do primeiro no que casar, ou null se ausente/vazio.
     */
    public function v(string $xpath): ?string
    {
        $r = $this->xpath->query($xpath);
        if ($r === false || $r->length === 0) {
            return null;
        }

        $texto = trim($r->item(0)->textContent);

        return $texto === '' ? null : $texto;
    }

    /**
     * Primeiro valor nao nulo entre varios caminhos (para choices do XSD,
     * como CNPJ vs CPF do tomador).
     */
    public function first(string ...$xpaths): ?string
    {
        foreach ($xpaths as $x) {
            $valor = $this->v($x);
            if ($valor !== null) {
                return $valor;
            }
        }

        return null;
    }

    public function has(string $xpath): bool
    {
        return $this->v($xpath) !== null;
    }

    /**
     * Chave de acesso: 50 digitos, extraidos do atributo Id do infNFSe,
     * que vem prefixado com "NFS".
     *
     * Nao sao 44 digitos — 44 e layout de NF-e.
     */
    public function chaveAcesso(): ?string
    {
        $r = $this->xpath->query(self::INF_NFSE . '/@Id');
        if ($r === false || $r->length === 0) {
            return null;
        }

        $id = trim($r->item(0)->nodeValue);
        $chave = preg_replace('/^NFS/', '', $id);

        return $chave !== '' ? $chave : null;
    }

    public function raw(): string
    {
        return $this->raw;
    }
}
