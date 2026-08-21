# NFS-e Nacional — Addon WHMCS

Addon WHMCS para emissão, consulta, cancelamento e download de **NFS-e** no padrão nacional da Receita Federal, integrado à API SEFIN/ADN via mTLS com certificado digital ICP-Brasil A1.

---

## Funcionalidades

- Emissão automática de NFS-e ao criar ou ao pagar fatura (configurável por cliente)
- Emissão manual pelo painel admin com confirmação
- Cancelamento via evento `101101` (API SEFIN)
- Download de DANFS-e (PDF) e XML via proxy mTLS — sem expor o certificado ao cliente
- Envio de e-mail com links de DANFS-e e XML após autorização
- Área do cliente com listagem, busca, ordenação e reenvio de e-mail
- Ícones de status de NFS-e na listagem de faturas (admin e área do cliente)
- Criptografia AES-256-CBC da senha do certificado em banco de dados
- Suporte ao bloco **IBS/CBS** (Reforma Tributária — XSD v1.01, obrigatório a partir de 2026)
- Fallback de resolução de CEP → IBGE via ViaCEP + cache local editável (`data/cep_ibge.json`)

---

## Requisitos

| Item | Versão mínima |
|------|---------------|
| WHMCS | 8.0+ |
| PHP | 8.1+ |
| Extensões PHP | `openssl`, `curl`, `mbstring`, `zlib`, `json` |
| Certificado digital | ICP-Brasil A1 (arquivo `.pfx` / `.p12`) |

---

## Documentação

| Guia | Descrição |
|------|-----------|
| [Instalação e Configuração](https://oraculo.gk2.cloud/books/whmcs/page/configuracao-nfs-e-nacional-v10-producao-e-homologacao) | Upload, Composer, permissões, certificado, ativação no WHMCS e configuração de todos os campos |
| [Guia de Utilização](https://oraculo.gk2.cloud/books/whmcs/page/guia-nfs-e-nacional-utilizacao-e-operacao) | Emissão manual e automática, cancelamento, download, erros comuns, FAQ |

---

## Instalação rápida

```bash
# 1. Copie a pasta para o diretório de addons do WHMCS
cp -r nfsenacional/ /caminho/para/whmcs/modules/addons/

# 2. Instale as dependências
cd /caminho/para/whmcs/modules/addons/nfsenacional
composer install --no-dev --optimize-autoloader

# 3. Ajuste permissões
chown -R www-data:www-data .
chmod -R 755 .
```

Em seguida, ative o addon em **Admin → Configurações → Apps & Integrações → NFS-e Nacional** e siga o [guia de configuração](https://oraculo.gk2.cloud/books/whmcs/page/configuracao-nfs-e-nacional-v10-producao-e-homologacao).

---

## Ambientes

| Ambiente | Endpoint SEFIN | Validade fiscal |
|----------|----------------|-----------------|
| `homologacao` | `sefin.producaorestrita.nfse.gov.br` | Não — apenas testes |
| `producao` | `sefin.nfse.gov.br` | Sim |

---

## Estrutura do projeto

```
modules/addons/nfsenacional/
├── nfsenacional.php        # Entry point do addon
├── hooks.php               # Registro de hooks WHMCS
├── cron.php                # Cron de emissão automática
├── composer.json
├── data/
│   └── cep_ibge.json       # Cache manual CEP → IBGE (fallback do ViaCEP)
├── src/NfseNacional/
│   ├── Admin/              # Painel admin, ações e campos de configuração
│   ├── ClientArea/         # Área do cliente e proxy de download
│   ├── Config/             # Leitura de configurações (inclui criptografia AES)
│   ├── Domain/             # Entidades, enums e serviços de domínio
│   ├── Fiscal/             # Mappers, builders de DPS/Evento, assinador XML
│   ├── Hook/               # Hooks WHMCS (faturas, listagens, área do cliente)
│   ├── Persistence/        # Repositório NFS-e e sequência DPS
│   ├── Security/           # TokenSigner HMAC-SHA256
│   └── Transport/          # HTTP client (cURL + mTLS), endpoints, auth
└── templates/
    ├── admin/
    └── client/
```

---

## Segurança

- **Senha do certificado** armazenada criptografada (AES-256-CBC) no banco — a chave fica em `.nfse_enc_key` no sistema de arquivos, separada do banco
- **Tokens de ação** (emitir, cancelar, excluir) assinados com HMAC-SHA256 por instalação — resistentes a CSRF e timing attacks
- **Proxy mTLS** para DANFS-e e XML — o certificado nunca é exposto ao navegador do cliente
- **Proteção do `.nfse_enc_key`**: bloquear via nginx ou Apache (veja o guia de instalação)

---

## Erros comuns

| Código | Causa | Solução |
|--------|-------|---------|
| E0120 | `<IM>` enviado, município sem IM no CNC | Deixe *Inscrição Municipal* vazio |
| E0166 | `regApTribSN` ausente (Simples Nacional) | Preencha *Apuração Simples Nacional* |
| E0240 | CEP do tomador não pertence ao município | Corrija o CEP ou edite `data/cep_ibge.json` |
| E0314 | Código tributação municipal inválido | Deixe *cTribMun* vazio |
| E0316 | Código NBS inválido | Deixe *Código de Serviço NBS* vazio |
| HTTP 496 | mTLS ausente — certificado não configurado | Verifique caminho e senha do `.pfx` |

---

## Suporte

[gk2.com.br](https://gk2.com.br) · [WhatsApp](https://gk2.cloud/whatsapp)
