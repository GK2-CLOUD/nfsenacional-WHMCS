<?php

namespace GK2\NfseNacional\Fiscal\Signer;

use GK2\NfseNacional\Config\ModuleConfig;

/**
 * Assina um elemento DOM usando xmlseclibs (XMLSecurityDSig).
 */
class XmlSigner
{
    private ModuleConfig $config;

    public function __construct(?ModuleConfig $config = null)
    {
        $this->config = $config ?? new ModuleConfig();
    }

    /**
     * Assina o elemento informado (deve conter atributo Id) e anexa a
     * assinatura como filho do elemento.
     *
     * @param \DOMDocument $dom
     * @param \DOMElement $elementToSign
     * @return \DOMDocument
     * @throws \RuntimeException se a biblioteca ou certificado não estiverem acessíveis
     */
    public function signDom(\DOMDocument $dom, \DOMElement $elementToSign): \DOMDocument
    {
        // Tenta carregar autoload do Composer (módulo -> raiz)
        if (!class_exists('RobRichards\\XMLSecLibs\\XMLSecurityDSig')) {
            $autoloads = [
                __DIR__ . '/../../../vendor/autoload.php',       // modules/addons/nfsenacional/vendor/autoload.php
                __DIR__ . '/../../../../vendor/autoload.php',    // modules/addons/vendor/autoload.php
                __DIR__ . '/../../../../../vendor/autoload.php', // workspace root vendor/autoload.php
            ];
            foreach ($autoloads as $a) {
                if (file_exists($a)) {
                    require_once $a;
                    break;
                }
            }
        }

        if (!class_exists('RobRichards\\XMLSecLibs\\XMLSecurityDSig')) {
            throw new \RuntimeException("Biblioteca xmlseclibs não encontrada. Instale 'robrichards/xmlseclibs' via Composer dentro do módulo nfsenacional.");
        }

        $certPath = $this->config->getCertificadoPath();
        $certPassword = $this->config->getCertificadoSenha();

        if (empty($certPath)) {
            throw new \RuntimeException('Caminho do certificado não configurado.');
        }

        // Criar DSig sem prefixo para evitar tags com prefixo (ex: ds:Signature)
        // Muitas APIs nacionais exigem que não haja prefixos em elementos XML.
        $dsig = new \RobRichards\XMLSecLibs\XMLSecurityDSig('');
        $dsig->setCanonicalMethod(\RobRichards\XMLSecLibs\XMLSecurityDSig::C14N);

        $id = $elementToSign->getAttribute('Id');
        if (empty($id)) {
            throw new \RuntimeException("Elemento a assinar não possui atributo 'Id'.");
        }

        // Referência pelo Id — não sobrescrever o atributo existente (overwrite=false)
        // para evitar que xmlseclibs gere um novo Id (pfx...) que viola o XSD.
        $dsig->addReference(
            $elementToSign,
            \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256,
            [
                'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
                \RobRichards\XMLSecLibs\XMLSecurityDSig::C14N,
            ],
            ['overwrite' => false],
        );

        $key = new \RobRichards\XMLSecLibs\XMLSecurityKey(
            \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256,
            ['type' => 'private'],
        );
        $key->passphrase = $certPassword;

        $publicCertPem = '';
        $ext = strtolower(pathinfo($certPath, PATHINFO_EXTENSION));
        if (in_array($ext, ['p12', 'pfx'])) {
            $pkcs12 = @file_get_contents($certPath);
            if ($pkcs12 === false) {
                throw new \RuntimeException('Não foi possível ler o arquivo PKCS12 do certificado.');
            }
            $certs = [];
            if (!openssl_pkcs12_read($pkcs12, $certs, $certPassword)) {
                throw new \RuntimeException('Falha ao ler PKCS12 (senha incorreta ou arquivo inválido)');
            }
            $key->loadKey($certs['pkey'], false);
            $publicCertPem = $certs['cert'] ?? '';
        } else {
            if (!file_exists($certPath)) {
                throw new \RuntimeException('Arquivo de certificado não encontrado: ' . $certPath);
            }
            $key->loadKey($certPath, true);
            $publicCertPem = @file_get_contents($certPath) ?: '';
        }

        $dsig->sign($key);

        if (!empty($publicCertPem)) {
            $dsig->add509Cert($publicCertPem, true);
        }

        // Anexa assinatura como filho do PAI do elemento assinado (ex: <DPS>),
        // para evitar inserir <Signature> dentro de <infDPS> (não permitido pelo XSD).
        $parentNode = $elementToSign->parentNode ?? $elementToSign;
        $dsig->appendSignature($parentNode);

        return $dom;
    }
}
