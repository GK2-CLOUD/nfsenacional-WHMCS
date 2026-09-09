<?php

// Bootstrap dos testes sem runtime WHMCS.
// Carrega o autoload e define stubs das funções globais do WHMCS usadas
// pelos providers (logModuleCall, LogActivity, logActivity).

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('logModuleCall')) {
    function logModuleCall(...$args): void
    {
    }
}

if (!function_exists('LogActivity')) {
    function LogActivity(...$args): void
    {
    }
}

if (!function_exists('logActivity')) {
    function logActivity(...$args): void
    {
    }
}
