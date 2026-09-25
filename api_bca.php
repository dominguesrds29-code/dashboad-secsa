<?php
// api_bca.php
// Endpoint para consulta rápida das ocorrências do BCA no Dashboard
// Retorna os dados pré-processados e cacheados pela rotina automática das 06:00 (sync_bca.php)

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

date_default_timezone_set('America/Sao_Paulo');

$cacheFile = __DIR__ . DIRECTORY_SEPARATOR . 'bca' . DIRECTORY_SEPARATOR . 'bca_cache.json';
$logFile = __DIR__ . DIRECTORY_SEPARATOR . 'bca' . DIRECTORY_SEPARATOR . 'bca_sync.log';

// Se o cache ainda não existir ou for solicitada sincronização forçada (?force_sync=1)
if (!file_exists($cacheFile) || isset($_GET['force_sync']) || isset($_GET['executar_agora'])) {
    require_once __DIR__ . '/sync_bca.php';
    $resultado = executarSincronizacaoBca($logFile, $cacheFile, $db_host, $db_port, $db_name, $db_user, $db_pass);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Leitura direta e instantânea do cache gerado às 06:00
$conteudoCache = file_get_contents($cacheFile);
$dados = json_decode($conteudoCache, true);

if (!$dados) {
    // Se o arquivo estiver corrompido, refaz a sincronização
    require_once __DIR__ . '/sync_bca.php';
    $dados = executarSincronizacaoBca($logFile, $cacheFile, $db_host, $db_port, $db_name, $db_user, $db_pass);
}

echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
