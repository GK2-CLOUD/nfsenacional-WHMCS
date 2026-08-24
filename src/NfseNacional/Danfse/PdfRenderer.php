<?php

namespace GK2\NfseNacional\Danfse;

/**
 * Converte o HTML preenchido do DANFS-e em PDF, via TCPDF.
 *
 * O TCPDF vem com o WHMCS (vendor/tecnickcom/tcpdf) — o modulo nao
 * empacota o seu. Versao verificada em homologacao: 6.10.0.
 *
 * Geometria: A4 retrato, margens de 3,5 mm nos quatro lados, area util de
 * 203 mm — os mesmos 3,5 mm do padding de .sheet no mockup, que e onde
 * assenta a moldura do documento.
 */
class PdfRenderer
{
    private const MARGEM_PADRAO = 3.5;

    /**
     * Deriva horizontal do HTML, em mm.
     *
     * O TCPDF assenta a tabela externa 2,90mm a direita da margem esquerda,
     * mas encosta a direita dela na margem direita — o conteudo inteiro
     * nasce descentrado no papel. Medido a 600dpi com margem de 10mm e de
     * 3,5mm: o centro do conteudo deu 106,46mm nas duas, contra 105mm de
     * centro da folha. A deriva e constante, nao proporcional.
     *
     * Enquanto a moldura era a borda dessa mesma tabela, ela derivava junto
     * e nada aparecia. Desenhada no papel, a folga saia 6,4mm de um lado e
     * 3,5mm do outro.
     *
     * Nao adianta so encurtar a margem esquerda: a tabela e width=100%,
     * entao ela ALARGA em vez de andar. Para transladar, a margem direita
     * cresce o mesmo tanto — e por isso as margens do TCPDF sao assimetricas
     * enquanto a moldura e o conteudo ficam centrados no papel.
     */
    private const DERIVA_HTML = 2.90;

    /**
     * Quanto o HTML afasta os blocos da margem, em mm.
     *
     * 2,12mm de cellpadding do .wrap mais 1,41mm de cellspacing da tabela
     * container. Entra na conta das margens e na do QR, que precisa fechar
     * na mesma coluna dos blocos.
     */
    private const RECUO_BLOCO = 3.53;

    /**
     * Folga entre a moldura e os blocos, em mm.
     *
     * Unico numero de gosto nesta geometria; os outros sao medidos. 4,24mm
     * e a media da folga que o documento tinha antes da moldura passar a
     * ser desenhada no papel (4,97mm de um lado, 3,51mm do outro), entao a
     * largura util nao muda e nada reflui.
     */
    private const FOLGA_MOLDURA = 4.24;

    /** Distancia entre a linha de paginacao e o pe da moldura, em mm. */
    private const RODAPE_RECUO = 5.5;
    private const RODAPE_TAMANHO = 6.0;
    private const RODAPE_COR = [107, 107, 107];   // #6b6b6b, o mesmo de .foot

    /** Traco e cor da moldura — os mesmos que .wrap tinha no HTML. */
    private const MOLDURA_ESPESSURA = 0.4;
    private const MOLDURA_COR = [154, 154, 154];   // #9a9a9a

    /**
     * Lado do QR Code, em mm.
     *
     * 23,05mm — a ALTURA DA COLUNA DE TEXTO do cabecalho, medida no PDF.
     *
     * Tres restricoes se cruzam aqui, e o valor e o unico ponto onde as
     * tres fecham:
     *
     *   alinhar topo E base   -> sendo quadrado, o QR precisa ter
     *                            exatamente a altura da coluna vizinha
     *   modulo fino           -> lado pequeno (o numero de modulos e
     *                            fixo em 49; ver QR_CORRECAO)
     *   cabecalho respirando  -> coluna alta
     *
     * As duas primeiras empurram o lado para baixo, a terceira para cima.
     * O cabecalho foi comprimido ate 22,85mm — separando os grupos com
     * uma linha de 2pt em vez de outra de 7,5pt — e o QR acompanha.
     * Resultado: 0,467mm por modulo, mais fino que os 0,49mm de antes.
     *
     * Mexer na altura do cabecalho exige remedir e ajustar este valor.
     */
    private const QR_LADO = 23.05;

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
     * Topo do QR, em mm a partir da borda da pagina.
     *
     * 8,15mm e o topo da coluna de texto do cabecalho. Com QR_LADO igual
     * a altura dessa coluna, topo e base coincidem com ela.
     */
    private const QR_Y = 8.15;

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

    /**
     * Recuo horizontal do texto dentro das celulas, em mm.
     *
     * O TCPDF nao aceita padding-left, padding abreviado nem margin-left
     * para isso — todos testados e ignorados. O cellpadding funciona, mas
     * incide nos DOIS eixos e em cada uma das ~24 linhas do documento:
     * subir de 2 para 3 custaria 16mm de altura para render 0,35mm de
     * recuo.
     *
     * A saida e o <blockquote>, cujo recuo esquerdo o TCPDF expoe por
     * setListIndentWidth(). Sendo bloco, indenta TODAS as linhas — um
     * espacador &nbsp; indentaria so a primeira, deixando serrilhada a
     * margem de qualquer texto que quebre. E o custo de altura e zero.
     *
     * CUIDADO: o TCPDF desloca o texto para a direita mas NAO reduz a
     * largura de quebra, entao este recuo sai da folga do lado direito.
     * Com cellpadding=2 a celula tem 2,31mm de folga horizontal no total;
     * medido, cada milimetro aqui e um milimetro a menos antes da borda
     * direita, e a 1,5mm o texto passava a encostar nela. 0,75mm reparte
     * a folga sem encostar de nenhum dos lados.
     */
    private const RECUO_TEXTO = 0.75;

    private float $margem;
    private string $fonte;

    /**
     * Margens do TCPDF: [esquerda, topo, direita].
     *
     * Assimetricas de proposito — ver DERIVA_HTML. O que fica simetrico e o
     * que se ve: a moldura e a folga dela para os blocos.
     */
    private function margens(): array
    {
        $esq = $this->margem + self::FOLGA_MOLDURA - self::DERIVA_HTML - self::RECUO_BLOCO;

        return [$esq, $this->margem, $esq + self::DERIVA_HTML];
    }

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

    /**
     * Coluna util do documento: [x da aresta esquerda, largura].
     *
     * E onde comecam e terminam os blocos. O QR e a linha de paginacao se
     * alinham por ela, nao pela margem.
     */
    private function colunaConteudo(): array
    {
        [$esq, , $dir] = $this->margens();
        $x = $esq + self::RECUO_BLOCO;

        return [$x, 210.0 - $dir - self::RECUO_BLOCO - $x];
    }

    /** Aresta direita do QR na mesma coluna em que terminam os blocos. */
    private function qrX(): float
    {
        [$x, $largura] = $this->colunaConteudo();

        return $x + $largura - self::QR_LADO;
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

        $pdf = new DanfsePdf('P', 'mm', 'A4', true, 'UTF-8');

        [$esq, $topo, $dir] = $this->margens();
        $pdf->SetMargins($esq, $topo, $dir);
        $pdf->SetAutoPageBreak(true, $this->margem);
        // Header() nao imprime nada; existe so para dar as paginas de
        // continuacao a mesma folga que a moldura tem nas laterais.
        $pdf->setPrintHeader(true);
        $pdf->setHeaderFont([$this->fonte, '', self::TAMANHO]);
        $pdf->definirMargemContinuacao($this->margem + self::FOLGA_MOLDURA);
        $pdf->setPrintFooter(false);
        $pdf->SetFont($this->fonte, '', self::TAMANHO);
        $pdf->setListIndentWidth(self::RECUO_TEXTO);

        $this->aplicarMetadados($pdf, $meta);

        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        $this->desenharMoldura($pdf);
        $this->numerarPaginas($pdf, $meta);
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

        $pdf = new DanfsePdf('P', 'mm', 'A4', true, 'UTF-8');

        [$esq, $topo, $dir] = $this->margens();
        $pdf->SetMargins($esq, $topo, $dir);
        $pdf->SetAutoPageBreak(true, $this->margem);
        // Header() nao imprime nada; existe so para dar as paginas de
        // continuacao a mesma folga que a moldura tem nas laterais.
        $pdf->setPrintHeader(true);
        $pdf->setHeaderFont([$this->fonte, '', self::TAMANHO]);
        $pdf->definirMargemContinuacao($this->margem + self::FOLGA_MOLDURA);
        $pdf->setPrintFooter(false);
        $pdf->SetFont($this->fonte, '', self::TAMANHO);
        $pdf->setListIndentWidth(self::RECUO_TEXTO);
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
     * Moldura externa do documento.
     *
     * Vinha da borda de .wrap, no HTML. Uma borda de tabela termina onde o
     * conteudo termina — a moldura fechava logo abaixo do rodape e sobrava
     * um palmo de papel branco fora dela. No mockup a .wrap tem
     * height="100%", entao a moldura desce ate o pe da folha.
     *
     * O TCPDF nao implementa height:100% em tabela, entao ela e desenhada
     * por API: um retangulo da margem a margem, nos quatro lados. Como so
     * tem traco (sem preenchimento), pode ser desenhado depois do conteudo.
     *
     * Em TODAS as paginas: a moldura e o contorno do documento, nao do
     * comeco dele. Uma segunda pagina sem moldura nao se parece com a
     * primeira nem com um DANFS-e.
     */
    private function desenharMoldura(\TCPDF $pdf): void
    {
        for ($pagina = 1; $pagina <= $pdf->getNumPages(); $pagina++) {
            $pdf->setPage($pagina);
            $this->moldurar($pdf);
        }
    }

    private function moldurar(\TCPDF $pdf): void
    {
        $pdf->Rect(
            $this->margem,
            $this->margem,
            210.0 - 2 * $this->margem,
            297.0 - 2 * $this->margem,
            'D',
            ['all' => [
                'width' => self::MOLDURA_ESPESSURA,
                'color' => self::MOLDURA_COR,
            ]]
        );
    }

    /**
     * Identificacao e numero de pagina, no rodape de cada folha.
     *
     * So aparece quando o documento passa de uma pagina. O caso comum e uma
     * folha, e ai a linha seria ruido. Nao ha teto de duas: o corte de 2000
     * caracteres do xDescServ limita o texto, nao a altura — medido, 60
     * itens de uma linha cada cabem nos 2000 caracteres e levam o documento
     * a TRES paginas.
     *
     * Existe porque uma folha solta precisa se identificar: sem ela, a
     * pagina 2 nao diz de que nota e, nem que existe uma pagina 1. Por isso
     * repete numero e chave de acesso, e nao so "2 de 2".
     *
     * A PARTIR DA SEGUNDA. A primeira ja se identifica sozinha — tem o
     * cabecalho com o numero e o bloco da chave de acesso — e a linha so
     * repetia o que estava logo acima.
     *
     * Fica no vao entre o fim do conteudo e o pe da moldura, entao nao
     * disputa espaco com o documento.
     */
    private function numerarPaginas(\TCPDF $pdf, array $meta): void
    {
        $total = $pdf->getNumPages();

        if ($total < 2) {
            return;
        }

        [$x, $largura] = $this->colunaConteudo();
        $y = 297.0 - $this->margem - self::RODAPE_RECUO;

        $numero = trim((string) ($meta['numero'] ?? ''));
        $chave  = Formato::chave($meta['chave'] ?? null, 4);

        $identificacao = 'DANFS-e' . ($numero !== '' ? ' n° ' . $numero : '')
            . ($chave !== Formato::TRACO ? '  ·  chave ' . $chave : '');

        $pdf->SetFont($this->fonte, '', self::RODAPE_TAMANHO);
        $pdf->SetTextColor(...self::RODAPE_COR);

        for ($pagina = 2; $pagina <= $total; $pagina++) {
            $pdf->setPage($pagina);
            $pdf->SetXY($x, $y);
            $pdf->Cell($largura, 0, $identificacao, 0, 0, 'L');
            $pdf->SetXY($x, $y);
            $pdf->Cell($largura, 0, 'Página ' . $pagina . ' de ' . $total, 0, 0, 'R');
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
