<?php

namespace GK2\NfseNacional\Danfse;

/**
 * TCPDF com uma margem de topo propria para as paginas de continuacao.
 *
 * A margem de topo do TCPDF vale para o documento inteiro, e as duas paginas
 * pedem coisas diferentes: a primeira abre com o cabecalho do DANFS-e, cujo
 * logo ja traz respiro; as seguintes abrem com uma faixa de secao, que
 * encostava na moldura (0,5mm, contra 4,25mm das laterais).
 *
 * O gancho e o setHeader() do TCPDF: ele chama Header() e, LOGO DEPOIS, poe o
 * cursor em (lMargin, tMargin) — lendo tMargin naquele instante. Mexer em
 * tMargin aqui dentro e o unico jeito de dar margem diferente as paginas de
 * continuacao sem empurrar a primeira para baixo.
 *
 * Header() nao desenha nada; existe so por esse efeito colateral. Por isso
 * setPrintHeader(true) no PdfRenderer nao imprime cabecalho nenhum.
 *
 * O arquivo so pode ser carregado onde o TCPDF existe (o WHMCS o traz em
 * vendor/tecnickcom/tcpdf). PdfRenderer so o instancia depois de conferir
 * class_exists('\TCPDF').
 */
class DanfsePdf extends \TCPDF
{
    private float $margemContinuacao = 0.0;

    public function definirMargemContinuacao(float $mm): void
    {
        $this->margemContinuacao = $mm;
    }

    public function Header()
    {
        if ($this->page > 1 && $this->margemContinuacao > 0.0) {
            $this->tMargin = $this->margemContinuacao;
        }
    }
}
