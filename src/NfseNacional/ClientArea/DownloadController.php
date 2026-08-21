<?php

namespace GK2\NfseNacional\ClientArea;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Danfse\DanfseService;
use GK2\NfseNacional\Domain\AmbienteGuard;
use GK2\NfseNacional\Domain\Entity\Nfse;
use GK2\NfseNacional\Persistence\NfseRepository;
use GK2\NfseNacional\Security\TokenSigner;

/**
 * Proxy de download de DANFS-e e XML da NFS-e Nacional.
 *
 * Os endpoints do governo (ADN e SEFIN) exigem mTLS com certificado digital,
 * então o cliente nunca acessa o governo direto: este controller busca o
 * arquivo com o certificado do addon e repassa.
 *
 * CONTROLE DE ACESSO — difere por ambiente, por decisão do operador:
 *
 *   PRODUÇÃO     token HMAC obrigatório + verificação de propriedade quando
 *                há cliente logado. Sem isso o `id` é sequencial e bastaria
 *                iterar ?id=1,2,3 para enumerar as NFS-e de todos os
 *                clientes, com CNPJ/CPF, endereço e valores.
 *
 *   HOMOLOGAÇÃO  aberto, para facilitar teste. Cada acesso sem token válido
 *                é registrado em logActivity — se aparecer log desses em
 *                produção, é porque o ambiente está configurado errado.
 *
 * A regra segue o ambiente do addon, não uma flag separada: não há como
 * esquecer de "voltar a ligar" a verificação ao ir para produção.
 */
class DownloadController
{
    private NfseRepository $repository;
    private ModuleConfig $config;
    private AmbienteGuard $guard;

    public function __construct(?ModuleConfig $config = null)
    {
        $this->config     = $config ?? new ModuleConfig();
        $this->guard      = AmbienteGuard::getInstance($this->config);
        $this->repository = new NfseRepository();
    }

    /**
     * Trata o download, envia o arquivo e encerra.
     *
     * @param string $type 'danfse' ou 'xml'
     */
    public function handle(string $type): void
    {
        if (!in_array($type, ['danfse', 'xml'], true)) {
            $this->abort(400, 'Tipo inválido.');
        }

        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            $this->abort(400, 'ID inválido.');
        }

        $this->autorizarToken($id, $type);

        $nfse = null;
        try {
            $nfse = $this->repository->findById($id);
        } catch (\Throwable $e) {
            $this->abort(404, 'NFS-e não encontrada.');
        }

        if ($nfse === null) {
            $this->abort(404, 'NFS-e não encontrada.');
        }

        $this->autorizarProprietario($nfse);

        $certPath = $this->config->getCertificadoPath();
        $certPass = $this->config->getCertificadoSenha();

        if ($type === 'danfse') {
            $this->serveDanfse($nfse, $certPath, $certPass);
        } else {
            $this->serveXml($nfse, $certPath, $certPass);
        }
    }

    // ─── Controle de acesso ───────────────────────────────────────────────────

    /**
     * Token HMAC. Em homologação, ausência ou invalidez é registrada e o
     * acesso segue; em produção, é 403.
     */
    private function autorizarToken(int $id, string $type): void
    {
        $token = trim($_GET['token'] ?? '');

        if (TokenSigner::verify($id . ':' . $type, $token)) {
            return;
        }

        if (!$this->guard->isHomologacao()) {
            $this->abort(403, 'Acesso negado.');
        }

        logActivity('NFS-e Nacional [DownloadController]: acesso sem token válido permitido'
            . ' — ambiente de HOMOLOGAÇÃO. id=' . $id . ' tipo=' . $type
            . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?')
            . ' | Em produção este acesso seria negado.');
    }

    /**
     * Cliente logado só acessa a própria nota. Sem sessão (link de e-mail),
     * o token já respondeu pela autorização.
     */
    private function autorizarProprietario(Nfse $nfse): void
    {
        $clientId = (int) ($_SESSION['uid'] ?? 0);

        if ($clientId <= 0 || (int) $nfse->clientId === $clientId) {
            return;
        }

        if (!$this->guard->isHomologacao()) {
            $this->abort(403, 'Acesso negado.');
        }

        logActivity('NFS-e Nacional [DownloadController]: cliente ' . $clientId
            . ' acessou NFS-e ' . $nfse->id . ' do cliente ' . $nfse->clientId
            . ' — permitido por ser HOMOLOGAÇÃO. Em produção seria negado.');
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Entrega o DANFS-e no modelo configurado.
     *
     * No modelo GK2 o PDF é gerado localmente. Qualquer falha na geração cai
     * no documento oficial em vez de devolver erro ao cliente: um DANFS-e do
     * governo é melhor que nenhum.
     */
    private function serveDanfse(Nfse $nfse, string $certPath, string $certPass): void
    {
        if ($this->config->getDanfseModelo()->isLocal()) {
            try {
                $service = new DanfseService(null, $this->config);
                $pdf = $service->gerarPdf(
                    $nfse,
                    fn(string $url): string => $this->fetch($url, $certPath, $certPass, 'application/json')
                );

                $this->enviarPdf($pdf, $service->nomeArquivo($nfse));
            } catch (\Throwable $e) {
                logActivity('NFS-e Nacional [DANFS-e GK2]: falha ao gerar para a NFS-e ' . $nfse->id
                    . ' — ' . $e->getMessage() . ' | Caindo no DANFS-e oficial.');
            }
        }

        $url = $nfse->danfseUrl ?? '';
        if (empty($url)) {
            $this->abort(404, 'URL do DANFS-e não disponível.');
        }

        $body = $this->fetch($url, $certPath, $certPass, 'application/pdf');

        $chave = $nfse->chaveAcesso ?? (string) $nfse->id;

        $this->enviarPdf($body, 'danfse-' . $chave . '.pdf');
    }

    private function enviarPdf(string $bytes, string $filename): void
    {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    }

    private function serveXml(Nfse $nfse, string $certPath, string $certPass): void
    {
        $url = $nfse->xmlUrl ?? '';
        if (empty($url)) {
            $this->abort(404, 'URL do XML não disponível.');
        }

        $body = $this->fetch($url, $certPath, $certPass, 'application/json');

        // Resposta da SEFIN é JSON com campo nfseXmlGZipB64
        $decoded = json_decode($body, true);
        if (isset($decoded['nfseXmlGZipB64'])) {
            $xml = gzdecode(base64_decode($decoded['nfseXmlGZipB64']));
            if ($xml === false) {
                $this->abort(502, 'Erro ao descomprimir XML.');
            }
        } else {
            $xml = $body; // fallback: corpo já é XML
        }

        $chave    = $nfse->chaveAcesso ?? (string) $nfse->id;
        $filename = 'nfse-' . $chave . '.xml';

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        echo $xml;
        exit;
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Realiza fetch com mTLS usando o certificado digital do addon.
     * Ambos os endpoints do governo (ADN/SEFIN) exigem autenticação mTLS.
     *
     * O Ingress do governo tem instabilidade conhecida — entrega 502 Bad Gateway
     * intermitentemente em até 50% dos requests, mesmo com mTLS válido. Por isso
     * fazemos até MAX_ATTEMPTS tentativas com backoff curto antes de desistir.
     *
     * @return string Corpo da resposta
     */
    private function fetch(string $url, string $certPath, string $certPass, string $accept): string
    {
        $maxAttempts  = 4;
        $backoffMs    = 400;
        $lastCode     = 0;
        $lastErr      = '';
        $lastBody     = '';

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch   = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => ['Accept: ' . $accept],
            ];

            if (!empty($certPath) && file_exists($certPath)) {
                $ext = strtolower(pathinfo($certPath, PATHINFO_EXTENSION));

                if (in_array($ext, ['pfx', 'p12'], true)) {
                    $opts[CURLOPT_SSLCERTTYPE]   = 'P12';
                    $opts[CURLOPT_SSLCERT]       = $certPath;
                    $opts[CURLOPT_SSLCERTPASSWD] = $certPass;
                } else {
                    $opts[CURLOPT_SSLCERT]       = $certPath;
                    $opts[CURLOPT_SSLCERTPASSWD] = $certPass;
                }
            }

            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            if ($body !== false && $code >= 200 && $code < 300) {
                if ($attempt > 1) {
                    logActivity('NFS-e Nacional [DownloadController]: documento obtido na tentativa '
                        . $attempt . '/' . $maxAttempts . ' (URL=' . $url . ')');
                }
                return $body;
            }

            $lastCode = $code;
            $lastErr  = $err;
            $lastBody = is_string($body) ? $body : '';

            // 4xx (exceto 408/429) não vale retry — é erro permanente
            $isTransient = $body === false
                || $code === 0
                || $code === 408
                || $code === 429
                || $code >= 500;

            if (!$isTransient || $attempt === $maxAttempts) {
                break;
            }

            usleep($backoffMs * 1000);
            $backoffMs *= 2;
        }

        $detail  = $lastErr ?: ('HTTP ' . $lastCode);
        $preview = mb_substr(strip_tags($lastBody), 0, 300);
        logActivity('NFS-e Nacional [DownloadController]: falha ao buscar documento após '
            . $maxAttempts . ' tentativas.'
            . ' URL=' . $url
            . ' | Code=' . $lastCode
            . ' | cURLErr=' . ($lastErr ?: 'nenhum')
            . ' | CertPath=' . ($certPath ?: 'vazio')
            . ' | Body=' . ($preview !== '' ? $preview : '(sem corpo)'));
        $this->abort(502, 'Erro ao obter documento do governo: ' . $detail);
    }

    private function abort(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
        exit($msg);
    }
}
