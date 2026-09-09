<?php

namespace GK2\NfseNacional\Config;

use GK2\NfseNacional\Domain\Enum\Ambiente;
use GK2\NfseNacional\Domain\Enum\EmissaoPolitica;
use WHMCS\Database\Capsule;

/**
 * Gerencia configuracoes do addon NFS-e Nacional.
 *
 * Le e escreve settings em tbladdonmodules WHERE module = 'nfsenacional'.
 * Fornece getters tipados para cada configuracao.
 */
class ModuleConfig
{
    private const MODULE_NAME = 'nfsenacional';

    private ?array $cache = null;

    /**
     * Retorna todas as configuracoes do addon como array associativo.
     */
    public function getAll(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            $rows = Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE_NAME)
                ->get(['setting', 'value']);

            foreach ($rows as $row) {
                $this->cache[$row->setting] = $row->value;
            }
        }

        return $this->cache;
    }

    /**
     * Retorna o valor de uma configuracao especifica.
     */
    public function get(string $key, string $default = ''): string
    {
        $all = $this->getAll();
        return $all[$key] ?? $default;
    }

    /**
     * Atualiza ou insere uma configuracao.
     */
    public function set(string $key, string $value): void
    {
        $exists = Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE_NAME)
            ->where('setting', $key)
            ->exists();

        if ($exists) {
            Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE_NAME)
                ->where('setting', $key)
                ->update(['value' => $value]);
        } else {
            Capsule::table('tbladdonmodules')->insert([
                'module' => self::MODULE_NAME,
                'setting' => $key,
                'value' => $value,
            ]);
        }

        // Invalida cache
        $this->cache = null;
    }

    // ─── Getters tipados ───────────────────────────────────────────

    public function getAmbiente(): Ambiente
    {
        $valor = $this->get('ambiente', 'homologacao');
        return Ambiente::from($valor);
    }

    public function getCnpjPrestador(): string
    {
        return preg_replace('/\D/', '', $this->get('cnpj_prestador'));
    }

    public function getInscricaoMunicipal(): string
    {
        return $this->get('inscricao_municipal');
    }

    public function getRegApTribSN(): string
    {
        $valor = $this->get('reg_ap_trib_sn', '1');
        return (string) ((int) preg_replace('/\D.*/', '', $valor) ?: 1);
    }

    public function getCodigoMunicipioPrestador(): string
    {
        return $this->get('codigo_municipio_prestador', '');
    }

    public function getCertificadoPath(): string
    {
        return $this->get('certificado_path');
    }

    public function getCertificadoSenha(): string
    {
        $value = $this->get('certificado_senha');
        return $this->decryptValue($value);
    }

    /**
     * Migra a senha do certificado de plaintext para AES-256-CBC.
     * Idempotente: não faz nada se já estiver criptografada ou vazia.
     * Deve ser chamada em nfsenacional_output() para migrar instalações existentes.
     */
    public function ensureCertificadoSenhaEncrypted(): void
    {
        $value = $this->get('certificado_senha');
        if (empty($value) || str_starts_with($value, self::ENC_PREFIX)) {
            return;
        }
        $this->set('certificado_senha', $this->encryptValue($value));
    }

    // ─── Criptografia de valores sensíveis (AES-256-CBC) ──────────────

    private const ENC_PREFIX = 'ENC:';

    /**
     * Lê (ou gera) a chave de criptografia AES-256 do filesystem.
     * A chave é armazenada em .nfse_enc_key no diretório raiz do addon
     * (protegido por .htaccess) — separada do banco de dados para que
     * um dump de BD sozinho não exponha as senhas.
     */
    private function getEncryptionKey(): string
    {
        $keyFile = dirname(__DIR__, 3) . '/.nfse_enc_key';

        if (file_exists($keyFile)) {
            $hex = trim((string) file_get_contents($keyFile));
            if (strlen($hex) === 64 && ctype_xdigit($hex)) {
                return hex2bin($hex);
            }
        }

        // Gera nova chave aleatória de 256 bits
        $key = random_bytes(32);
        file_put_contents($keyFile, bin2hex($key));
        chmod($keyFile, 0600);
        return $key;
    }

    /**
     * Criptografa um valor com AES-256-CBC.
     * O IV aleatório é prefixado ao ciphertext antes do base64.
     */
    private function encryptValue(string $plain): string
    {
        $key    = $this->getEncryptionKey();
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return self::ENC_PREFIX . base64_encode($iv . $cipher);
    }

    /**
     * Descriptografa um valor com AES-256-CBC.
     * Se o valor não tiver o prefixo ENC: retorna o plaintext original
     * (compatibilidade retroativa com instalações antigas).
     */
    private function decryptValue(string $value): string
    {
        if (!str_starts_with($value, self::ENC_PREFIX)) {
            return $value; // plaintext ainda não migrado
        }

        $raw    = base64_decode(substr($value, strlen(self::ENC_PREFIX)));
        $iv     = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $key    = $this->getEncryptionKey();
        $plain  = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }

    public function getCnae(): string
    {
        return $this->get('cnae', '');
    }

    public function getCodigoServico(): string
    {
        return $this->get('codigoservico', '');
    }

    public function getCodigoServicoNacional(): string
    {
        return $this->get('codigo_servico_nacional');
    }

    public function getCodigoMunicipal(): string
    {
        return $this->get('codigomunicipal', '');
    }

    /**
     * Retorna a chave secreta única desta instalação para geração de tokens HMAC.
     * Gerada automaticamente com 32 bytes aleatórios na primeira chamada.
     */
    public function getTokenSecret(): string
    {
        $secret = $this->get('_token_secret');
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32)); // 64 chars hex
            $this->set('_token_secret', $secret);
        }
        return $secret;
    }

    public function getExigibilidadeIss(): int
    {
        $valor = $this->get('exigibilidade_iss', '1');
        return (int) preg_replace('/\D.*/', '', $valor) ?: 1;
    }

    public function getRegEspTrib(): string
    {
        $valor = $this->get('reg_esp_trib', '0');
        return (string) ((int) preg_replace('/\D.*/', '', $valor));
    }

    public function getAliquotaIss(): float
    {
        return (float) $this->get('iss', '0');
    }

    public function getRetencaoIss(): float
    {
        return (float) $this->get('reteriss', '0');
    }

    public function isOptanteSimplesNacional(): bool
    {
        return $this->get('optante_simples') === 'on';
    }

    public function getSerieDps(): string
    {
        return $this->get('serie_dps', '1');
    }

    public function getEmissaoPadrao(): EmissaoPolitica
    {
        $valor = $this->get('emissao_padrao', '1');
        $numero = (int) preg_replace('/\D.*/', '', $valor);
        return EmissaoPolitica::tryFrom($numero) ?? EmissaoPolitica::NAO_EMITIR;
    }

    public function isEmailHabilitado(): bool
    {
        return $this->get('email') === 'on';
    }

    public function isCancelarComFatura(): bool
    {
        return $this->get('cancelar') === 'on';
    }

    public function isExcluirLateFee(): bool
    {
        return $this->get('excluir_latefee') === 'on';
    }

    public function isDescontarDesconto(): bool
    {
        return $this->get('faturas_desconto') === 'on';
    }

    public function isDescontarCredito(): bool
    {
        return $this->get('faturas_credito') === 'on';
    }

    public function getVerAplic(): string
    {
        $valor = trim($this->get('ver_aplic', 'WHMCS-NfseNac-1.0'));
        if (empty($valor)) {
            return 'WHMCS-NfseNac-1.0';
        }
        // XSD TSVerAplic: máximo 20 caracteres
        return mb_substr($valor, 0, 20);
    }

    public function isDebug(): bool
    {
        return $this->get('debug') === 'on';
    }

    public function getDocumentoCliente(): string
    {
        return $this->get('documento_cliente', 'taxid');
    }

    public function getProvedor(): string
    {
        $valor = $this->get('provedor', 'sefin');

        // Dropdown usa formato 'chave-Label amigavel' (ex: 'sefin-Sefin Nacional (padrão)').
        // Extrai apenas a chave antes do primeiro hífen.
        $chave = explode('-', $valor, 2)[0];

        return $chave !== '' ? $chave : 'sefin';
    }

    /**
     * Retorna a cidade usada na rota do endpoint de produção da Nota Control.
     *
     * Normalizada para slug de URL (minúsculas, sem acentos, só alfanumérico).
     */
    public function getCidadeNotaControl(): string
    {
        $valor = strtolower(trim($this->get('cidade_notacontrol', 'ribeiraopreto')));

        $mapa = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ];
        $valor = strtr($valor, $mapa);
        $valor = preg_replace('/[^a-z0-9]+/', '', $valor) ?? '';

        return $valor;
    }

    // ─── Setup (ativacao) ──────────────────────────────────────────

    /**
     * Insere configuracoes padrao na ativacao do modulo.
     */
    public function insertDefaults(): void
    {
        $defaults = [
            '_token_secret' => bin2hex(random_bytes(32)),
            'provedor' => 'sefin',
            'cidade_notacontrol' => 'ribeiraopreto',
            'ambiente' => 'homologacao',
            'serie_dps' => '1',
            'documento_cliente' => 'taxid',
            'optante_simples' => '1',
            'emissao_padrao' => '1-Nao Emitir',
            'email' => '0',
            'cancelar' => '0',
            'debug' => '0',
        ];

        foreach ($defaults as $setting => $value) {
            $exists = Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE_NAME)
                ->where('setting', $setting)
                ->exists();

            if (!$exists) {
                Capsule::table('tbladdonmodules')->insert([
                    'module' => self::MODULE_NAME,
                    'setting' => $setting,
                    'value' => $value,
                ]);
            }
        }
    }

    /**
     * Garante que o template de email "NFS-e Nacional" exista.
     */
    public function ensureEmailTemplate(): void
    {
        // Remove duplicatas antes de verificar (pode haver resquícios de versões anteriores)
        $ids = Capsule::table('tblemailtemplates')
            ->where('name', 'NFS-e Nacional')
            ->pluck('id')
            ->toArray();

        if (count($ids) > 1) {
            // Mantém apenas o de menor ID (o mais antigo / o que será atualizado)
            $keepId = min($ids);
            Capsule::table('tblemailtemplates')
                ->where('name', 'NFS-e Nacional')
                ->where('id', '!=', $keepId)
                ->delete();
        }

        $tpl = Capsule::table('tblemailtemplates')
            ->where('name', 'NFS-e Nacional')
            ->first();

        $message = '<p>Prezado {$client_name},</p>'
            . "\r\n"
            . '<p>Estamos enviando a nota fiscal eletronica de numero <strong>{$idNFS}</strong>, emitida em <strong>{$autorizacao}</strong>.</p>'
            . "\r\n"
            . '<p><a href="{$danfse_url}" target="_blank" rel="noopener"><strong>Ver DANFS-e</strong></a></p>'
            . "\r\n"
            . '<p><a href="{$xml_url}" target="_blank" rel="noopener"><strong>Ver XML</strong></a></p>'
            . "\r\n"
            . '<p>{$signature}</p>';

        if (empty($tpl)) {
            Capsule::table('tblemailtemplates')->insert([
                'type'          => 'general',
                'name'          => 'NFS-e Nacional',
                'subject'       => 'Nota Fiscal Eletronica - Fatura #{$idFatura}',
                'message'       => $message,
                'attachments'   => '',
                'fromname'      => '',
                'fromemail'     => '',
                'disabled'      => 0,
                'custom'        => 1,
                'language'      => '',
                'copyto'        => '',
                'blind_copy_to' => '',
                'plaintext'     => 0,
            ]);
        } elseif ($tpl->type !== 'general') {
            // Corrige templates criados com type incorreto
            Capsule::table('tblemailtemplates')
                ->where('id', $tpl->id)
                ->update(['type' => 'general', 'custom' => 1, 'language' => '']);
        }
    }

    /**
     * Garante que os custom fields do cliente existam.
     */
    public function ensureCustomFields(): void
    {
        // Campo: Emitir NFS-e (Nacional)
        $emitirId = Capsule::table('tblcustomfields')
            ->where('fieldname', 'Emitir NFS-e (Nacional)')
            ->value('id');

        if (empty($emitirId)) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'client',
                'fieldname' => 'Emitir NFS-e (Nacional)',
                'fieldtype' => 'dropdown',
                'adminonly' => 'on',
                'fieldoptions' => '1- Nao Emitir NFS-e,2- Fatura Gerada,3- Fatura Paga',
                'description' => '[Criado Automaticamente] NFS-e via Addon Nacional, nao altere as informacoes desse campo.',
            ]);
        }

        // Campo: Reter ISS (Nacional)
        $reterIssId = Capsule::table('tblcustomfields')
            ->where('fieldname', 'Reter ISS (Nacional)')
            ->value('id');

        if (empty($reterIssId)) {
            Capsule::table('tblcustomfields')->insert([
                'type' => 'client',
                'fieldname' => 'Reter ISS (Nacional)',
                'fieldtype' => 'tickbox',
                'adminonly' => 'on',
                'description' => '[Criado Automaticamente] NFS-e via Addon Nacional, nao altere as informacoes desse campo.',
            ]);
        }
    }
}
