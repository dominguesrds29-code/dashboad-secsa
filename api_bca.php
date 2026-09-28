<?php
// api_bca.php
// Endpoint de altíssima performance para consulta rápida das ocorrências do BCA no Dashboard
// Retorna exclusivamente os dados pré-processados e cacheados pela rotina diária das 06:10 (sync_bca.php)
// NUNCA interpela o portal do CENDOC via requisições web do Dashboard para proteger a infraestrutura de rede.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

date_default_timezone_set('America/Sao_Paulo');

$bcaDir = __DIR__ . DIRECTORY_SEPARATOR . 'bca';
$cacheFile = $bcaDir . DIRECTORY_SEPARATOR . 'bca_cache.json';
$logFile = $bcaDir . DIRECTORY_SEPARATOR . 'bca_sync.log';

// Apenas executa sincronização ao vivo com o CENDOC se for explicitamente solicitado por CLI ou force_sync manual de admin
if ((isset($_GET['force_sync']) && $_GET['force_sync'] === '1') || (isset($_GET['executar_agora']) && $_GET['executar_agora'] === '1')) {
    require_once __DIR__ . '/sync_bca.php';
    $resultado = executarSincronizacaoBca($logFile, $cacheFile, $db_host, $db_port, $db_name, $db_user, $db_pass);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 1. Leitura direta e instantânea do cache local gerado às 06:10
if (file_exists($cacheFile)) {
    $conteudoCache = @file_get_contents($cacheFile);
    $dados = json_decode($conteudoCache, true);
    if ($dados && is_array($dados)) {
        echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// 2. Fallback seguro caso o cache ainda não tenha sido gerado (sem interpelar o CENDOC)
$arquivosLocais = is_dir($bcaDir) ? glob($bcaDir . DIRECTORY_SEPARATOR . '*.pdf') : [];
$temPdfLocal = !empty($arquivosLocais);

$respostaSegura = [
    'success' => $temPdfLocal,
    'arquivo' => $temPdfLocal ? basename($arquivosLocais[0]) : null,
    'caminho_arquivo' => $temPdfLocal ? 'bca/' . basename($arquivosLocais[0]) : null,
    'bca_numero' => null,
    'bca_data' => null,
    'total_paginas' => 0,
    'total_ocorrencias' => 0,
    'total_citacoes' => 0,
    'total_militares_monitorados' => 0,
    'ultima_atualizacao' => date('d/m/Y H:i:s'),
    'status_download' => 'Rotina agendada diariamente às 06:10',
    'resumo' => 'Aguardando execução da rotina oficial agendada das 06:10 da manhã.',
    'ocorrencias' => []
];

echo json_encode($respostaSegura, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

