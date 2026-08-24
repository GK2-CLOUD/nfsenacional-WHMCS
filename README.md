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

1. Baixe o arquivo `nfsenacional-vX.Y.Z.zip` da [página de releases](https://github.com/<org>/<repo>/releases).
2. Extraia o conteúdo em `modules/addons/nfsenacional/` na sua instalação WHMCS.
3. O zip já inclui a pasta `vendor/` com todas as dependências — não é necessário rodar `composer install` no servidor de produção.
4. Em **Configuration Value → System → Activate Modules → Other Addon Modules**, ative o addon "NFS-e Nacional".
5. Preencha as configurações do addon (certificado A1, série DPS, ambiente, política de emissão) — detalhado no guia acima.

> ⚠️ **Não use o código direto do repositório em produção.** Clone o repo apenas para
> desenvolvimento. Para instalação, use sempre o zip de release, que já vem com
> `vendor/` incluso e a pasta renomeada para `nfsenacional`.

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

- [`guzzlehttp/guzzle`](https://github.com/guzzle/guzzle) — cliente HTTP (com autenticação mTLS via certificado A1)
- [`robrichards/xmlseclibs`](https://github.com/robrichards/xmlseclibs) — assinatura digital XML

## Desenvolvimento

### Setup inicial

```bash
# Clone o repositório
git clone <repo-url>
cd nota-fiscal

# Instale as dependências do módulo
cd nfsenacional-WHMCS
composer install
```

> **Importante:** A pasta `vendor/` não é commitada no repositório. Após clonar ou trocar
> de branch, execute sempre `composer install` para regenerá-la a partir do `composer.lock`.
> O `composer.lock` garante que todos os ambientes usem exatamente as mesmas versões das
> dependências.

### Estrutura do projeto

```
nota-fiscal/                        ← Raiz do repositório
├── .github/workflows/release.yml   ← Workflow de release automático
├── DOCUMENTACAO-REFERENCIA-NFSE.md ← Catálogo dos arquivos de suporte (XSD, APIs, anexos)
├── esquemas-nfse-rtc-v1-01-*/      ← Schemas XSD oficiais da NFS-e
├── MANUAL API/                     ← Especificações OpenAPI/Swagger das APIs
├── anexo_*.xlsx                    ← Planilhas oficiais (IBGE, NBS, tributações, eventos)
├── nfsenacional-WHMCS/             ← Módulo WHMCS
│   ├── composer.json               ← Dependências PHP (guzzle, xmlseclibs)
│   ├── composer.lock               ← Versões exatas lockadas (commitado)
│   ├── vendor/                     ← ⚠️ Não commitado — gerado via composer install
│   ├── CHANGELOG.md                ← Histórico de versões (Keep a Changelog)
│   └── src/NfseNacional/           ← Código-fonte do módulo
└── guia_*.pdf                      ← Guias e notas técnicas
```

### Fluxo de release

O release é **100% automatizado** via GitHub Actions (`.github/workflows/release.yml`).
Para publicar uma nova versão:

1. **Atualize a versão** no docblock do `nfsenacional-WHMCS/nfsenacional.php`:
   ```php
   /**
    * @version    1.0.1   ← altere aqui
    */
   ```

2. **Registre as mudanças** no `nfsenacional-WHMCS/CHANGELOG.md`:
   ```markdown
   ## [1.0.1] - 2026-08-10

   ### Corrigido
   - Timeout em chamadas de consulta corrigido
   - Encoding UTF-8 no e-mail de notificação

   ### Adicionado
   - Suporte a certificado A3 com cadeia completa
   ```

3. **Abra um PR** com as alterações para a branch `main`.

4. **Ao mergear o PR**, o workflow automaticamente:
   - Extrai a versão do docblock
   - Verifica se a tag já existe (evita duplicatas)
   - Executa `composer install --no-dev`
   - Empacota o módulo em `nfsenacional-vX.Y.Z.zip` (pasta renomeada para `nfsenacional`)
   - Extrai a seção correspondente do `CHANGELOG.md`
   - Cria a tag `vX.Y.Z` no Git
   - Publica o release no GitHub com o zip anexado

### Convenções

| Convenção | Regra |
| --- | --- |
| **Versionamento** | [SemVer](https://semver.org/) — `MAJOR.MINOR.PATCH` |
| **Changelog** | [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/) — seções `Adicionado`, `Alterado`, `Corrigido`, `Removido` |
| **PHP mínimo** | 8.3+ (produção); `composer.json` declara `>=8.1` por compatibilidade com WHMCS |
| **Vendor** | Não commitado — `.gitignore` ignora `vendor/`; `composer.lock` é commitado |
| **Release** | Automático no merge de PR para `main`; tag = versão do módulo |
| **Nome do pacote** | `nfsenacional/` (sem sufixo `-WHMCS`) dentro do zip de release |
