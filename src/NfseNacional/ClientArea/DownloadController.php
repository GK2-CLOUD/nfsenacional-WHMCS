<?php

namespace GK2\NfseNacional\ClientArea;

use GK2\NfseNacional\Config\ModuleConfig;
use GK2\NfseNacional\Domain\Entity\Nfse;
use GK2\NfseNacional\Persistence\NfseRepository;
use GK2\NfseNacional\Security\TokenSigner;

/**
 * Proxy seguro para download de DANFS-e e XML da NFS-e Nacional.
 *
 * Ambos os endpoints do governo (ADN e SEFIN) exigem mTLS com certificado
 * digital. Este controller autentica o acesso via token HMAC, busca o arquivo
 * usando o certificado configurado no addon e repassa ao cliente.
 *
 * Controle de acesso:
 * - Token HMAC obrigatório (impede adivinhação de IDs)
 * - Se cliente logado: verifica que a nota pertence a ele
 * - Se não logado (link de email): token HMAC é suficiente
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

        $id    = (int) ($_GET['id'] ?? 0);
        $token = trim($_GET['token'] ?? '');

        if ($id <= 0 || !TokenSigner::verify($id . ':' . $type, $token)) {
            $this->abort(403, 'Acesso negado.');
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

        // Se cliente logado, garante que a nota é dele
        $clientId = (int) ($_SESSION['uid'] ?? 0);
        if ($clientId > 0 && (int) $nfse->clientId !== $clientId) {
            $this->abort(403, 'Acesso negado.');
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

        // Nota Control devolve link externo real (nfse.issnetonline.com.br) →
        // redirect 302, pois o proxy quebraria o HTML relativo do documento.
        if ($this->isNotaControlUrl($url)) {
            http_response_code(302);
            header('Location: ' . $url);
            exit;
        }

        $body = $this->fetch($url, $certPath, $certPass, 'application/pdf,text/html,*/*');

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
        $xmlRetorno = $nfse->xmlRetorno ?? '';

        // Nota Control: não há URL de XML — serve o XML autorizado armazenado
        // (gzip+base64 gravado em xml_retorno durante a emissão).
        if (empty($url) || $this->isNotaControlUrl($url)) {
            if (!empty($xmlRetorno)) {
                $xml = @gzdecode(base64_decode($xmlRetorno));
                if ($xml === false) {
                    $this->abort(502, 'Erro ao descomprimir XML.');
                }
                $this->outputXml($xml, $nfse);
            }
            if (empty($url)) {
                $this->abort(404, 'URL do XML não disponível.');
            }
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

        $this->outputXml($xml, $nfse);
    }

    /**
     * Envia o XML autorizado ao cliente e encerra.
     */
    private function outputXml(string $xml, Nfse $nfse): void
    {
        $chave    = $nfse->chaveAcesso ?? (string) $nfse->id;
        $filename = 'nfse-' . $chave . '.xml';

        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: private, no-store');
        echo $xml;
        exit;
    }

    /**
     * Detecta URL externa da Nota Control pelo host.
     */
    private function isNotaControlUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        return str_contains($host, 'issnetonline.com.br');
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Realiza fetch com mTLS usando o certificado digital do addon.
     * Ambos os endpoints do governo (ADN/SEFIN) exigem autenticação mTLS.
     *
     * @return string Corpo da resposta
     */
    private function fetch(string $url, string $certPath, string $certPass, string $accept): string
    {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['Accept: ' . $accept],
        ];

        if (!empty($certPath) && file_exists($certPath)) {
            $ext = strtolower(pathinfo($certPath, PATHINFO_EXTENSION));

            if (in_array($ext, ['pfx', 'p12'], true)) {
                // cURL aceita P12 diretamente via CURLOPT_SSLCERTTYPE
                $opts[CURLOPT_SSLCERTTYPE] = 'P12';
                $opts[CURLOPT_SSLCERT]     = $certPath;
                $opts[CURLOPT_SSLCERTPASSWD] = $certPass;
            } else {
                // PEM separado (cert + key no mesmo arquivo ou dois arquivos)
                $opts[CURLOPT_SSLCERT]       = $certPath;
                $opts[CURLOPT_SSLCERTPASSWD] = $certPass;
            }
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 300) {
            $detail  = $err ?: ('HTTP ' . $code);
            $preview = is_string($body) ? mb_substr(strip_tags($body), 0, 300) : '(sem corpo)';
            logActivity('NFS-e Nacional [DownloadController]: falha ao buscar documento.'
                . ' URL=' . $url
                . ' | Code=' . $code
                . ' | cURLErr=' . ($err ?: 'nenhum')
                . ' | CertPath=' . ($certPath ?: 'vazio')
                . ' | Body=' . $preview);
            $this->abort(502, 'Erro ao obter documento do governo: ' . $detail);
        }

        return $body;
    }

    private function abort(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
        exit($msg);
    }
}
