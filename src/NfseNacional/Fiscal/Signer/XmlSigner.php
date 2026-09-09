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

        // Algoritmo de assinatura varia por provedor:
        // Sefin Nacional usa SHA-256; Nota Control usa SHA-1 (manual v1.01, seção 7.3.3).
        $usaSha1 = $this->config->getProvedor() === 'notacontrol';
        $digestAlgo = $usaSha1
            ? \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA1
            : \RobRichards\XMLSecLibs\XMLSecurityDSig::SHA256;
        $signAlgo = $usaSha1
            ? \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA1
            : \RobRichards\XMLSecLibs\XMLSecurityKey::RSA_SHA256;

        // Criar DSig sem prefixo para evitar tags com prefixo (ex: ds:Signature)
        // Muitas APIs nacionais exigem que não haja prefixos em elementos XML.
        $dsig = new \RobRichards\XMLSecLibs\XMLSecurityDSig('');
        $dsig->setCanonicalMethod(\RobRichards\XMLSecLibs\XMLSecurityDSig::C14N);

        $id = $elementToSign->getAttribute('Id');
        if (empty($id)) {
            throw new \RuntimeException("Elemento a assinar não possui atributo 'Id'.");
        }

        // Registra o atributo Id como ID real no DOM. Crítico para a resolução
        // do URI="#..." na validação da assinatura no destino (Nota Control).
        $elementToSign->setIdAttribute('Id', true);

        // Referência pelo Id — não sobrescrever o atributo existente (overwrite=false)
        // para evitar que xmlseclibs gere um novo Id (pfx...) que viola o XSD.
        $dsig->addReference(
            $elementToSign,
            $digestAlgo,
            [
                'http://www.w3.org/2000/09/xmldsig#enveloped-signature',
                \RobRichards\XMLSecLibs\XMLSecurityDSig::C14N,
            ],
            ['overwrite' => false],
        );

        // Zero formatação: o template do xmlseclibs (BASE_TEMPLATE) contém
        // quebras de linha/indentação que viram text nodes dentro de <Signature>.
        // Removê-los ANTES de assinar garante que a forma canônica (SignedInfo)
        // seja idêntica à string minificada que será enviada — senão o digest
        // diverge no destino (erro E0714).
        $this->stripWhitespaceNodes($dsig->sigNode);

        $key = new \RobRichards\XMLSecLibs\XMLSecurityKey(
            $signAlgo,
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

    /**
     * Remove recursivamente text nodes que contenham apenas whitespace
     * (quebras de linha, indentações) de uma subárvore DOM.
     *
     * Necessário para "zero formatação": o XML assinado deve ser minificado
     * (linha única), canonicamente idêntico ao que será serializado e enviado.
     */
    private function stripWhitespaceNodes(\DOMNode $node): void
    {
        // Snapshot: childNodes é uma NodeList viva; remover durante a iteração
        // direta corrompe o índice.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                if (trim($child->nodeValue) === '') {
                    $node->removeChild($child);
                }
            } elseif ($child->nodeType === XML_ELEMENT_NODE) {
                $this->stripWhitespaceNodes($child);
            }
        }
    }
}
