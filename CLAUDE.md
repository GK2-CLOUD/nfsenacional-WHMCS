# CLAUDE.md — NFS-e Nacional (WHMCS Addon)

## Visão Geral

Addon WHMCS para emissão, consulta e cancelamento de **NFS-e Nacional** via API SEFIN/ADN do governo federal (`sefin.nfse.gov.br`). Namespace PHP: `GK2\NfseNacional`. Versão do XSD: **1.01**.

---

## Estrutura de Diretórios

```
modules/addons/nfsenacional/
├── nfsenacional.php          # Entry point do addon (config, output, etc.)
├── hooks.php                 # Registro de hooks WHMCS
├── cron.php                  # Cron de emissão automática
├── composer.json             # Dependências: robrichards/xmlseclibs (assinatura XML)
├── docs/
│   ├── API NFS-e - Sefin Nacional (v1).json      # Swagger oficial SEFIN ← CONSULTE AQUI
│   ├── API NFS-e - ADN Contribuinte (v1).json
│   ├── API NFS-e - ADN DANFSe (v1).json
│   ├── leiaute-eventos-nfse.md                    # Leiaute XML dos eventos (cancelamento, etc.)
│   ├── exemplo-emissao.xml                        # Exemplo real de NFS-e emitida
│   └── nfse-esquemas_xsd-prodrest-v1-01-*/       # XSDs oficiais v1.00 e v1.01
└── src/NfseNacional/
    ├── Bootstrap.php
    ├── Admin/
    │   ├── ConfigFields.php      # Definição dos campos de configuração do addon (Bootstrap popovers, seções coloridas, badges obrigatório/opcional)
    │   ├── AdminController.php   # Dashboard, listagem, detalhe — UI visual com badges de status/ambiente
    │   └── Action/
    │       ├── EmitirAction.php
    │       ├── CancelarAction.php
    │       ├── ExcluirAction.php
    │       └── ReenviarEmailAction.php
    ├── ClientArea/
    │   ├── ClientAreaController.php  # Pré-computa reenviar_token e download URLs por nota
    │   └── DownloadController.php    # Proxy mTLS para DANFS-e (PDF) e XML; verifica token + propriedade
    ├── Config/
    │   └── ModuleConfig.php      # Lê configurações do addon via tbladdonmodules; getTokenSecret() auto-gera segredo HMAC; getCertificadoSenha() descriptografa AES-256-CBC transparentemente
    ├── Domain/
    │   ├── AmbienteGuard.php     # Singleton — congela o ambiente (homolog/produção)
    │   ├── AmbienteMismatchException.php
    │   ├── Entity/
    │   │   ├── Nfse.php          # Entidade principal (status, chaveAcesso, etc.)
    │   │   └── Dps.php
    │   ├── Enum/
    │   │   ├── Ambiente.php      # homologacao | producao
    │   │   ├── NfseStatus.php    # PROCESSANDO | AUTORIZADA | CANCELADA | ERRO
    │   │   └── EmissaoPolitica.php
    │   └── Service/
    │       ├── EmissaoService.php      # Orquestra emissão DPS → NFS-e
    │       ├── CancelamentoService.php # Orquestra cancelamento via evento e101101
    │       ├── ConsultaService.php
    │       ├── EmailService.php        # Envia email via localAPI; usa DownloadUrlService para os links
    │       └── DownloadUrlService.php  # Gera URLs assinadas (HMAC) para download de DANFS-e e XML
    ├── Fiscal/
    │   ├── ProviderInterface.php
    │   ├── NacionalProvider.php        # HTTP client wrapper (emitir, cancelar, consultar)
    │   ├── Signer/
    │   │   └── XmlSigner.php           # Assinatura XML com xmlseclibs (RSA-SHA256)
    │   ├── Payload/
    │   │   ├── DpsPayloadBuilder.php   # Gera XML da DPS (XSD v1.01), assina, retorna string
    │   │   └── EventoPayloadBuilder.php # Gera XML do pedRegEvento (cancelamento e101101)
    │   └── Mapper/
    │       ├── PrestadorMapper.php     # Dados do prestador (CNPJ, IM, Simples)
    │       ├── TomadorMapper.php       # Dados do tomador (CNPJ/CPF, endereço)
    │       ├── ServicoMapper.php       # Discriminação, valor, códigos fiscais
    │       └── TributoMapper.php       # ISS, PIS/COFINS, retenções
    ├── Security/
    │   └── TokenSigner.php             # HMAC-SHA256 com chave por instalação; sign(data)/verify(data,token) com hash_equals
    ├── Hook/
    │   ├── AdminInvoiceUI.php          # Painel NFS-e na tela da fatura (admin)
    │   ├── AdminInvoiceListUI.php      # Ícones de status NFS-e na listagem de faturas do admin
    │   ├── ClientInvoiceListUI.php     # Ícones de status NFS-e na listagem de faturas da área do cliente
    │   ├── HookHandler.php             # Registra todos os hooks via add_hook()
    │   ├── InvoiceHooks.php
    │   └── ClientAreaMenu.php
    ├── Persistence/
    │   ├── NfseRepository.php          # CRUD na tabela tblnfsenacional
    │   ├── DpsSequence.php             # Sequência numérica do DPS (isolada por série+ambiente)
    │   └── Migration.php
    └── Transport/
        ├── HttpClient.php              # Guzzle/cURL com mTLS, retry em 429/503
        ├── ApiEndpoints.php            # URLs de todos os endpoints SEFIN/ADN
        ├── ApiResponse.php
        └── Auth/
            ├── CertificateAuth.php     # mTLS via PKCS12 ou PEM
            └── TokenAuth.php
```

---

## Fluxo de Emissão (DPS → NFS-e)

```
InvoiceHooks / EmitirAction
    → EmissaoService::processarEmissao()
        → DpsPayloadBuilder::build()         # Gera XML DPS (XSD v1.01)
            → PrestadorMapper::map()
            → TomadorMapper::map()
            → ServicoMapper::map()
            → TributoMapper::map()
            → XmlSigner::signDom()           # Assina <infDPS> com certificado
        → NacionalProvider::emitirDps()      # GZip + Base64 → POST JSON
            POST /nfse {"dpsXmlGZipB64": "..."}
        → NfseRepository::createOrUpdate()   # Salva status, chaveAcesso, etc.
```

## Fluxo de Cancelamento (Evento e101101)

```
CancelarAction
    → CancelamentoService::cancelar()
        → EventoPayloadBuilder::buildCancelamento()  # Gera XML pedRegEvento
            → XmlSigner::signDom()                    # Assina <infPedReg>
        → NacionalProvider::cancelar()               # GZip + Base64 → POST JSON
            POST /nfse/{chaveAcesso}/eventos
            {"pedidoRegistroEventoXmlGZipB64": "..."}
        → NfseRepository::updateStatus(CANCELADA)
```

---

## Formato dos Identificadores XML

### DPS (`infDPS.Id`)
```
DPS + CodMun(7) + tpInscFed(1) + InscFederal(14) + Serie(5) + NumDPS(15) = 45 chars
     └─ IBGE 7d ┘ └ 1=CPF/2=CNPJ ┘ └── pad left 0 ─┘ └─ pad 0─┘ └── pad left 0 ──┘
```
- `tpInscFed`: **1=CPF**, **2=CNPJ** (atenção: 1 NÃO é CNPJ)
- InscFederal: sempre 14 dígitos (CPF é left-padded com zeros)

### pedRegEvento (`infPedReg.Id`)
```
PRE + chaveAcesso(50) + codEvento(6) = 59 chars
```
- codEvento cancelamento: `101101`

### Chave de acesso NFS-e
- **50 dígitos numéricos** — verificar `strlen` antes de montar qualquer ID

---

## API — Endpoints SEFIN Nacional

| Operação | Método | Path |
|----------|--------|------|
| Emitir DPS | POST | `/nfse` |
| Consultar NFS-e | GET | `/nfse/{chaveAcesso}` |
| Registrar Evento | POST | `/nfse/{chaveAcesso}/eventos` |
| Consultar Evento | GET | `/nfse/{chaveAcesso}/eventos/{tipoEvento}/{numSeqEvento}` |
| Consultar DPS | GET | `/dps/{id}` |

- **Homologação**: `sefin.producaorestrita.nfse.gov.br/SefinNacional`
- **Produção**: `sefin.nfse.gov.br/SefinNacional` (+ domínio próprio configurável)
- **DANFSE**: `adn.{dominio}/danfse/{chaveAcesso}`

### Formato de transporte
- DPS: `{"dpsXmlGZipB64": "<gzip+base64 do XML>"}` — campo **dpsXmlGZipB64**
- Evento: `{"pedidoRegistroEventoXmlGZipB64": "<gzip+base64 do XML>"}` — campo **pedidoRegistroEventoXmlGZipB64**
- Resposta sucesso: campo **nfseXmlGZipB64** (NFS-e autorizada)
- Autenticação: mTLS obrigatório (certificado ICP-Brasil A1/A3)

### mTLS — DANFSE e XML exigem certificado
- **Ambos** os endpoints (`adn.*.nfse.gov.br/danfse/` e `sefin.*.nfse.gov.br/SefinNacional/nfse/`) retornam **HTTP 496** (No Certificate) sem mTLS.
- No `DownloadController`, usar `CURLOPT_SSLCERTTYPE = 'P12'` + `CURLOPT_SSLCERT` + `CURLOPT_SSLCERTPASSWD` para P12/PFX direto (sem converter para PEM).

---

## Campos de Configuração Importantes

| Chave (tbladdonmodules) | Getter | Uso no XML |
|-------------------------|--------|------------|
| `cnpj_prestador` | `getCnpjPrestador()` | `<CNPJ>` no prestador, `CNPJAutor` no evento |
| `codigoservico` | `getCodigoServico()` | `<cTribNac>` (6 dígitos, LC 116) — OBRIGATÓRIO. Ex: `010700` para item 1.07 |
| `codigo_servico_nacional` | `getCodigoServicoNacional()` | `<cNBS>` (9 dígitos, NBS) — **OPCIONAL** no XSD (`minOccurs="0"`), deixar vazio se não souber |
| `codigomunicipal` | `getCodigoMunicipal()` | `<cTribMun>` (3 dígitos exatos) — código específico de cada município. Se comprimento ≠ 3, é omitido e logado via `logActivity`. Deixar vazio evita E0314. |
| `exigibilidade_iss` | `getExigibilidadeIss()` | Exigibilidade do ISS: 1=Exigível, 2=Não Incidência, 3=Isenção, 4=Exportação, 5=Imunidade, 6-7=Suspensa |
| `codigo_municipio_prestador` | `getCodigoMunicipioPrestador()` | `<cLocEmi>` / `<cLocPrestacao>` (7 dígitos IBGE) — sem default; **obrigatório configurar** |
| `inscricao_municipal` | `getInscricaoMunicipal()` | `<IM>` no prestador — enviado somente se não vazio. Deixar vazio em municípios que rejeitam (erro E0120) |
| `regime_tributario` | — | Define `opSimpNac`: 4=MEI(2), 1-2+optante=ME/EPP(3), resto=1 |
| `reg_ap_trib_sn` | `getRegApTribSN()` | `<regApTribSN>`: 1=Competência, 2=Caixa (obrigatório para ME/EPP e MEI) |
| `reg_esp_trib` | `getRegEspTrib()` | `<regEspTrib>`: 0=Nenhum, 1=Estimativa Anual, 2=Autônomo, 3=Soc. Profissionais, 4=Cooperativa, 5=MEI, 6=ME-EPP SN |
| `ibscbs_cind_op` | — | `<cIndOp>` no bloco IBSCBS (padrão `050101`) |
| `ibscbs_cst` | — | `<CST>` no bloco IBSCBS (padrão `000`) |
| `ibscbs_cclass_trib` | — | `<cClassTrib>` no bloco IBSCBS (padrão `000001`) |
| `ambiente` | `getAmbiente()` | `homologacao` ou `producao` |
| `certificado_path` | `getCertificadoPath()` | Caminho do PFX/P12 ou PEM |
| `certificado_senha` | `getCertificadoSenha()` | Senha do certificado — armazenada criptografada (prefixo `ENC:`); getter retorna plaintext |

### Campos removidos (não reintroduzir)

Os campos abaixo foram removidos intencionalmente para simplificar o módulo:

| Campo removido | Motivo |
|---|---|
| `reterissfatura` | Nunca foi implementado no fluxo de emissão; desconto em fatura é responsabilidade do operador |
| `produtos` / `mod_nfsenacional_grupo` | Tributação por grupo de produto aumenta complexidade sem ganho claro para open-source |
| `dps_proximo` (legado) | Número errado causa retrabalho fiscal; sequência é controlada automaticamente por `dps_proximo_{ambiente}_{serie}` |
| `excluir_latefee` | Base de cálculo é responsabilidade fiscal do operador; módulo não deve tomar essa decisão |
| `desconto` | Idem — dedução de créditos deve ser configurada na fatura, não no módulo |
| `addfunds` | Emissão por tipo de fatura é controlada pela política de emissão por cliente |

---

## Regras de Negócio Críticas

### Ambiente
- `AmbienteGuard` é **singleton** — congela o ambiente na primeira instância.
- Todos os registros na `tblnfsenacional` têm coluna `ambiente`.
- Queries sempre filtram por ambiente ativo — **nunca misturar prod/homolog**.

### Assinatura Digital
- Assina `<infDPS>` (DPS) e `<infPedReg>` (eventos) com RSA-SHA256.
- Certificado PKCS12 (.pfx/.p12) ou PEM.
- Biblioteca: `robrichards/xmlseclibs` (via Composer no diretório do módulo).
- `verAplic` tem **max 20 caracteres** (XSD `TSVerAplic`). Usar `WHMCS-NfseNac-1.0`.

### IBSCBS (Reforma Tributária — IBS/CBS)
- Bloco `<IBSCBS>` dentro do `<infDPS>` é **obrigatório a partir de 2026** no XSD v1.01.
- Campos: `finNFSe=0`, `cIndOp`, `indDest=0`, `valores/trib/gIBSCBS/{CST, cClassTrib}`.

### NBS (Nomenclatura Brasileira de Serviços)
- Campo `<cNBS>` é **OPCIONAL**.
- 9 dígitos numéricos, padded à **direita** com zeros (`STR_PAD_RIGHT`).
- Se o código configurado não existir na tabela NBS → erro **E0316**.
- Solução: deixar o campo `codigo_servico_nacional` **vazio** nas configurações.

### Simples Nacional
- `opSimpNac`: 1=Não Optante, 2=MEI, 3=ME/EPP
- `regApTribSN` é obrigatório quando `opSimpNac` é 2 ou 3 (erro **E0166** se ausente).

### Inscrição Municipal (IM)
- Alguns municípios não têm IM cadastrado no CNC NFS-e → erro **E0120**.
- `<IM>` é enviado somente se o campo `inscricao_municipal` estiver preenchido.
- Para evitar E0120: deixar o campo `inscricao_municipal` **vazio** nas configurações do addon.

### Discriminação do serviço (`xDescServ`)
- Não pode conter quebras de linha (`\n`, `\r`) → erro **E999**.
- `ServicoMapper::sanitizeDiscriminacao()` substitui `\n` por ` | ` (separador visual legível na nota).
- Itens da fatura são concatenados com `\n` antes do sanitize, resultando em: `Item A | Item B | Item C`.
- Itens dos tipos abaixo são excluídos da discriminação **e do valor total** — não representam serviços prestados:

| Tipo (`tblinvoiceitems.type`) | Descrição |
|---|---|
| `PaymentGateway` | Tarifas de boleto, cartão, etc. |
| `LateFee` | Multa por atraso |
| `Credit` | Créditos / descontos |
| `Tax` | Impostos sobre a fatura |

### Sequência DPS
- `DpsSequence` garante sequência isolada por `serie + ambiente`.
- Chave em `tbladdonmodules`: `dps_proximo_{ambiente}_{serie}` (ex: `dps_proximo_homologacao_00001`).
- **Não existe fallback** para `dps_proximo` legado — foi removido intencionalmente.
- Nunca reutilizar número DPS — o ID da DPS é único no sistema nacional.
- Não há proteção contra duplicata se um registro com ERRO for excluído e a DPS original chegou ao SEFIN.

### Nome do Cliente (`client_name`)
- **Não usar** `$invoice['client']['companyname']` — esse campo não existe na resposta de `GetInvoice`.
- Usar `EmissaoService::resolveClientName(int $userId)` que chama `GetClientsDetails` e prioriza `firstname + lastname`, caindo em `companyname` apenas se ambos estiverem vazios.

### Número e URLs da NFS-e
- `numero_nfse_nacional` é extraído do XML de retorno via regex `/<nNFSe>(\w+)<\/nNFSe>/` após `gzdecode(base64_decode($nfseXmlGZipB64))`.
- `danfse_url` e `xml_url` são construídos via `ApiEndpoints::obterDanfse()` e `consultarNfseSefin()` com a `chaveAcesso` — são URLs brutas do governo, **não usadas diretamente nos links**: ver seção Downloads abaixo.

---

## Segurança

### Política de Emissão — Emissão Manual vs. Automática

| Contexto | Regra aplicada |
|---|---|
| Hooks automáticos (`InvoiceCreated`, `InvoicePaid`) | `deveEmitir($userId, $invoiceStatus)` — usa política do cliente; se vazia, cai no global |
| Emissão manual pelo admin (`EmitirAction`) | `emissaoManualBloqueada($userId)` — bloqueia **apenas** se o campo do cliente estiver explicitamente em `1- Nao Emitir NFS-e`; campo vazio ou global "Não Emitir" **não** bloqueiam |

- `EmissaoService::emissaoManualBloqueada(int $userId): bool` — retorna `true` somente se `getPoliticaCliente()` for `EmissaoPolitica::NAO_EMITIR`.
- Nunca usar `deveEmitir()` para validar emissão manual — ele respeita o global, o que impediria emissão de clientes sem política definida quando o global é "Não Emitir".

### Botões de Ação (AdminInvoiceUI) — Padrão de Confirm
- **Nunca** usar `onclick="return confirm(...)"` em `<a href="url">` — o WHMCS tem JS global que intercepta o clique antes do `onclick` e navega de qualquer forma.
- Padrão correto para todos os botões destrutivos ou confirmáveis:
```html
<a href="javascript:void(0)" onclick="if(confirm('Mensagem?')){window.location.href='url';}">
```

### Feedback na página da fatura (AdminInvoiceUI)
- `AdminInvoiceUI::render()` lê `$_GET['nfse_status']` e `$_GET['nfse_msg']` e exibe `alert-success` ou `alert-danger` dentro do painel.
- `EmitirAction`, `CancelarAction` e `ReenviarEmailAction` redirecionam para `invoices.php?action=edit&id=X&nfse_status=success|error&nfse_msg=...`.
- Mensagens nos redirects **não devem conter aspas** — elas são passadas via `urlencode()` e depois `htmlspecialchars()`, e aspas duplas viram `&quot;`.

### Tokens de Ação (CSRF)
- **Todos** os tokens de ação usam `TokenSigner::sign(data)` / `TokenSigner::verify(data, token)`.
- Nunca usar `hash_hmac('sha1', ..., 'nfsenacional')` — chave hardcoded, SHA1 fraco.
- `TokenSigner` usa HMAC-SHA256 com segredo aleatório por instalação armazenado em `tbladdonmodules` (`_token_secret`).
- A verificação usa `hash_equals()` para resistência a timing attacks.

### Criptografia da Senha do Certificado
- A senha do certificado (`certificado_senha`) é armazenada **criptografada** (AES-256-CBC) no banco.
- A chave de criptografia fica em `.nfse_enc_key` no raiz do addon (arquivo filesystem, 0600) — separada do banco para que dump de BD sozinho não exponha a senha.
- O arquivo `.htaccess` no raiz do addon bloqueia acesso HTTP a `.nfse_enc_key` em servidores Apache. **Em nginx** (que ignora `.htaccess`) é necessário configurar manualmente uma regra `deny` para o arquivo no `server {}` block.
- **Migração automática**: `ensureCertificadoSenhaEncrypted()` é chamado em `nfsenacional_output()` — na próxima vez que o admin acessar o painel, senhas plaintext existentes são migradas automaticamente.
- Senhas criptografadas têm prefixo `ENC:` no banco; senhas sem prefixo são tratadas como plaintext (compatibilidade retroativa durante a migração).
- `getCertificadoSenha()` sempre retorna o plaintext descriptografado — consumidores não precisam saber sobre a criptografia.
- **Nunca** logar ou exibir o valor bruto de `certificado_senha` — sempre usar `getCertificadoSenha()`.

### XSS
- Todas as chamadas `htmlspecialchars()` em código PHP devem usar `ENT_QUOTES, 'UTF-8'` — especialmente em contextos de atributo HTML (`value=""`, `title=""`, `href=""`).
- Valores de `$_GET` inseridos em atributos HTML (filtros de listagem, inputs) são os de maior risco.
- **Cuidado com funções aninhadas**: `htmlspecialchars(urldecode($x))` — ao adicionar `ENT_QUOTES, 'UTF-8'`, os argumentos extras devem ficar em `htmlspecialchars(...)`, não dentro do `urldecode(...)`. Regex simplista de substituição comete esse erro.

### Convenção de dados por token

| Ação | Dado assinado |
|------|--------------|
| Emitir / Cancelar | `(string) $invoiceId` |
| Reenviar e-mail (admin + cliente) | `(string) $invoiceId` |
| Excluir registro | `(string) $nfse->id` |
| Reenviar e-mail (área do cliente) | `$invoiceId . ':' . $clientId` |
| Download DANFSE | `$nfse->id . ':danfse'` |
| Download XML | `$nfse->id . ':xml'` |

### Área do Cliente (template)
- O template `templates/client/home.tpl` usa `{$nota.reenviar_token}` (pré-computado em `ClientAreaController`).
- **Não usar** filtros Smarty `|sha1` para gerar tokens — gera SHA1 sem chave, não HMAC.

### Endpoints de Produção
- `ApiEndpoints::DOMAIN_SUFFIX['producao']` = `'nfse.gov.br'` — nunca alterar para outro domínio.
- Homologação usa `producaorestrita.nfse.gov.br`.

---

## Downloads de DANFS-e e XML

### Por que não usar os links do governo diretamente
Os endpoints `adn.*.nfse.gov.br/danfse/` e `sefin.*.nfse.gov.br/SefinNacional/nfse/` exigem **mTLS** (HTTP 496 sem certificado). Não é possível enviar esses links ao cliente.

### Proxy interno
Todos os links de DANFS-e e XML nos emails e na área do cliente passam pelo nosso endpoint proxy:

```
GET /index.php?m=nfsenacional&dl=danfse&id={nfse_id}&token={hmac}
GET /index.php?m=nfsenacional&dl=xml&id={nfse_id}&token={hmac}
```

O `DownloadController` verifica o token, busca o arquivo no governo com o certificado configurado e repassa ao cliente.

### Controle de acesso do endpoint
1. **Token HMAC obrigatório** — impede enumeração de IDs (qualquer `id` sem token válido → 403)
2. **Verificação de propriedade** — se `$_SESSION['uid']` estiver definido (cliente logado), verifica que `nfse->clientId === uid`; acesso sem sessão ativa (ex: link de email) é permitido com token válido
3. **Nenhum dado sensível exposto** — o token não contém o ID da fatura nem do cliente

### Portal público do governo (www.nfse.gov.br)
- Existe URL pública `https://www.nfse.gov.br/ConsultaPublica/Download/DANFSe?chave=...` acessível via browser sem mTLS.
- O parâmetro `chave` é double Base64 de um token binário de 56 bytes gerado internamente pelo governo — **não é derivável a partir da chave de acesso** que temos.
- Não há API pública documentada para obter esse token. Nosso proxy mTLS permanece como abordagem correta.

### Geração das URLs
`DownloadUrlService::danfseUrl(Nfse)` e `xmlUrl(Nfse)` lêem `SystemURL` da `tblconfiguration` e retornam a URL completa com token assinado. Usar sempre este serviço — nunca montar a URL manualmente.

### Onde as URLs são geradas
- **Email**: `EmailService::enviar()` chama `DownloadUrlService` antes de chamar `localAPI('SendEmail', ...)`
- **Área do cliente**: `ClientAreaController::render()` chama `DownloadUrlService` ao montar `$notasData`
- **Painel admin** (detalhe da nota): `AdminController` chama `DownloadUrlService` nos botões "Ver DANFS-e" / "Baixar XML"

---

## Template de Email

### Tipo correto: `general`, não `invoice`
- Templates do tipo `invoice` no WHMCS têm contexto Smarty limitado — `{$client_name}` e outras variáveis podem não estar disponíveis.
- O template "NFS-e Nacional" deve ser do tipo **`general`** com `id = clientId` na chamada `localAPI('SendEmail', ...)`.
- Templates `invoice` com `id = invoiceId` retornam "Sending Failed" silenciosamente quando variáveis padrão estão ausentes.

### Chamada correta
```php
localAPI('SendEmail', [
    'messagename' => 'NFS-e Nacional',
    'id'          => $nfse->clientId,   // ← client ID, não invoice ID
    'customvars'  => base64_encode(serialize([
        'idNFS'       => ...,
        'idFatura'    => ...,
        'autorizacao' => ...,
        'danfse_url'  => $urlService->danfseUrl($nfse),  // ← URL do proxy, não do governo
        'xml_url'     => $urlService->xmlUrl($nfse),
        ...
    ])),
]);
```

### Variáveis disponíveis no template Smarty
| Variável | Origem |
|----------|--------|
| `{$client_name}` | Auto-populada pelo WHMCS (tipo general) |
| `{$signature}` | Auto-populada pelo WHMCS |
| `{$idNFS}` | customvars |
| `{$idFatura}` | customvars |
| `{$autorizacao}` | customvars |
| `{$danfse_url}` | customvars — URL do proxy |
| `{$xml_url}` | customvars — URL do proxy |
| `{$chave_acesso}` | customvars |

### `ensureEmailTemplate()` — comportamento
- Remove duplicatas pelo nome antes de criar/atualizar
- Corrige templates existentes com `type != 'general'` automaticamente
- Chamado idempotentemente em `nfsenacional_output()` a cada carregamento do painel admin

---

## Hooks WHMCS

### Hooks registrados (`HookHandler::register()`)

| Hook WHMCS | Classe | Descrição |
|------------|--------|-----------|
| `AdminInvoicesControlsOutput` | `AdminInvoiceUI` | Painel NFS-e na tela de edição da fatura (admin) |
| `InvoiceCreated` | `InvoiceHooks` | Emissão automática ao criar fatura (se política configurada) |
| `InvoicePaid` | `InvoiceHooks` | Emissão automática ao pagar fatura |
| `InvoiceCancelled` | `InvoiceHooks` | Cancelamento automático da NFS-e |
| `AdminAreaFooterOutput` | `AdminInvoiceListUI` | Ícones de status NFS-e na listagem de faturas do admin |
| `ClientAreaHeadOutput` | `ClientInvoiceListUI` | Ícones de status NFS-e na listagem de faturas do cliente |

### Filtro de página dos hooks de listagem
- **Admin** (`AdminInvoiceListUI`): executa apenas em `invoices.php` e `clientsinvoices.php` — verifica `basename($_SERVER['SCRIPT_NAME'])`.
- **Cliente** (`ClientInvoiceListUI`): executa apenas em `clientarea.php` com `$_GET['action']` em `['invoices', 'viewinvoice', '']` — verifica `basename($_SERVER['SCRIPT_NAME']) === 'clientarea.php'`.
- Ambos retornam `''` imediatamente fora das páginas relevantes.

### Estrutura HTML das listagens (seletores JS)
- **Admin (`clientsinvoices.php`)**: `input[name="selectedinvoices[]"]` para obter o invoice ID pelo `cb.value`; appenda badge no `td[1]` do `tr` pai.
- **Cliente (área do cliente)**: `tr[data-url*="viewinvoice.php?id="]` para extrair invoice ID do atributo `data-url`; appenda badge no `td.dtr-control`.
- **Fallback cliente**: `a[href*="viewinvoice.php?id="]` para links diretos em outros contextos.

### Badges de status — cores
Os mapas de cor e ícone usam chaves **maiúsculas** (igual ao `NfseStatus` enum):

| Status | Cor de fundo | Ícone FA |
|--------|-------------|----------|
| `AUTORIZADA` | verde `#e8f5e9` | `fa-check-circle` |
| `CANCELADA` | laranja `#fff3e0` | `fa-ban` |
| `ERRO` | vermelho `#ffebee` | `fa-exclamation-circle` |
| `PROCESSANDO` | azul `#e3f2fd` | `fa-sync-alt` |
| `PENDENTE` | cinza `#f5f5f5` | `fa-clock` |
| `SUBSTITUIDA` | marrom `#efebe9` | `fa-exchange-alt` |

### Alinhamento na listagem admin
- Linhas **sem** NFS-e recebem um `<span>` invisível (`visibility:hidden`) de mesmas dimensões que o badge, para preservar o alinhamento das colunas.
- Usar `buildPlaceholder()` em `AdminInvoiceListUI` para todas as faturas sem NFS-e.

### Links dos badges
- **Admin**: linka para `addonmodules.php?module=nfsenacional&action=detail&id={nfse_id}` (detalhe da nota).
- **Cliente**: linka para `index.php?m=nfsenacional&search={invoiceId}` (lista de NFS-e filtrada pela fatura).

---

## Área do Cliente

### Funcionalidades
- Listagem paginada de NFS-e do cliente logado (10 por página)
- Busca por número de NFS-e ou fatura (campo `search` na URL)
- Ordenação por coluna clicável: Nº NFS-e, Fatura, Data, Valor, Status
- Reenvio de email com feedback visual (`?nfse_ok=1` / `?nfse_erro=1`)
- Links de download de DANFSE e XML via proxy seguro

### Identificação do cliente
- Usar `$_SESSION['uid']` — mais confiável que `WHMCS\Session::get('uid')` no contexto de hook/clientarea.

### Reenvio de email (área do cliente)
- Token assinado: `TokenSigner::sign($invoiceId . ':' . $clientId)`
- `ClientAreaController::handleReenviar()` verifica token + que a NFS-e pertence ao cliente logado antes de chamar `EmailService::enviar()`.

### Colunas da tabela (home.tpl)
- Ocultadas em mobile com `.hide-sm`: Data, Valor
- Ordenação preserva `search` + `dir` + `orderby` na paginação via `$sortSuffix` e `$baseUrl`

---

## Erros Comuns e Causas

| Código / Mensagem | Causa | Solução |
|-------------------|-------|---------|
| **E0004** | `tpInscFed` errado no Id da DPS | 1=CPF, 2=CNPJ (CNPJ tem 14 dígitos) |
| **RNG6110** | Falha de schema XML | Verificar tamanho/formato de campos; `verAplic` ≤ 20 chars |
| **E0120** | `<IM>` enviado mas município não tem IM no CNC | Deixar `inscricao_municipal` vazio nas configurações do addon |
| **E0314** | `cTribMun` inválido ou não administrado pelo município | Verificar código no portal NFS-e da prefeitura; se em dúvida, deixar `codigomunicipal` vazio |
| **E0166** | `regApTribSN` ausente para Simples Nacional | Configurar `reg_ap_trib_sn` (1=Competência, 2=Caixa) |
| **E0316** | Código NBS inexistente na tabela | Deixar `codigo_servico_nacional` vazio (campo opcional) |
| **E0812** | CNPJ do autor não corresponde ao certificado | `CNPJAutor` no evento deve ser o CNPJ do certificado digital |
| **E0822** | Cancelamento fora do prazo | Prazo parametrizado pelo município emissor |
| **E999** | Erro não catalogado | Verificar `\n` na discriminação, padding do NBS, campos obrigatórios |
| **E1989** | Assinatura obrigatória | Certificado digital não configurado ou XmlSigner falhou |
| **HTTP 496** | mTLS ausente em endpoint ADN/SEFIN | Configurar `certificado_path` e `certificado_senha` no addon |
| **Sending Failed** (SendEmail) | Template do tipo `invoice` com `id=invoiceId` — contexto Smarty incompleto | Usar template `type=general` com `id=clientId` |
| **"A subject is required"** (SendEmail) | `customtype` passado junto com `messagename` | Remover `customtype`; os dois modos são mutuamente exclusivos |
| **Link de email 404** | `getEmailTemplateLink()` montando URL com admindir errado | Usar URL relativa: `configemailtemplates.php?action=edit&id=X` |

---

## Tabela do Banco de Dados

**`tblnfsenacional`** — registros de NFS-e por fatura WHMCS:

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | int | PK |
| `invoice_id` | int | FK para tblinvoices |
| `id_client` | int | FK para tblclients |
| `ambiente` | varchar | `homologacao` \| `producao` |
| `status` | varchar | NfseStatus enum |
| `chave_acesso` | varchar(50) | Chave de acesso da NFS-e |
| `numero_nfse_nacional` | varchar | Número da NFS-e |
| `numero_dps` | int | Número sequencial da DPS |
| `serie_dps` | varchar | Série da DPS |
| `protocolo` | varchar | idDps retornado pela API |
| `data_emissao` | datetime | |
| `data_autorizacao` | datetime | |
| `erro` | text | Mensagem de erro (status ERRO) |
| `xml_retorno` | text | nfseXmlGZipB64 da resposta |
| `danfse_url` | varchar | URL bruta do DANFS-e no governo (não usar diretamente em links) |
| `xml_url` | varchar | URL bruta do XML no governo (não usar diretamente em links) |

**`mod_nfsenacional_grupo`** — códigos fiscais por grupo de produto:

| Coluna | Descrição |
|--------|-----------|
| `idgrupo` | ID do grupo WHMCS (tblproductgroups) |
| `codigo_servico_nacional` | NBS do grupo |
| `codigoatividade` | cTribNac do grupo |
| `codigomunicipal` | cTribMun do grupo |
| `cnae` | CNAE do grupo |

---

## Documentação de Referência

Todos os arquivos estão em `docs/`:

- **`API NFS-e - Sefin Nacional (v1).json`** — Swagger completo. Consultar para:
  - Nome exato dos campos JSON de request/response
  - Códigos HTTP esperados
  - Estrutura de erros (`erros[]`) e alertas (`alertas[]`)

- **`leiaute-eventos-nfse.md`** — Leiaute XML dos eventos (cancelamento, manifestação, etc.). Consultar para:
  - Estrutura do `pedRegEvento` e `infPedReg`
  - Campos obrigatórios por tipo de evento
  - Regras de negócio (RNs) e códigos de erro

- **`nfse-esquemas_xsd-prodrest-v1-01-*/Schemas/1.01/`** — XSDs oficiais v1.01. Consultar para:
  - Validação de tipos/tamanhos de campos
  - Sequência obrigatória de elementos
  - Constraints de pattern (ex: `DPS[0-9]{42}`, `TSVerAplic` max 20)

- **`exemplo-emissao.xml`** — XML real de NFS-e com IBSCBS incluído.
