<?php

namespace GK2\NfseNacional\Persistence;

use WHMCS\Database\Capsule;

/**
 * Gerencia criacao e atualizacao do schema da tabela tblnfsenacional.
 */
class Migration
{
    private const TABLE_NAME = 'tblnfsenacional';
    private const GRUPO_TABLE = 'mod_nfsenacional_grupo';

    /**
     * Cria a tabela principal se nao existir.
     *
     * @return array ['sucesso' => bool, 'msg' => string]
     */
    public function up(): array
    {
        try {
            if (!Capsule::schema()->hasTable(self::TABLE_NAME)) {
                Capsule::schema()->create(self::TABLE_NAME, function ($table) {
                    $table->increments('id');
                    $table->integer('id_client')->nullable()->index();
                    $table->string('id_invoice', 15)->nullable()->index();
                    $table->string('client_name', 255)->nullable();
                    $table->decimal('total', 10, 2)->nullable();
                    $table->string('status', 30)->nullable()->default('PENDENTE');
                    $table->integer('numero_dps')->nullable();
                    $table->string('serie_dps', 10)->nullable();
                    $table->string('chave_acesso', 60)->nullable()->unique();
                    $table->string('numero_nfse_nacional', 30)->nullable();
                    $table->string('protocolo', 60)->nullable();
                    $table->string('codigo_verificacao', 60)->nullable();
                    $table->string('xml_url', 500)->nullable();
                    $table->string('ambiente', 15)->nullable()->index();
                    $table->text('erro')->nullable();
                    $table->dateTime('data_emissao')->nullable();
                    $table->dateTime('data_autorizacao')->nullable();
                    $table->timestamps();
                });
            }

            // Tabela de grupos de produtos (personalizacao fiscal por grupo)
            if (!Capsule::schema()->hasTable(self::GRUPO_TABLE)) {
                Capsule::schema()->create(self::GRUPO_TABLE, function ($table) {
                    $table->increments('id');
                    $table->integer('idgrupo')->nullable()->index();
                    $table->string('cnae', 20)->nullable();
                    $table->string('codigoatividade', 20)->nullable();
                    $table->string('codigomunicipal', 20)->nullable();
                    $table->string('codigo_servico_nacional', 20)->nullable();
                    $table->string('iss', 10)->nullable();
                });
            }

            // Adicionar colunas que possam faltar (upgrades futuros)
            $this->addColumnsIfMissing();

            return ['sucesso' => true, 'msg' => 'Tabelas criadas com sucesso.'];
        } catch (\Exception $e) {
            return ['sucesso' => false, 'msg' => $e->getMessage()];
        }
    }

    /**
     * Verifica e adiciona colunas novas (para upgrades do modulo).
     */
    public function addColumnsIfMissing(): void
    {
        $schema = Capsule::schema();

        // Exemplo: adicionar colunas de eventos futuros
        if ($schema->hasTable(self::TABLE_NAME)) {
            if (!$schema->hasColumn(self::TABLE_NAME, 'evento_cancelamento_id')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->string('evento_cancelamento_id', 60)->nullable()->after('xml_url');
                });
            }

            if (!$schema->hasColumn(self::TABLE_NAME, 'evento_substituicao_id')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->string('evento_substituicao_id', 60)->nullable()->after('evento_cancelamento_id');
                });
            }

            if (!$schema->hasColumn(self::TABLE_NAME, 'xml_envio')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->longText('xml_envio')->nullable()->after('erro');
                });
            }

            if (!$schema->hasColumn(self::TABLE_NAME, 'xml_retorno')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->longText('xml_retorno')->nullable()->after('xml_envio');
                });
            }

            if (!$schema->hasColumn(self::TABLE_NAME, 'valor_iss')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->decimal('valor_iss', 10, 2)->nullable()->after('total');
                });
            }

            if (!$schema->hasColumn(self::TABLE_NAME, 'retido_iss')) {
                $schema->table(self::TABLE_NAME, function ($table) {
                    $table->tinyInteger('retido_iss')->nullable()->default(0)->after('valor_iss');
                });
            }
        }
    }
}
