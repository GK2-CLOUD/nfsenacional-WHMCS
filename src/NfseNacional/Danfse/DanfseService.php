<?php

namespace GK2\NfseNacional\Danfse;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\Entity\Nfse;
use GK2\NfseNacional\Fiscal\Mapper\TomadorMapper;
use GK2\NfseNacional\Persistence\NfseRepository;

/**
 * Gera o DANFS-e no modelo GK2 a partir de uma NFS-e ja emitida.
 *
 * Nao chama a API do governo para montar o documento: o XML da NFS-e ja
 * esta em tblnfsenacional.xml_retorno, gravado na emissao e na consulta.
 * Isso torna o download imune a indisponibilidade do ADN — que, segundo o
 * proprio DownloadController, devolve 502 em ate 50% dos requests.
 */
class DanfseService
{
    private NfseRepository $repository;
    private ModuleConfig $config;
    private TomadorMapper $tomador;
    private TemplateRenderer $template;
    private PdfRenderer $pdf;

    public function __construct(
        ?NfseRepository $repository = null,
        ?ModuleConfig $config = null,
        ?TomadorMapper $tomador = null,
        ?TemplateRenderer $template = null,
        ?PdfRenderer $pdf = null,
    ) {
        $this->repository = $repository ?? new NfseRepository();
        $this->config     = $config ?? new ModuleConfig();
        $this->tomador    = $tomador ?? new TomadorMapper($this->config);
        $this->template   = $template ?? new TemplateRenderer();
        $this->pdf        = $pdf ?? new PdfRenderer();
    }

    /**
     * Bytes do PDF.
     *
     * @param callable|null $buscarXml fn(string $url): string — usado so
     *        quando a nota nao tem xml_retorno gravado. Recebe a xml_url e
     *        devolve o corpo da resposta da SEFIN. O chamador injeta isso
     *        para reaproveitar o fetch com mTLS e retry que ja existe, em
     *        vez de este servico abrir a sua propria conexao.
     *
     * @throws \RuntimeException quando nao ha como montar o documento
     */
    public function gerarPdf(Nfse $nfse, ?callable $buscarXml = null): string
    {
        $xml = NfseXml::fromXml($this->obterXml($nfse, $buscarXml));

        $mapper = new TokenMapper($this->config->getInscricaoMunicipal());
        $tokens = $mapper->map($xml, $this->dadosTomador($nfse));

        $html = $this->template->render($tokens, [
            'se_homologacao' => $mapper->isHomologacao($xml),
        ]);

        return $this->pdf->render(
            $html,
            ConsultaPublica::url($xml->chaveAcesso()),
            ['numero' => $tokens['numero_nfse'], 'chave' => $xml->chaveAcesso()]
        );
    }

    /**
     * Nome sugerido para o arquivo.
     */
    public function nomeArquivo(Nfse $nfse): string
    {
        $chave = $nfse->chaveAcesso ?: (string) $nfse->id;

        return 'danfse-' . $chave . '.pdf';
    }

    // ─────────────────────────────────────────────────────────────────

    /**
     * XML da NFS-e, em tres niveis:
     *
     *   1. tblnfsenacional.xml_retorno — caminho normal, zero rede
     *   2. GET na xml_url, e GRAVA o resultado (auto-cura): notas emitidas
     *      antes da coluna existir passam a ter o XML na primeira vez que
     *      alguem baixa o documento
     *   3. falha — o chamador cai no DANFS-e oficial
     */
    private function obterXml(Nfse $nfse, ?callable $buscarXml): string
    {
        $b64 = $this->repository->xmlRetorno((int) $nfse->id);

        if (!empty($b64)) {
            return $this->descomprimir($b64);
        }

        if ($buscarXml === null || empty($nfse->xmlUrl)) {
            throw new \RuntimeException(
                'NFS-e ' . $nfse->id . ' sem xml_retorno e sem forma de buscar o XML.'
            );
        }

        $corpo = $buscarXml($nfse->xmlUrl);
        $dados = json_decode((string) $corpo, true);
        $b64 = $dados['nfseXmlGZipB64'] ?? null;

        if (empty($b64)) {
            // Resposta ja em XML puro: aproveita, mas nao ha o que gravar
            // no formato que o resto do modulo espera.
            if (str_contains((string) $corpo, '<NFSe')) {
                return (string) $corpo;
            }

            throw new \RuntimeException('Resposta da SEFIN sem nfseXmlGZipB64 para a NFS-e ' . $nfse->id . '.');
        }

        $this->repository->salvarXmlRetorno((int) $nfse->id, $b64);
        logActivity('NFS-e Nacional [DANFS-e GK2]: xml_retorno recuperado e gravado para a NFS-e ' . $nfse->id . '.');

        return $this->descomprimir($b64);
    }

    private function descomprimir(string $b64): string
    {
        $bin = base64_decode(trim($b64), true);
        if ($bin === false) {
            throw new \RuntimeException('xml_retorno nao e base64 valido.');
        }

        $xml = @gzdecode($bin);

        // Registro gravado sem compressao em alguma versao anterior
        return $xml === false ? $bin : $xml;
    }

    /**
     * Dados do tomador vem do WHMCS, pela mesma leitura que a emissao usa
     * (ver TokenMapper). Cliente removido do WHMCS nao impede a emissao do
     * documento: os campos ficam com travessao.
     */
    private function dadosTomador(Nfse $nfse): array
    {
        $clientId = (int) $nfse->clientId;

        if ($clientId <= 0) {
            return [];
        }

        try {
            return $this->tomador->mapParaExibicao($clientId);
        } catch (\Throwable $e) {
            logActivity('NFS-e Nacional [DANFS-e GK2]: falha ao ler o cliente ' . $clientId
                . ' para a NFS-e ' . $nfse->id . ' — ' . $e->getMessage());

            return [];
        }
    }
}
