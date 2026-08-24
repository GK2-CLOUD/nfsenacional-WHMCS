<?php

namespace GK2\NfseNacional\Admin;

use WHMCS\Database\Capsule;

/**
 * Monta os campos de configuração do addon para nfsenacional_config().
 */
class ConfigFields
{
    /**
     * Retorna o array de configuração completo do addon.
     */
    public static function build(): array
    {
        $customFieldsClientes = self::listarCustomFieldsClientes();
        $docOptions           = self::buildDocOptions($customFieldsClientes);
        $linkEmailTemplate    = self::getEmailTemplateLink();

        // ── Closure: label + badge (sem ícone de ajuda) ─────────────
        $fn = function (string $label, bool $required): string {
            $reqBadge = $required
                ? '<span style="display:inline-block;background:#d9534f;color:#fff;font-size:10px;'
                  . 'font-weight:700;padding:1px 7px;border-radius:3px;vertical-align:middle;'
                  . 'margin-left:6px;letter-spacing:0.3px;">Obrigatório</span>'
                : '<span style="display:inline-block;background:#aaa;color:#fff;font-size:10px;'
                  . 'padding:1px 7px;border-radius:3px;vertical-align:middle;'
                  . 'margin-left:6px;letter-spacing:0.3px;">Opcional</span>';

            return '<span style="font-weight:600;font-size:13px;color:#333;">' . $label . '</span>'
                . ' ' . $reqBadge;
        };

        // ── Closure: ícone de ajuda para o fim da Description ────────
        $tip = function (string $label, string $content): string {
            return ' <i class="fas fa-question-circle nfse-help-icon" '
                . 'style="color:#aaa;cursor:pointer;vertical-align:middle;margin-left:5px;font-size:13px;" '
                . 'data-toggle="popover" '
                . 'data-placement="auto" '
                . 'title="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '" '
                . 'data-content="' . htmlspecialchars($content, ENT_QUOTES, 'UTF-8') . '">'
                . '</i>';
        };

        // ── Closure: cabeçalho de seção ──────────────────────────────
        $sec = function (string $icon, string $title, string $hexColor, string $desc = ''): array {
            return [
                'FriendlyName' => '<div class="nfse-section-sep" data-color="' . $hexColor . '">'
                    . '<i class="fas ' . $icon . '"></i> ' . $title . '</div>',
                'Type'        => '',
                'Description' => $desc
                    ? '<small style="color:#777;font-style:italic;">' . $desc . '</small>'
                    : '',
                'Default'     => '',
            ];
        };

        $configarray = [
            'name'        => 'NFS-e Nacional',
            'description' => 'Addon para emissão de NFS-e integrado com a API Nacional (ADN) — SEFIN/RFB',
            'version'     => '1.0.0',
            'language'    => 'brasil',
            'author'      => '<a title="Addon NFS-e Nacional" href="https://gk2.com.br" target="_blank">GK2</a>',
            'access'      => 'admin',
        ];

        // ── Script de inicialização (injetado uma única vez) ─────────
        $initScript = <<<'HTML'
<script>
jQuery(function ($) {
    /* ── Seções: colspan=2 e estilo ── */
    $('div.nfse-section-sep').each(function () {
        var color = $(this).data('color') || '#337ab7';
        $(this).css({
            padding          : '7px 14px',
            background       : color,
            color            : '#fff',
            'border-radius'  : '4px',
            'font-size'      : '12px',
            'font-weight'    : '700',
            'text-transform' : 'uppercase',
            'letter-spacing' : '0.8px',
            margin           : '8px 0 4px'
        });
        var $row = $(this).closest('tr');
        var $tds = $row.find('td');
        if ($tds.length >= 2) {
            $tds.eq(0).attr('colspan', '2').css('padding-top', '18px');
            $tds.eq(1).hide();
        }
    });

    /* ── Popovers ── */
    $('[data-toggle="popover"]').popover({
        container : 'body',
        trigger   : 'hover focus',
        html      : false,
        placement : 'auto',
        delay     : { show: 150, hide: 100 }
    });

    /* ── Legenda ── */
    $('div.nfse-section-sep').first().closest('tr').before(
        '<tr><td colspan="2" style="padding:2px 10px 10px;border:none;">' +
        '<small style="color:#888;">Campos marcados com ' +
        '<span style="background:#d9534f;color:#fff;font-size:10px;font-weight:700;' +
        'padding:1px 7px;border-radius:3px;">Obrigatório</span> ' +
        'são essenciais para o funcionamento do módulo.</small>' +
        '</td></tr>'
    );
});
</script>
HTML;

        // ══════════════════════════════════════════════════════════════
        // SEÇÃO 1 — TRIBUTAÇÃO E SERVIÇOS
        // ══════════════════════════════════════════════════════════════
        $configarray['fields']['bloco-tributacao']  = $sec(
            'fa-calculator',
            'Tributação e Serviços',
            '#1a7abf',
            'Códigos fiscais e alíquotas utilizados na geração do XML da DPS.',
        );
        $configarray['fields']['bloco-tributacao']['Description'] =
            $initScript . ($configarray['fields']['bloco-tributacao']['Description'] ?? '');

        $configarray['fields']['cnae'] = [
            'FriendlyName' => $fn('CNAE', true),
            'Type'         => 'text',
            'Size'         => '12',
            'Description'  => 'Código CNAE da atividade principal. Ex: 6209100'
                . $tip(
                    'CNAE',
                    'Classificação Nacional de Atividades Econômicas da sua empresa. '
                    . 'Informe o código CNAE da atividade principal. '
                    . 'Ex: 6209100 para Suporte Técnico em Infraestrutura de TI.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['codigoservico'] = [
            'FriendlyName' => $fn('Código de Serviço LC 116 (cTribNac)', true),
            'Type'         => 'text',
            'Size'         => '12',
            'Description'  => '6 dígitos numéricos conforme LC 116/2003. Ex: <strong>010700</strong> (item 1.07).'
                . $tip(
                    'Código de Serviço LC 116 (cTribNac)',
                    'Código do item da lista de serviços conforme Lei Complementar 116/2003, '
                    . 'com 6 dígitos numéricos (item + subitem + desdobro nacional). '
                    . 'Este valor é enviado como cTribNac no XML da DPS. '
                    . 'Ex: 010700 para Suporte Técnico em Informática (item 1.07 da LC 116). '
                    . 'Consulte a tabela de códigos nacionais em nfse.gov.br.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['codigo_servico_nacional'] = [
            'FriendlyName' => $fn('Código de Serviço NBS', false),
            'Type'         => 'text',
            'Size'         => '15',
            'Description'  => '9 dígitos NBS. <strong>Opcional</strong> — deixe vazio se não utilizar (evita erro E0316).'
                . $tip(
                    'Código de Serviço NBS',
                    'Código NBS — Nomenclatura Brasileira de Serviços (9 dígitos). '
                    . 'Campo opcional no XSD. Deixe vazio se não souber ou se o município não exigir. '
                    . 'Não confunda com o código LC 116. '
                    . 'Um código NBS inválido gera o erro E0316.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['codigomunicipal'] = [
            'FriendlyName' => $fn('Código de Tributação Municipal (cTribMun)', false),
            'Type'         => 'text',
            'Size'         => '8',
            'Description'  => '<strong>Exatamente 3 dígitos</strong> conforme lista da prefeitura. '
                . 'Código incorreto → erro <strong>E0314</strong>. '
                . '<strong>Deixe vazio se o município não exigir.</strong>'
                . $tip(
                    'Código de Tributação Municipal (cTribMun)',
                    'Código de tributação municipal do ISSQN definido pela prefeitura do município do prestador. '
                    . 'Deve ter EXATAMENTE 3 dígitos numéricos conforme o cadastro da prefeitura (ex: 107). '
                    . 'Atenção: cada município possui sua própria lista de códigos válidos — '
                    . 'um código inválido gera o erro E0314. '
                    . 'Consulte o portal de NFS-e da sua prefeitura para obter o código correto. '
                    . 'Deixe vazio se o município não exigir este campo.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['exigibilidade_iss'] = [
            'FriendlyName' => $fn('Exigibilidade do ISS', true),
            'Type'         => 'dropdown',
            'Options'      => '1-Exigível,2-Não Incidência,3-Isenção,4-Exportação,5-Imunidade,6-Suspensa por Decisão Judicial,7-Suspensa por Processo Administrativo',
            'Description'  => 'Situação de exigibilidade do ISS enviada no XML. Para operações normais: <strong>1-Exigível</strong>.'
                . $tip(
                    'Exigibilidade do ISS',
                    'Define a situação de exigibilidade do ISS no XML da DPS. '
                    . 'Use "1-Exigível" para operações normais tributadas. '
                    . 'Outros valores: 2=Não Incidência, 3=Isenção (verificar legislação municipal), '
                    . '4=Exportação, 5=Imunidade, '
                    . '6=Suspensa por Decisão Judicial, 7=Suspensa por Processo Administrativo. '
                    . 'Consulte seu contador ou a legislação do seu município.',
                ),
            'Default'      => '1-Exigível',
        ];

        $configarray['fields']['iss'] = [
            'FriendlyName' => $fn('Alíquota ISS (%)', false),
            'Type'         => 'text',
            'Size'         => '10',
            'Description'  => 'Percentual do ISS. Ex: <strong>2.00</strong> para 2%. Use ponto como separador decimal.'
                . $tip(
                    'Alíquota ISS (%)',
                    'Alíquota do ISS aplicada sobre o valor dos serviços. '
                    . 'Informe em percentual com ponto decimal. Ex: 2.00 para 2%. '
                    . 'Consulte a legislação do seu município.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['reteriss'] = [
            'FriendlyName' => $fn('Retenção ISS (%)', false),
            'Type'         => 'text',
            'Size'         => '10',
            'Description'  => 'Percentual de retenção do ISS. Deixe vazio se não houver retenção na fonte.'
                . $tip(
                    'Retenção ISS (%)',
                    'Percentual de retenção do ISS quando o tomador é responsável pelo recolhimento na fonte. '
                    . 'Informe o mesmo valor da alíquota ISS se houver retenção integral. '
                    . 'Deixe vazio se não houver retenção.',
                ),
            'Default'      => '',
        ];

        // ══════════════════════════════════════════════════════════════
        // SEÇÃO 2 — DADOS DA EMPRESA
        // ══════════════════════════════════════════════════════════════
        $configarray['fields']['bloco-empresa'] = $sec(
            'fa-building',
            'Dados da Empresa (Prestador)',
            '#27896a',
            'Informações da empresa emissora da NFS-e: CNPJ, inscrição municipal, município e regime tributário.',
        );

        $configarray['fields']['cnpj_prestador'] = [
            'FriendlyName' => $fn('CNPJ do Prestador', true),
            'Type'         => 'text',
            'Size'         => '20',
            'Description'  => 'Somente números. Ex: 12345678000195. Deve coincidir com o CNPJ do certificado A1.'
                . $tip(
                    'CNPJ do Prestador',
                    'CNPJ da empresa emissora da NFS-e, sem pontuação. '
                    . 'Deve ser idêntico ao CNPJ do certificado digital A1 utilizado para assinar os documentos XML.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['inscricao_municipal'] = [
            'FriendlyName' => $fn('Inscrição Municipal (IM)', false),
            'Type'         => 'text',
            'Size'         => '20',
            'Description'  => 'Se preenchida, é enviada no XML. '
                . '<strong>Deixe vazio</strong> se o município rejeitar (evita erro E0120).'
                . $tip(
                    'Inscrição Municipal (IM)',
                    'Inscrição Municipal da empresa na prefeitura. '
                    . 'Se preenchida, será incluída no XML da DPS como campo <IM>. '
                    . 'Deixe vazio para omitir o campo — alguns municípios não aceitam o campo IM '
                    . 'e retornam erro E0120.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['codigo_municipio_prestador'] = [
            'FriendlyName' => $fn('Código IBGE do Município', true),
            'Type'         => 'text',
            'Size'         => '12',
            'Description'  => '7 dígitos IBGE do município do prestador. Ex: 3550308 (São Paulo/SP).'
                . $tip(
                    'Código IBGE do Município',
                    'Código IBGE de 7 dígitos do município onde a empresa está estabelecida. '
                    . 'Consulte a tabela de códigos em ibge.gov.br ou sidra.ibge.gov.br. '
                    . 'Ex: 4115200 para Maringá/PR, 3550308 para São Paulo/SP, 3304557 para Rio de Janeiro/RJ.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['regime_tributario'] = [
            'FriendlyName' => $fn('Regime Tributário', true),
            'Type'         => 'dropdown',
            'Options'      => '1-Simples Nacional,2-Simples Nacional Excesso,3-Regime Normal,4-MEI',
            'Description'  => 'Define o enquadramento tributário. Use em conjunto com "Optante pelo Simples Nacional" abaixo.'
                . $tip(
                    'Regime Tributário',
                    'Regime tributário da empresa. Afeta o campo opSimpNac no XML. '
                    . 'Use em conjunto com "Optante pelo Simples Nacional": '
                    . 'Simples + optante = ME/EPP; MEI = MEI; Normal = não optante.',
                ),
            'Default'      => '1-Simples Nacional',
        ];

        $configarray['fields']['optante_simples'] = [
            'FriendlyName' => $fn('Optante pelo Simples Nacional', true),
            'Type'         => 'yesno',
            'Description'  => 'Marque se a empresa é ME ou EPP optante do Simples Nacional.'
                . $tip(
                    'Optante pelo Simples Nacional',
                    'Marque se a empresa é optante pelo Simples Nacional (ME ou EPP). '
                    . 'Combinado com o Regime Tributário, determina o código opSimpNac correto no XML da DPS.',
                ),
            'Default'      => '1',
        ];

        $configarray['fields']['reg_ap_trib_sn'] = [
            'FriendlyName' => $fn('Apuração Simples Nacional', false),
            'Type'         => 'dropdown',
            'Options'      => '1-Competência,2-Caixa',
            'Description'  => 'Regime de apuração dos tributos. Obrigatório para ME/EPP e MEI (erro E0166 se ausente).'
                . $tip(
                    'Apuração Simples Nacional',
                    'Define se os tributos do Simples Nacional são apurados por Competência ou por Caixa. '
                    . 'Enviado apenas para optantes do Simples Nacional (ME/EPP e MEI). '
                    . 'Ignorado para regime normal. '
                    . 'A ausência deste campo para optantes gera o erro E0166.',
                ),
            'Default'      => '1-Competência',
        ];

        $configarray['fields']['reg_esp_trib'] = [
            'FriendlyName' => $fn('Regime Especial de Tributação (regEspTrib)', false),
            'Type'         => 'dropdown',
            'Options'      => '0-Nenhum,1-Estimativa Anual,2-Profissional Autônomo,3-Sociedade de Profissionais,4-Cooperativa,5-MEI,6-ME-EPP Simples Nacional',
            'Description'  => 'Regime especial de tributação do ISS. Para a maioria das empresas: <strong>0-Nenhum</strong>. Consulte seu contador.'
                . $tip(
                    'Regime Especial de Tributação (regEspTrib)',
                    'Código do regime especial de tributação do ISS, conforme legislação municipal. '
                    . 'Use "0-Nenhum" para empresas sem regime especial (maioria dos casos). '
                    . 'Outros valores: 1=Estimativa Anual, 2=Profissional Autônomo, '
                    . '3=Sociedade de Profissionais, 4=Cooperativa, 5=MEI, 6=ME-EPP Simples Nacional. '
                    . 'Consulte seu contador ou a legislação do seu município.',
                ),
            'Default'      => '0-Nenhum',
        ];

        // ══════════════════════════════════════════════════════════════
        // SEÇÃO 3 — CERTIFICADO DIGITAL A1
        // ══════════════════════════════════════════════════════════════
        $configarray['fields']['bloco-certificado'] = $sec(
            'fa-certificate',
            'Certificado Digital A1',
            '#b06a00',
            'Certificado ICP-Brasil A1 (.pfx) utilizado para assinar o XML das DPS enviadas à API Nacional.',
        );

        $configarray['fields']['certificado_path'] = [
            'FriendlyName' => $fn('Caminho do Certificado A1', true),
            'Type'         => 'text',
            'Size'         => '60',
            'Description'  => 'Caminho absoluto para o arquivo <code>.pfx</code> no servidor. Ex: /var/www/certs/empresa.pfx'
                . $tip(
                    'Caminho do Certificado A1',
                    'Caminho absoluto no servidor para o arquivo .pfx do certificado digital A1. '
                    . 'O processo do servidor web (Apache/Nginx) precisa ter permissão de leitura nesse arquivo. '
                    . 'Ex: /var/www/certs/empresa.pfx',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['certificado_senha'] = [
            'FriendlyName' => $fn('Senha do Certificado A1', true),
            'Type'         => 'password',
            'Size'         => '20',
            'Description'  => 'Senha do arquivo <code>.pfx</code> do certificado digital A1.'
                . $tip(
                    'Senha do Certificado A1',
                    'Senha de proteção do arquivo .pfx do certificado digital. '
                    . 'Armazenada no banco de dados e usada somente no momento da assinatura dos documentos XML.',
                ),
            'Default'      => '',
        ];

        // ══════════════════════════════════════════════════════════════
        // SEÇÃO 4 — API NFS-e NACIONAL
        // ══════════════════════════════════════════════════════════════
        $configarray['fields']['bloco-api'] = $sec(
            'fa-cloud',
            'API NFS-e Nacional',
            '#2e7d32',
            'Configurações de conexão com a API SEFIN/ADN do governo federal (sefin.nfse.gov.br).',
        );

        $configarray['fields']['ambiente'] = [
            'FriendlyName' => $fn('Ambiente', true),
            'Type'         => 'dropdown',
            'Options'      => 'homologacao,producao',
            'Description'  => '<strong>homologacao</strong> para testes (sem validade fiscal) &nbsp;|&nbsp; '
                . '<strong>producao</strong> para emissão real com valor legal.'
                . $tip(
                    'Ambiente',
                    'Selecione "homologacao" para realizar testes sem valor fiscal, '
                    . 'ou "producao" para emissão real. '
                    . 'Atenção: notas emitidas em homologação não têm validade legal '
                    . 'e não podem ser convertidas para produção.',
                ),
            'Default'      => 'homologacao',
        ];

        $configarray['fields']['serie_dps'] = [
            'FriendlyName' => $fn('Série da DPS', false),
            'Type'         => 'text',
            'Size'         => '8',
            'Description'  => 'Até 5 caracteres alfanuméricos. Ex: 1. Alterar a série reinicia a sequência numérica.'
                . $tip(
                    'Série da DPS',
                    'Série identificadora do documento. Até 5 caracteres alfanuméricos (ex: 1, A, DPS). '
                    . 'Alterar a série inicia uma nova sequência numérica independente. '
                    . 'Use "1" para a série padrão.',
                ),
            'Default'      => '1',
        ];

        $configarray['fields']['ver_aplic'] = [
            'FriendlyName' => $fn('Nome da Aplicação (verAplic)', false),
            'Type'         => 'text',
            'Size'         => '25',
            'Description'  => 'Identificador da aplicação emissora que aparece na NFS-e. Máximo 20 caracteres.'
                . $tip(
                    'verAplic',
                    'Campo verAplic do XML da DPS (XSD TSVerAplic, máximo 20 caracteres). '
                    . 'Aparece na NFS-e como identificação do software emissor. '
                    . 'Padrão: WHMCS-NfseNac-1.0',
                ),
            'Default'      => 'WHMCS-NfseNac-1.0',
        ];

        // Campo dinâmico: próximo número de DPS (chave depende do ambiente + série atuais)
        $dpsAmbiente   = (string) Capsule::table('tbladdonmodules')
                            ->where('module', 'nfsenacional')
                            ->where('setting', 'ambiente')
                            ->value('value');
        $dpsSerie      = (string) Capsule::table('tbladdonmodules')
                            ->where('module', 'nfsenacional')
                            ->where('setting', 'serie_dps')
                            ->value('value');
        $dpsAmbiente   = $dpsAmbiente ?: 'homologacao';
        $dpsSerie      = $dpsSerie ?: '1';
        $dpsSettingKey = 'dps_proximo_' . $dpsAmbiente . '_' . $dpsSerie;

        $configarray['fields'][$dpsSettingKey] = [
            'FriendlyName' => $fn('Próximo Número DPS', false),
            'Type'         => 'text',
            'Size'         => '12',
            'Description'  => 'Próximo número sequencial a ser emitido. '
                . 'Ambiente atual: <strong>' . htmlspecialchars($dpsAmbiente, ENT_QUOTES, 'UTF-8') . '</strong> &nbsp;|&nbsp; '
                . 'Série: <strong>' . htmlspecialchars($dpsSerie, ENT_QUOTES, 'UTF-8') . '</strong>. '
                . 'Chave: <code>' . htmlspecialchars($dpsSettingKey, ENT_QUOTES, 'UTF-8') . '</code>. '
                . '<strong>Atenção:</strong> altere somente se necessário — numerar abaixo do último emitido causa rejeição pela SEFIN.'
                . $tip(
                    'Próximo Número DPS',
                    'Número sequencial que será usado na próxima DPS emitida para o ambiente e série atuais. '
                    . 'O módulo incrementa automaticamente a cada emissão. '
                    . 'Use este campo somente para corrigir a sequência após uma falha ou migração. '
                    . 'Nunca utilize um número já enviado à SEFIN — isso causa rejeição. '
                    . 'Alterar a série reinicia a contagem em uma nova chave independente.',
                ),
            'Default'      => '1',
        ];

        // ══════════════════════════════════════════════════════════════
        // SEÇÃO 5 — CONFIGURAÇÕES OPERACIONAIS
        // ══════════════════════════════════════════════════════════════
        $configarray['fields']['bloco-operacional'] = $sec(
            'fa-cogs',
            'Configurações Operacionais',
            '#555',
            'Comportamento do módulo: emissão automática, e-mail, cancelamento e controles de acesso.',
        );

        $configarray['fields']['documento_cliente'] = [
            'FriendlyName' => $fn('Campo CPF/CNPJ do Cliente', true),
            'Type'         => 'dropdown',
            'Options'      => $docOptions,
            'Description'  => '"taxid" usa o Tax ID padrão do WHMCS. '
                . 'Ou selecione o campo personalizado que armazena o CPF/CNPJ do cliente.'
                . $tip(
                    'Campo CPF/CNPJ do Cliente',
                    'Escolha o campo do WHMCS que contém o CPF ou CNPJ do tomador do serviço. '
                    . '"taxid" usa o campo Tax ID padrão do WHMCS. '
                    . 'Selecione um campo personalizado se o CPF/CNPJ estiver em outro campo.',
                ),
            'Default'      => 'taxid',
        ];

        $configarray['fields']['emissao_padrao'] = [
            'FriendlyName' => $fn('Emissão Automática de NFS-e', true),
            'Type'         => 'dropdown',
            'Options'      => '1-Não Emitir,2-Fatura Gerada,3-Fatura Paga',
            'Description'  => 'Comportamento padrão de emissão. Pode ser sobrescrito individualmente por cliente.'
                . $tip(
                    'Emissão Automática de NFS-e',
                    'Define o comportamento padrão de emissão para clientes sem configuração específica. '
                    . '"Não Emitir": desativa a emissão automática. '
                    . '"Fatura Gerada": emite ao criar a fatura. '
                    . '"Fatura Paga": emite somente após pagamento confirmado.',
                ),
            'Default'      => '1-Não Emitir',
        ];

        $emailFriendlyName = $fn('Enviar NFS-e por E-mail', false);
        if ($linkEmailTemplate) {
            $emailFriendlyName .= ' &nbsp;<a target="_blank" href="' . $linkEmailTemplate . '">'
                . '<span class="label label-success" style="font-size:11px;">Editar Template</span></a>';
        }
        $configarray['fields']['email'] = [
            'FriendlyName' => $emailFriendlyName,
            'Type'         => 'yesno',
            'Description'  => 'Envia DANFS-e e XML por e-mail ao cliente após autorização. '
                . 'Clique em "Editar Template" para personalizar o conteúdo.'
                . $tip(
                    'Enviar NFS-e por E-mail',
                    'Se habilitado, envia automaticamente ao cliente o link do DANFS-e '
                    . 'e o arquivo XML após a autorização da nota fiscal.',
                ),
        ];

        $configarray['fields']['excluir_latefee'] = [
            'FriendlyName' => $fn('Excluir Late Fee da Base de Cálculo', false),
            'Type'         => 'yesno',
            'Description'  => 'Exclui multas por atraso (Late Fee) do valor e da discriminação da NFS-e.'
                . $tip(
                    'Excluir Late Fee da Base de Cálculo',
                    'Quando marcado, multas por atraso geradas pelo WHMCS (Late Fee) são removidas '
                    . 'do valor total e da discriminação enviados à SEFIN. '
                    . 'Quando desmarcado, Late Fee integra a base de cálculo da nota.',
                ),
        ];

        $configarray['fields']['faturas_desconto'] = [
            'FriendlyName' => $fn('Faturas (Descontos)', false),
            'Type'         => 'yesno',
            'Description'  => 'Desconta o valor de desconto da fatura do valor total da NFS-e.'
                . $tip(
                    'Faturas (Descontos)',
                    'Quando marcado, o desconto aplicado na fatura (campo discount) é subtraído '
                    . 'do valor de serviços enviado à SEFIN. '
                    . 'Use quando o desconto representa uma redução real no valor do serviço prestado.',
                ),
        ];

        $configarray['fields']['faturas_credito'] = [
            'FriendlyName' => $fn('Faturas (Fundos/Crédito)', false),
            'Type'         => 'yesno',
            'Description'  => 'Desconta o crédito de conta aplicado na fatura do valor total da NFS-e.'
                . $tip(
                    'Faturas (Fundos/Crédito)',
                    'Quando marcado, o crédito de conta (saldo do cliente) aplicado na fatura '
                    . 'é subtraído do valor de serviços enviado à SEFIN. '
                    . 'Use quando o crédito representa um pagamento antecipado pelo mesmo serviço.',
                ),
        ];

        $configarray['fields']['cancelar'] = [
            'FriendlyName' => $fn('Cancelar NFS-e ao Cancelar Fatura', false),
            'Type'         => 'yesno',
            'Description'  => 'Cancela automaticamente a NFS-e ao cancelar a fatura no WHMCS.'
                . $tip(
                    'Cancelar NFS-e ao Cancelar Fatura',
                    'Se habilitado, ao cancelar uma fatura no WHMCS que tenha NFS-e emitida, '
                    . 'o cancelamento da nota será enviado automaticamente à API Nacional '
                    . 'com motivo "Erro na emissão".',
                ),
        ];

        $configarray['fields']['perfis_manuais'] = [
            'FriendlyName' => $fn('Perfis com Permissão Manual de NFS-e', true),
            'Type'         => 'text',
            'Size'         => '40',
            'Description'  => 'IDs dos perfis de admin autorizados (separados por vírgula). Vazio = todos os admins.'
                . $tip(
                    'Perfis com Permissão Manual de NFS-e',
                    'IDs dos perfis de administrador autorizados a emitir ou cancelar NFS-e '
                    . 'manualmente pelo painel. Informe os IDs separados por vírgula. '
                    . 'Deixe vazio para permitir a todos os administradores.',
                ),
            'Default'      => '',
        ];

        $configarray['fields']['debug'] = [
            'FriendlyName' => $fn('Modo Debug', false),
            'Type'         => 'yesno',
            'Description'  => 'Ativa log detalhado das chamadas à API Nacional. '
                . '<strong>Use somente para diagnóstico</strong> — desative em produção.'
                . $tip(
                    'Modo Debug',
                    'Ativa log detalhado de todas as requisições e respostas da API Nacional '
                    . 'no Module Log do WHMCS. '
                    . 'Use somente para diagnosticar erros. Mantenha desativado em produção.',
                ),
        ];

        // ══════════════════════════════════════════════════════════════
        // SEÇÃO 6 — IBS/CBS (REFORMA TRIBUTÁRIA)
        // ══════════════════════════════════════════════════════════════
        $configarray['fields']['bloco-ibscbs'] = $sec(
            'fa-balance-scale',
            'IBS/CBS — Reforma Tributária',
            '#7b1fa2',
            'Campos obrigatórios a partir de 2026 no XSD v1.01 — LC 214/2024 (IBS e CBS).',
        );

        $configarray['fields']['ibscbs_cind_op'] = [
            'FriendlyName' => $fn('Indicador de Operação IBS/CBS (cIndOp)', false),
            'Type'         => 'text',
            'Size'         => '10',
            'Description'  => 'Código <code>cIndOp</code> para IBS/CBS. Ex: <strong>050101</strong> para TI. Consulte a tabela RFB.'
                . $tip(
                    'Indicador de Operação IBS/CBS (cIndOp)',
                    'Código do indicador de operação para IBS e CBS, previsto na Reforma Tributária (LC 214/2024). '
                    . 'Ex: 050101 para serviços de tecnologia da informação. '
                    . 'Consulte a tabela de códigos da RFB.',
                ),
            'Default'      => '050101',
        ];

        $configarray['fields']['ibscbs_cst'] = [
            'FriendlyName' => $fn('CST IBS/CBS', false),
            'Type'         => 'text',
            'Size'         => '5',
            'Description'  => 'Código de Situação Tributária do IBS/CBS. Ex: <strong>000</strong> (tributação plena).'
                . $tip(
                    'CST IBS/CBS',
                    'Código de Situação Tributária para IBS e CBS (Reforma Tributária). '
                    . 'Ex: 000 para tributação plena. '
                    . 'Consulte a tabela de CST da Reforma Tributária.',
                ),
            'Default'      => '000',
        ];

        $configarray['fields']['ibscbs_cclass_trib'] = [
            'FriendlyName' => $fn('Classificação Tributária IBS/CBS (cClassTrib)', false),
            'Type'         => 'text',
            'Size'         => '10',
            'Description'  => 'Código de classificação tributária IBS/CBS. Ex: <strong>000001</strong>. Consulte tabela RFB.'
                . $tip(
                    'Classificação Tributária IBS/CBS (cClassTrib)',
                    'Código de classificação tributária IBS/CBS conforme tabela da Reforma Tributária. '
                    . 'Ex: 000001. Consulte a documentação técnica da RFB para o código correspondente '
                    . 'ao seu tipo de serviço.',
                ),
            'Default'      => '000001',
        ];

        return $configarray;
    }

    /**
     * Lista custom fields de clientes para opção de documento.
     */
    private static function listarCustomFieldsClientes(): array
    {
        $fields = [];
        $rows   = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->get(['id', 'fieldname']);

        foreach ($rows as $row) {
            $fields[$row->id] = $row->fieldname;
        }

        return $fields;
    }

    /**
     * Monta string de opções para o dropdown de documento do cliente.
     */
    private static function buildDocOptions(array $customFields): string
    {
        $options = 'taxid';

        foreach ($customFields as $cfId => $cfName) {
            $label    = '[' . $cfId . '] ' . trim((string) $cfName);
            $options .= ',' . $label;
        }

        return $options;
    }

    /**
     * Retorna o link para editar o template de e-mail NFS-e Nacional.
     */
    private static function getEmailTemplateLink(): string
    {
        $tpl = Capsule::table('tblemailtemplates')
            ->where('name', 'NFS-e Nacional')
            ->first();

        if (empty($tpl)) {
            return '';
        }

        return 'configemailtemplates.php?action=edit&id=' . $tpl->id;
    }
}
