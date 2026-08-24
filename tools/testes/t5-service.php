<?php
/** Fase 4 — fallback de origem do XML no DanfseService. */
// WHMCS nao existe aqui; so precisamos que as chamadas nao explodam.
namespace WHMCS\Database { class Capsule {} }

namespace {

function logActivity($m) { $GLOBALS['logs'][] = $m; }
function localAPI($a, $b = []) { return ['result' => 'error']; }
$GLOBALS['logs'] = [];

$M = __DIR__ . '/../../src/NfseNacional/';
foreach ([
    'Domain/Enum/Ambiente','Domain/Enum/NfseStatus','Domain/Enum/EmissaoPolitica',
    'Domain/AmbienteMismatchException','Domain/AmbienteGuard','Domain/Entity/Nfse',
    'Domain/Service/CepIbgeCache','Config/ModuleConfig','Persistence/NfseRepository',
    'Fiscal/Mapper/TomadorMapper',
    'Danfse/NfseXml','Danfse/Formato','Danfse/Codigos','Danfse/Uf','Danfse/ConsultaPublica',
    'Danfse/TokenMapper','Danfse/TemplateRenderer','Danfse/PdfRenderer','Danfse/DanfseService',
] as $c) require $M . $c . '.php';

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Danfse\{DanfseService, PdfRenderer, TemplateRenderer};
use GK2\NfseNacional\Domain\Entity\Nfse;
use GK2\NfseNacional\Fiscal\Mapper\TomadorMapper;
use GK2\NfseNacional\Persistence\NfseRepository;

$pass=0; $fail=0; $falhas=[];
function eq(string $w, $got, $want) {
    global $pass,$fail,$falhas;
    if ($got === $want) { $pass++; return; }
    $fail++;
    $falhas[] = sprintf("  %-46s\n      esperado: %s\n      obtido  : %s", $w, var_export($want,true), var_export($got,true));
}
function ok(string $w, bool $c) { eq($w, $c, true); }

/* ── Stubs ────────────────────────────────────────────────────────── */
class RepoStub extends NfseRepository {
    public ?string $xml = null;
    public array $gravado = [];
    public function __construct() {}
    public function xmlRetorno(int $id): ?string { return $this->xml; }
    public function salvarXmlRetorno(int $id, string $b64): bool { $this->gravado[$id] = $b64; return true; }
}
class ConfigStub extends ModuleConfig {
    public function __construct() {}
    public function get(string $k, string $d = ''): string { return $d; }
    public function getInscricaoMunicipal(): string { return ''; }
}
class TomadorStub extends TomadorMapper {
    public bool $explode = false;
    public int $chamadas = 0;
    public function __construct() {}
    public function mapParaExibicao(int $id): array {
        $this->chamadas++;
        if ($this->explode) throw new \RuntimeException('cliente sumiu');
        return ['razaoSocial'=>'DENTAL PRESS','documento'=>'80898828000148','inscricaoMunicipal'=>'',
                'telefone'=>'4430339816','logradouro'=>'AV X','numero'=>'1','complemento'=>'',
                'bairro'=>'ZONA 05','municipio'=>'Maringá','uf'=>'Paraná','cep'=>'87015001',
                'email'=>'a@b.c'];
    }
}
/** Sem TCPDF local: devolve um marcador com o conteudo do QR, que e o que importa aqui. */
class PdfStub extends PdfRenderer {
    public string $ultimoQr = ''; public string $ultimoHtml = '';
    public function render(string $html, string $qr, array $meta = []): string {
        $this->ultimoQr = $qr; $this->ultimoHtml = $html;
        return "%PDF-FAKE " . strlen($html);
    }
}

$XML = file_get_contents(__DIR__ . '/fixtures/nfse-597.xml');
$B64 = base64_encode(gzencode($XML, 9));

function nota(array $over = []): Nfse {
    return Nfse::fromRow((object) array_merge([
        'id'=>7,'id_client'=>42,'id_invoice'=>'100','status'=>'AUTORIZADA',
        'chave_acesso'=>'41152002214322136000122000000000059726082129342319',
        'xml_url'=>'https://sefin.example/nfse/CHAVE','ambiente'=>'homologacao',
    ], $over));
}
function svc(RepoStub $r, TomadorStub $t, PdfStub $p): DanfseService {
    return new DanfseService($r, new ConfigStub(), $t, new TemplateRenderer(), $p);
}

/* ── Nivel 1: XML no banco ────────────────────────────────────────── */
echo "NIVEL 1 — xml_retorno no banco\n";
$r = new RepoStub(); $r->xml = $B64; $t = new TomadorStub(); $p = new PdfStub();
$chamouFetch = false;
$pdf = svc($r,$t,$p)->gerarPdf(nota(), function() use (&$chamouFetch) { $chamouFetch = true; return ''; });
ok('gerou PDF',                 str_starts_with($pdf, '%PDF-FAKE'));
ok('NAO tocou a rede',          !$chamouFetch);
ok('nao regravou o xml',        $r->gravado === []);
eq('QR com a URL de consulta',  $p->ultimoQr,
   'https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave=41152002214322136000122000000000059726082129342319');
ok('html sem token sobrando',   !str_contains($p->ultimoHtml, '{{'));
ok('tomador veio do WHMCS',     str_contains($p->ultimoHtml, 'DENTAL PRESS'));

echo "\n  xml_retorno gravado SEM gzip (versao antiga)\n";
$r2 = new RepoStub(); $r2->xml = base64_encode($XML); $p2 = new PdfStub();
ok('ainda gera', str_starts_with(svc($r2,new TomadorStub(),$p2)->gerarPdf(nota()), '%PDF-FAKE'));

/* ── Nivel 2: busca na xml_url e auto-cura ────────────────────────── */
echo "\nNIVEL 2 — sem xml_retorno, busca e grava\n";
$r = new RepoStub(); $r->xml = null; $p = new PdfStub();
$urlPedida = null;
$pdf = svc($r,new TomadorStub(),$p)->gerarPdf(nota(), function($u) use (&$urlPedida, $B64) {
    $urlPedida = $u;
    return json_encode(['nfseXmlGZipB64' => $B64]);
});
ok('gerou PDF',            str_starts_with($pdf, '%PDF-FAKE'));
eq('usou a xml_url da nota', $urlPedida, 'https://sefin.example/nfse/CHAVE');
eq('GRAVOU o xml (auto-cura)', $r->gravado[7] ?? null, $B64);
ok('registrou em log',     (bool) array_filter($GLOBALS['logs'], fn($l) => str_contains($l, 'recuperado e gravado')));

echo "\n  resposta ja em XML puro\n";
$r = new RepoStub(); $r->xml = null;
$pdf = svc($r,new TomadorStub(),new PdfStub())->gerarPdf(nota(), fn($u) => $XML);
ok('gerou PDF',        str_starts_with($pdf, '%PDF-FAKE'));
ok('nada a gravar',    $r->gravado === []);

/* ── Nivel 3: falha -> chamador cai no oficial ────────────────────── */
echo "\nNIVEL 3 — falha explicita (chamador cai no oficial)\n";
foreach ([
    'sem xml e sem fetcher'   => [null, null],
    'fetch devolve lixo'      => [fn($u) => 'nao e json nem xml', null],
    'json sem o campo'        => [fn($u) => json_encode(['erro' => 'x']), null],
    'sem xml_url'             => [fn($u) => '', ['xml_url' => null]],
] as $caso => [$fetch, $over]) {
    $r = new RepoStub(); $r->xml = null;
    try {
        svc($r,new TomadorStub(),new PdfStub())->gerarPdf(nota($over ?? []), $fetch);
        eq("lanca: $caso", 'passou', 'RuntimeException');
    } catch (\RuntimeException $e) { $pass++; }
}

/* ── Cliente ausente nao impede o documento ───────────────────────── */
echo "\nCLIENTE indisponivel\n";
$r = new RepoStub(); $r->xml = $B64; $t = new TomadorStub(); $t->explode = true; $p = new PdfStub();
$pdf = svc($r,$t,$p)->gerarPdf(nota());
ok('gera mesmo assim',        str_starts_with($pdf, '%PDF-FAKE'));
ok('tomador vira travessao',  str_contains($p->ultimoHtml, '—'));
ok('logou a falha',           (bool) array_filter($GLOBALS['logs'], fn($l) => str_contains($l, 'falha ao ler o cliente')));

$r = new RepoStub(); $r->xml = $B64; $t = new TomadorStub(); $p = new PdfStub();
svc($r,$t,$p)->gerarPdf(nota(['id_client' => 0]));
eq('client_id 0 nem consulta o WHMCS', $t->chamadas, 0);

/* ── Nome do arquivo ──────────────────────────────────────────────── */
echo "\nnomeArquivo()\n";
$s = svc(new RepoStub(), new TomadorStub(), new PdfStub());
eq('usa a chave', $s->nomeArquivo(nota()), 'danfse-41152002214322136000122000000000059726082129342319.pdf');
eq('sem chave usa o id', $s->nomeArquivo(nota(['chave_acesso' => null])), 'danfse-7.pdf');

if ($falhas) echo "\n── FALHAS ──\n".implode("\n",$falhas)."\n";
printf("\n%d passaram, %d falharam\n", $pass, $fail);
exit($fail===0 ? 0 : 1);

}
