<?php
/**
 * NFS-e Nacional - WHMCS Addon Module
 *
 * Modulo de integracao com a API da NFS-e Nacional (ADN) para emissao,
 * consulta, cancelamento e download de documentos fiscais eletronicos.
 *
 * @package    GK2\NfseNacional
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    exit("This file cannot be accessed directly");
}

require_once __DIR__ . '/src/NfseNacional/Bootstrap.php';

use GK2\NfseNacional\Bootstrap;
use GK2\NfseNacional\Admin\ConfigFields;
use GK2\NfseNacional\Admin\AdminController;
use GK2\NfseNacional\ClientArea\ClientAreaController;
use GK2\NfseNacional\ClientArea\DownloadController;
use GK2\NfseNacional\Persistence\Migration;
use GK2\NfseNacional\Config\ModuleConfig;

Bootstrap::boot();

/**
 * Configuracao do addon.
 * Retorna metadados e campos de configuracao para o painel admin.
 */
function nfsenacional_config()
{
    return ConfigFields::build();
}

/**
 * Ativacao do addon.
 * Cria tabelas, templates de email e custom fields necessarios.
 */
function nfsenacional_activate()
{
    try {
        // Criar tabela principal
        $migration = new Migration();
        $result = $migration->up();

        if (!$result['sucesso']) {
            return [
                'status' => 'error',
                'description' => 'Erro ao criar tabela: ' . ($result['msg'] ?? 'Erro desconhecido'),
            ];
        }

        // Inserir configuracoes padrao
        $config = new ModuleConfig();
        $config->insertDefaults();

        // Criar template de email
        $config->ensureEmailTemplate();

        // Criar custom fields
        $config->ensureCustomFields();

        return [
            'status' => 'success',
            'description' => 'Addon NFS-e Nacional ativado com sucesso!',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Erro na ativacao: ' . $e->getMessage(),
        ];
    }
}

/**
 * Desativacao do addon.
 * Nao remove tabelas para preservar dados historicos.
 */
function nfsenacional_deactivate()
{
    return [
        'status' => 'success',
        'description' => 'Addon NFS-e Nacional desativado com sucesso!',
    ];
}

/**
 * Pagina administrativa do addon.
 * Despacha acoes (dashboard, list, detail) para o AdminController.
 */
function nfsenacional_output($vars)
{
    // Garante colunas e template de email em instalações existentes (idempotente)
    (new Migration())->addColumnsIfMissing();
    $moduleConfig = new ModuleConfig();
    $moduleConfig->ensureEmailTemplate();
    // Migra senha do certificado de plaintext para AES-256-CBC (idempotente)
    $moduleConfig->ensureCertificadoSenhaEncrypted();

    $controller = new AdminController();
    $controller->dispatch($vars);
}

/**
 * Area do cliente.
 * Renderiza a listagem de NFS-e do cliente logado.
 */
function nfsenacional_clientarea($vars)
{
    // Download proxy (DANFS-e / XML) — encerra com exit
    $dl = $_GET['dl'] ?? '';
    if (in_array($dl, ['danfse', 'xml'], true)) {
        (new DownloadController())->handle($dl);
    }

    // Endpoint AJAX da tabela — encerra com exit
    if (!empty($_GET['ajax'])) {
        (new ClientAreaController())->handleAjax();
    }

    $controller = new ClientAreaController();
    return $controller->render($vars);
}
