<?php
/**
 * Gera um DANFS-e GK2 com N itens na discriminacao, para exercitar a quebra
 * de pagina. RODA NO SERVIDOR WHMCS — precisa do TCPDF e do banco.
 *
 *   php tools/danfse-multipagina.php <n_itens> [id_nfse] [saida.pdf]
 *
 * Nao emite nota nem grava nada: pega o XML de uma NFS-e ja existente e troca
 * o <xDescServ> pela discriminacao montada com o ServicoMapper de verdade.
 *
 * As descricoes sao curtas de proposito — um item por linha (~3,2mm). Assim da
 * para mover a quebra em passos finos sem esbarrar no corte de 2000 caracteres
 * do xDescServ, que satura a altura se os itens forem longos.
 */
$raiz = dirname(__DIR__, 4);              // .../modules/addons/nfsenacional -> raiz do WHMCS
chdir($raiz);
require 'init.php';

use GK2\NfseNacional\Danfse\{NfseXml, TokenMapper, TemplateRenderer, PdfRenderer, ConsultaPublica};
use GK2\NfseNacional\Persistence\NfseRepository;
use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Fiscal\Mapper\{TomadorMapper, ServicoMapper};

$n      = (int) ($argv[1] ?? 20);
$idNfse = (int) ($argv[2] ?? 43);
$saida  = $argv[3] ?? sys_get_temp_dir() . '/danfse-multipagina.pdf';

$repo = new NfseRepository();
$nfse = $repo->findById($idNfse);

if (!$nfse) {
    fwrite(STDERR, "NFS-e $idNfse nao encontrada\n");
    exit(1);
}

$itens = [];
for ($i = 1; $i <= $n; $i++) {
    $itens[] = ['type' => 'Hosting', 'amount' => '1.00', 'description' => sprintf('Servico %02d', $i)];
}
$disc = (new ServicoMapper())->map(['invoiceid' => 1, 'items' => ['item' => $itens]])['discriminacao'];

$bruto = preg_replace(
    '#<xDescServ>.*?</xDescServ>#s',
    '<xDescServ>' . htmlspecialchars($disc, ENT_XML1) . '</xDescServ>',
    gzdecode(base64_decode($repo->xmlRetorno($idNfse))),
    1
);

$xml    = NfseXml::fromXml($bruto);
$mapper = new TokenMapper((new ModuleConfig())->getInscricaoMunicipal());
$tokens = $mapper->map($xml, (new TomadorMapper())->mapParaExibicao((int) $nfse->clientId));
$html   = (new TemplateRenderer())->render($tokens, ['se_homologacao' => $mapper->isHomologacao($xml)]);
$pdf    = new PdfRenderer();

file_put_contents($saida, $pdf->render(
    $html,
    ConsultaPublica::url($xml->chaveAcesso()),
    ['numero' => $tokens['numero_nfse'], 'chave' => $xml->chaveAcesso()]
));

printf("%s | %d itens | %d chars de xDescServ | %d pagina(s)\n",
    $saida, $n, mb_strlen($disc), $pdf->paginas($html, ''));
