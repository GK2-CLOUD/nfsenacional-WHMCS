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

    /**
     * Lado do QR Code, em mm.
     *
     * 28,4mm — a ALTURA DA COLUNA DE TEXTO do cabecalho (rotulo, numero,
     * competencia e emissao), medida no PDF: de 14,55mm a 42,94mm.
     *
     * O lado nao e arbitrario nem os 25mm do slot do mockup: sendo o QR
     * um quadrado, so ha como alinhar topo E base com a coluna vizinha se
     * ele tiver exatamente a altura dela. Com 25mm sobravam 3,4mm em uma
     * das pontas, dependendo de qual aresta se escolhesse casar.
     *
     * O tamanho sozinho nao decide a aparencia: o que deixa o QR "grosso"
     * e a razao entre lado e numero de modulos. Com correcao H sao 49
     * modulos, e 28,4mm dao 0,58mm por modulo — dentro da faixa fina.
     * Ver QR_CORRECAO.
     */
    private const QR_LADO = 28.4;

    /**
     * Nivel de correcao de erro.
     *
     * H (30% de recuperacao) em vez de M (15%). Escolhido por dois
     * motivos que apontam na mesma direcao: adensa a malha, o que resolve
     * o aspecto grosso no slot de 25mm, e dobra a tolerancia a sujeira,
     * dobra e desgaste — o DANFS-e e feito para ser impresso.
     */
    private const QR_CORRECAO = 'QRCODE,H';

    /**
     * Recuo horizontal do QR em relacao a margem da pagina, em mm.
     *
     * 4mm poe a aresta direita do QR em 196mm — exatamente onde terminam
     * as faixas de secao e os blocos do corpo. Medido no PDF; mexer aqui
     * desalinha o QR da coluna do documento.
     */
    private const QR_RECUO_X = 4.0;

    /**
     * Topo do QR, em mm a partir da borda da pagina.
     *
     * 14,55mm e o topo da coluna de texto do cabecalho. Com QR_LADO igual
     * a altura dessa coluna, topo e base coincidem com ela.
     */
    private const QR_Y = 14.55;

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

    /** Aresta direita do QR alinhada a coluna do documento (196mm). */
    private function qrX(): float
    {
        return 210.0 - $this->margem - self::QR_RECUO_X - self::QR_LADO;
    }

    private function qrY(): float
    {
        return self::QR_Y;
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

        // write2DBarcode desenha na pagina CORRENTE. Se o conteudo tiver
        // transbordado para a segunda pagina, a corrente e a 2 — e o QR
        // sumiria do cabecalho sem ninguem notar. O slot esta sempre na 1.
        if ($pdf->getNumPages() > 1) {
            $pdf->setPage(1);
        }

        $estilo = [
            'border'  => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => [255, 255, 255],
        ];

        $pdf->write2DBarcode(
            $conteudo,
            self::QR_CORRECAO,
            $this->qrX(),
            $this->qrY(),
            self::QR_LADO,
            self::QR_LADO,
            $estilo,
            'N'
        );
    }
}
