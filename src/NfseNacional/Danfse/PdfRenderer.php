<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Converte o HTML preenchido do DANFS-e em PDF, via TCPDF.
 *
 * O TCPDF vem com o WHMCS (vendor/tecnickcom/tcpdf) — o modulo nao
 * empacota o seu. Versao verificada em homologacao: 6.10.0.
 *
 * Geometria (decisao registrada no plano §1): A4 retrato, margens de
 * 10 mm nos quatro lados, area util de 190 mm. O guia TCPDF §2 fala em
 * 3,5 mm; esta desatualizado.
 */
class PdfRenderer
{
    private const MARGEM_PADRAO = 10.0;

    /** Lado do QR Code, em mm. */
    private const QR_LADO = 25.0;

    /**
     * Topo do QR, em mm. O X e calculado em qrX(), a partir da margem.
     * Calibrado contra o PDF gerado — mexer aqui desalinha o QR do slot
     * reservado no cabecalho do template.
     */
    private const QR_Y = 11.0;

    /**
     * Fonte base do documento.
     *
     * dejavusans em vez da core helvetica: e TrueType Unicode, entao
     * imprime o travessao dos campos vazios e os acentos sem depender do
     * mapeamento cp1252 das fontes core. A instalacao do WHMCS nao traz
     * dejavusansmono, que o template pedia originalmente.
     */
    private const FONTE_PADRAO = 'dejavusans';
    private const TAMANHO = 7.5;

    private float $margem;
    private string $fonte;

    /**
     * Margem e fonte sao parametrizaveis para a calibracao — nao para uso
     * corrente. Os padroes sao os valores decididos; mudar em producao
     * desalinha o QR e pode empurrar o documento para a segunda pagina.
     */
    public function __construct(?float $margem = null, ?string $fonte = null)
    {
        $this->margem = $margem ?? self::MARGEM_PADRAO;
        $this->fonte  = $fonte  ?? self::FONTE_PADRAO;
    }

    /** O slot do QR fica encostado na margem direita. */
    private function qrX(): float
    {
        return 210.0 - $this->margem - self::QR_LADO;
    }

    /**
     * Gera o PDF e devolve os bytes.
     *
     * @param string $html       Saida de TemplateRenderer::render()
     * @param string $qrConteudo Texto codificado no QR (URL de consulta)
     * @param array  $meta       numero, chave, ambiente — metadados do PDF
     *
     * @throws \RuntimeException se o TCPDF nao estiver disponivel
     */
    public function render(string $html, string $qrConteudo, array $meta = []): string
    {
        if (!class_exists('\TCPDF')) {
            throw new \RuntimeException(
                'TCPDF nao encontrado. O DANFS-e GK2 depende do TCPDF que acompanha o WHMCS.'
            );
        }

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');

        $pdf->SetMargins($this->margem, $this->margem, $this->margem);
        $pdf->SetAutoPageBreak(true, $this->margem);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont($this->fonte, '', self::TAMANHO);

        $this->aplicarMetadados($pdf, $meta);

        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        $this->desenharQr($pdf, $qrConteudo);

        // 'S' devolve a string; o DownloadController e quem escreve os headers.
        return $pdf->Output('danfse.pdf', 'S');
    }

    /**
     * Numero de paginas que o HTML ocupou. So faz sentido depois de
     * render(); serve para o teste de calibracao acusar quebra para a
     * segunda pagina, que o layout nao previa.
     */
    public function paginas(string $html, string $qrConteudo, array $meta = []): int
    {
        if (!class_exists('\TCPDF')) {
            return 0;
        }

        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetMargins($this->margem, $this->margem, $this->margem);
        $pdf->SetAutoPageBreak(true, $this->margem);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont($this->fonte, '', self::TAMANHO);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        return $pdf->getNumPages();
    }

    // ─────────────────────────────────────────────────────────────────

    private function aplicarMetadados(\TCPDF $pdf, array $meta): void
    {
        $numero = trim((string) ($meta['numero'] ?? ''));

        $pdf->SetTitle('DANFS-e' . ($numero !== '' ? ' ' . $numero : '') . ' — GK2 Cloud');
        $pdf->SetAuthor('GK2 CLOUD LTDA');
        $pdf->SetSubject('Documento Auxiliar da NFS-e');
        $pdf->SetCreator('WHMCS · GK2 Cloud');

        if (!empty($meta['chave'])) {
            $pdf->SetKeywords('NFS-e, DANFS-e, ' . $meta['chave']);
        }
    }

    /**
     * O TCPDF nao desenha QR a partir do HTML — tem que ser por API,
     * DEPOIS do writeHTML, por cima do slot reservado no cabecalho.
     *
     * QR vazio nao invalida o documento (a chave de acesso impressa ja
     * permite a consulta), entao um conteudo em branco apenas nao
     * desenha, em vez de abortar a geracao.
     */
    private function desenharQr(\TCPDF $pdf, string $conteudo): void
    {
        if (trim($conteudo) === '') {
            return;
        }

        $estilo = [
            'border'  => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
        ];

        $pdf->write2DBarcode(
            $conteudo,
            'QRCODE,M',
            $this->qrX(),
            self::QR_Y,
            self::QR_LADO,
            self::QR_LADO,
            $estilo,
            'N'
        );
    }
}
