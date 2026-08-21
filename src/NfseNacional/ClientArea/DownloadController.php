<?php

namespace GK2\NfseNacional\ClientArea;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\Entity\Nfse;
use GK2\NfseNacional\Persistence\NfseRepository;

/**
 * Proxy seguro para download de DANFS-e e XML da NFS-e Nacional.
 *
 * Ambos os endpoints do governo (ADN e SEFIN) exigem mTLS com certificado
 * digital. Este controller autentica o acesso via token HMAC, busca o arquivo
 * usando o certificado configurado no addon e repassa ao cliente.
 *
 * Controle de acesso: NENHUM.
 *
 * Por decisão do operador, o download é público: qualquer pessoa que tenha
 * (ou adivinhe) a URL baixa o documento. Não há verificação de token nem de
 * propriedade — o `id` é sequencial, então basta iterar `?id=1,2,3...` para
 * enumerar as NFS-e de todos os clientes, com CNPJ/CPF, endereço e valores.
 *
 * O parâmetro `token` continua sendo aceito e ignorado, para que os links
 * já enviados por email seguem funcionando.
 */
class DownloadController
{
    private NfseRepository $repository;

    public function __construct()
    {
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

        $nfse = null;
        try {
            $nfse = $this->repository->findById($id);
        } catch (\Throwable $e) {
            $this->abort(404, 'NFS-e não encontrada.');
        }

        if ($nfse === null) {
            $this->abort(404, 'NFS-e não encontrada.');
        }

        $config   = new ModuleConfig();
        $certPath = $config->getCertificadoPath();
        $certPass = $config->getCertificadoSenha();

        if ($type === 'danfse') {
            $this->serveDanfse($nfse, $certPath, $certPass);
        } else {
            $this->serveXml($nfse, $certPath, $certPass);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function serveDanfse(Nfse $nfse, string $certPath, string $certPass): void
    {
        $url = $nfse->danfseUrl ?? '';
        if (empty($url)) {
            $this->abort(404, 'URL do DANFS-e não disponível.');
        }

        $body = $this->fetch($url, $certPath, $certPass, 'application/pdf');

        $chave    = $nfse->chaveAcesso ?? (string) $nfse->id;
        $filename = 'danfse-' . $chave . '.pdf';

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        echo $body;
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
