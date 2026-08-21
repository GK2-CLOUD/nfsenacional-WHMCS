<?php
/**
 * Mede a altura do DANFS-e GK2 em mm, para varias margens.
 *
 * Existe porque o layout tem que caber em UMA pagina e a folga e pequena
 * (~7mm na margem de 10mm). Qualquer mexida no template pode empurrar o
 * documento para a segunda pagina sem ninguem notar — este script acusa.
 *
 * Roda no servidor WHMCS, onde vive o TCPDF:
 *   php tools/medir-altura.php /caminho/para/nfse.xml
 *
 * Descoberta que motivou o script: reduzir a fonte de 7,5pt para 6,0pt
 * muda a altura em 0,7mm. A altura NAO vem do texto, vem das caixas de
 * bloco do TCPDF — dois <div> empilhados numa celula custam ~3x o que
 * custa um <br>. Ver o commit da Fase 3.
 */

$raiz = dirname(__DIR__);
foreach ([$raiz.'/../../../vendor/autoload.php', $raiz.'/vendor/autoload.php'] as $a) {
    if (is_readable($a)) { require $a; break; }
}
$B = $raiz . '/src/NfseNacional/Danfse/';
foreach (['NfseXml','Formato','Codigos','Uf','TokenMapper','TemplateRenderer','PdfRenderer'] as $c) require $B.$c.'.php';

if (!class_exists('\TCPDF')) { fwrite(STDERR, "TCPDF nao encontrado — rode no servidor WHMCS.\n"); exit(1); }
$xmlPath = $argv[1] ?? null;
if (!$xmlPath || !is_readable($xmlPath)) { fwrite(STDERR, "uso: php tools/medir-altura.php <nfse.xml>\n"); exit(1); }
use GK2\NfseNacional\Danfse\{NfseXml, TokenMapper, TemplateRenderer};

$xml = NfseXml::fromXml(file_get_contents($xmlPath));
$tomador = ['razaoSocial'=>'DENTAL PRESS ENSINO E PESQUISA LTDA','documento'=>'80898828000148',
  'inscricaoMunicipal'=>'','telefone'=>'4430339816','logradouro'=>'AV DR LUIZ TEIXEIRA MENDES',
  'numero'=>'S/N','complemento'=>'','bairro'=>'ZONA 05','municipio'=>'Maringá','uf'=>'Paraná',
  'cep'=>'87015001','email'=>'webmaster@dentalpress.com.br'];
$tokens = (new TokenMapper())->map($xml, $tomador);
$html = (new TemplateRenderer())->render($tokens, ['se_homologacao'=>false]);

function altura(string $html, float $m, string $fonte, float $tam): float {
    $pdf = new \TCPDF('P','mm','A4',true,'UTF-8');
    $pdf->SetMargins($m,$m,$m);
    $pdf->SetAutoPageBreak(false);          // sem quebra: Y final = altura real
    $pdf->setPrintHeader(false); $pdf->setPrintFooter(false);
    $pdf->SetFont($fonte,'',$tam);
    $pdf->AddPage();
    $pdf->writeHTML($html,true,false,true,false,'');
    return $pdf->GetY();
}

echo "Altura util da pagina (A4 = 297mm):\n";
foreach ([10.0, 8.0, 5.0, 3.5] as $m) {
    $disp = 297 - 2*$m;
    $y = altura($html, $m, 'dejavusans', 7.5);
    printf("  margem %4.1fmm -> conteudo %6.1fmm | disponivel %6.1fmm | %s por %.1fmm\n",
        $m, $y - $m, $disp, ($y-$m) <= $disp ? 'CABE   ' : 'ESTOURA', abs(($y-$m)-$disp));
}
echo "\nMesma margem 10mm, variando o corpo:\n";
foreach ([7.5, 7.0, 6.5, 6.0] as $t) {
    $y = altura($html, 10.0, 'dejavusans', $t);
    printf("  fonte %.1fpt -> conteudo %6.1fmm | %s\n", $t, $y-10, ($y-10) <= 277 ? 'CABE' : 'ESTOURA');
}
