<?php
// sync_bca.php
// Script de Sincronização, Download e Análise Automática do BCA (Boletim do Comando da Aeronáutica)
// Projetado para ser executado via CLI (Agendador de Tarefas do Windows / Cron) diariamente às 06:00.

date_default_timezone_set('America/Sao_Paulo');

// Diretório base exclusivo para os arquivos do BCA
$baseDir = __DIR__;
$bcaDir = $baseDir . DIRECTORY_SEPARATOR . 'bca';
if (!is_dir($bcaDir)) {
    @mkdir($bcaDir, 0777, true);
    @chmod($bcaDir, 0777);
}

$logFile = $bcaDir . DIRECTORY_SEPARATOR . 'bca_sync.log';
$cacheFile = $bcaDir . DIRECTORY_SEPARATOR . 'bca_cache.json';

// Função de gerenciamento e rotação de log (limite de 10 MB)
function rotacionarLogSeNecessario($logFile, $maxBytes = 10485760) {
    if (!file_exists($logFile)) return;
    
    $tamanhoAtual = @filesize($logFile);
    if ($tamanhoAtual === false || $tamanhoAtual < $maxBytes) return;

    $conteudo = @file_get_contents($logFile);
    if (empty($conteudo)) return;

    // Remove os blocos/dias mais antigos até que o tamanho fique dentro do limite seguro (8 MB)
    $delimitador = "==================================================================";
    $blocos = explode($delimitador, $conteudo);
    $tamanhoAlvo = (int)($maxBytes * 0.8); // Mantém os 80% mais recentes

    while (count($blocos) > 2 && strlen($conteudo) > $tamanhoAlvo) {
        array_shift($blocos);
        $conteudo = implode($delimitador, $blocos);
    }

    if (strlen($conteudo) > $tamanhoAlvo) {
        $linhas = explode(PHP_EOL, $conteudo);
        while (count($linhas) > 50 && strlen(implode(PHP_EOL, $linhas)) > $tamanhoAlvo) {
            array_shift($linhas);
        }
        $conteudo = implode(PHP_EOL, $linhas);
    }

    $avisoRotacao = "[" . date('Y-m-d H:i:s') . "] [SISTEMA] Rotação de log: informações mais antigas foram apagadas para manter o arquivo abaixo de 10 MB." . PHP_EOL;
    @file_put_contents($logFile, $avisoRotacao . ltrim($conteudo));
}

// Função de log estruturado
function registrarLogBca($mensagem, $logFile) {
    rotacionarLogSeNecessario($logFile, 10485760); // 10 MB
    $linha = "[" . date('Y-m-d H:i:s') . "] " . $mensagem . PHP_EOL;
    @file_put_contents($logFile, $linha, FILE_APPEND);
    if (php_sapi_name() === 'cli') {
        echo $linha;
    }
}

// 1. Função auxiliar para carregar .env do ctr_efetivo
function carregarEnv($caminho) {
    if (!file_exists($caminho)) return;
    $linhas = file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        if (strpos(trim($linha), '#') === 0) continue;
        $partes = explode('=', $linha, 2);
        if (count($partes) === 2) {
            $nome = trim($partes[0]);
            $valor = trim(trim($partes[1]), "\"'");
            putenv("$nome=$valor");
            $_ENV[$nome] = $valor;
        }
    }
}

carregarEnv(__DIR__ . '/../ctr_efetivo/public/.env');

$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_DATABASE') ?: 'efetivosj';
$db_user = getenv('DB_USERNAME') ?: 'root';
$db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';

// 2. Normalização de texto sem acentos para busca insensível
function normalizarTextoBusca($str) {
    if (!$str) return '';
    $str = mb_strtoupper($str, 'UTF-8');
    $map = [
        'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'Ç'=>'C','Ñ'=>'N'
    ];
    $str = strtr($str, $map);
    $str = preg_replace('/[^A-Z0-9\s\-_]/', ' ', $str);
    return trim(preg_replace('/\s+/', ' ', $str));
}

// 3. Extração de texto por página real do PDF (Híbrido: pdftotext CLI + Parser Nativo PageTree)
function extrairPaginasDoPdf($content, $pdfFilePath = null) {
    // A. Método 1: pdftotext (Padrão no Linux/Ubuntu e 100% fiel à contagem física de páginas)
    if ($pdfFilePath && file_exists($pdfFilePath)) {
        $checkCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where pdftotext 2>nul' : 'which pdftotext 2>/dev/null';
        $hasTool = @shell_exec($checkCmd);
        if (!empty($hasTool)) {
            $output = @shell_exec('pdftotext "' . $pdfFilePath . '" -');
            if (!empty($output)) {
                $rawPages = explode("\x0C", $output);
                if (end($rawPages) === "") array_pop($rawPages);
                if (count($rawPages) > 0) {
                    $paginas = [];
                    foreach ($rawPages as $idx => $pTxt) {
                        $pNum = $idx + 1;
                        $pClean = trim(preg_replace('/\s+/', ' ', $pTxt));
                        $paginas[$pNum] = [
                            'pagina' => $pNum,
                            'texto_bruto' => $pClean,
                            'texto_norm' => normalizarTextoBusca($pClean)
                        ];
                    }
                    return $paginas;
                }
            }
        }
    }

    // B. Método 2: Parser Nativo de Árvore de Páginas PDF (Resolve /Pages -> /Kids)
    $objects = [];
    $streams = [];

    // 1. Objetos diretos
    if (preg_match_all('/(\d+)\s+(\d+)\s+obj\s*(.*?)(?:endobj|stream)/s', $content, $mObjs, PREG_OFFSET_CAPTURE)) {
        foreach ($mObjs[1] as $idx => $idMatch) {
            $objNum = (int)$idMatch[0];
            $objects[$objNum] = $mObjs[3][$idx][0];
        }
    }

    // 2. Streams de dados indexados por objNum
    if (preg_match_all('/(\d+)\s+(\d+)\s+obj\s*<<(?:(?!>>).)*?>>\s*stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $mStreams)) {
        foreach ($mStreams[1] as $idx => $idMatch) {
            $objNum = (int)$idMatch;
            $streams[$objNum] = $mStreams[3][$idx];
        }
    }

    // 3. Objetos dentro de /Type /ObjStm
    if (preg_match_all('/(\d+)\s+(\d+)\s+obj\s*<<(?:(?!>>).)*?\/Type\s*\/ObjStm\b(?:(?!>>).)*?\/N\s+(\d+)\b(?:(?!>>).)*?\/First\s+(\d+)\b.*?>>\s*stream[\r\n]+(.*?)[\r\n]+endstream/is', $content, $mObjStm)) {
        foreach ($mObjStm[5] as $idx => $streamData) {
            $uncompressed = @gzuncompress($streamData);
            if ($uncompressed !== false) {
                $n = (int)$mObjStm[3][$idx];
                $first = (int)$mObjStm[4][$idx];
                $header = substr($uncompressed, 0, $first);
                $body = substr($uncompressed, $first);
                
                $pairs = preg_split('/\s+/', trim($header));
                for ($i = 0; $i < count($pairs); $i += 2) {
                    if (isset($pairs[$i+1])) {
                        $numObj = (int)$pairs[$i];
                        $offset = (int)$pairs[$i+1];
                        $nextOffset = isset($pairs[$i+3]) ? (int)$pairs[$i+3] : strlen($body);
                        $objects[$numObj] = substr($body, $offset, $nextOffset - $offset);
                    }
                }
            }
        }
    }

    // Coleta recursiva de nós /Page a partir do catálogo raiz /Pages
    $rootPagesId = null;
    foreach ($objects as $id => $dict) {
        if (preg_match('/\/Type\s*\/Catalog\b(?:(?!>>).)*?\/Pages\s+(\d+)\s+(\d+)\s+R/is', $dict, $mRoot)) {
            $rootPagesId = (int)$mRoot[1];
            break;
        }
    }

    $pageObjectIds = [];
    if ($rootPagesId) {
        coletarNosPagina($rootPagesId, $objects, $pageObjectIds);
    } else {
        foreach ($objects as $id => $dict) {
            if (preg_match('/\/Type\s*\/Page\b/i', $dict)) {
                $pageObjectIds[] = $id;
            }
        }
    }

    $paginas = [];
    $pNum = 1;
    foreach ($pageObjectIds as $pageId) {
        $pDict = $objects[$pageId] ?? '';
        $pageText = "";

        $contentIds = [];
        if (preg_match('/\/Contents\s+(\d+)\s+(\d+)\s+R/i', $pDict, $mCont)) {
            $contentIds[] = (int)$mCont[1];
        } elseif (preg_match('/\/Contents\s*\[(.*?)\]/is', $pDict, $mContArr)) {
            if (preg_match_all('/(\d+)\s+\d+\s+R/i', $mContArr[1], $mRefs)) {
                foreach ($mRefs[1] as $rId) {
                    $contentIds[] = (int)$rId;
                }
            }
        }

        foreach ($contentIds as $cId) {
            if (isset($streams[$cId])) {
                $pageText .= descompactarTextoStreamPdf($streams[$cId]) . " ";
            }
        }

        $pageText = trim(mb_convert_encoding($pageText, 'UTF-8', 'ISO-8859-1'));
        $pageText = str_replace(['\\(', '\\)', '\\-'], ['(', ')', '-'], $pageText);
        $pageText = str_replace('\\', '', $pageText);
        $pageText = preg_replace('/\s+/', ' ', $pageText);

        $paginas[$pNum] = [
            'pagina' => $pNum,
            'texto_bruto' => $pageText,
            'texto_norm' => normalizarTextoBusca($pageText)
        ];
        $pNum++;
    }

    return $paginas;
}

function coletarNosPagina($nodeId, &$objects, &$pageObjectIds) {
    $dict = $objects[$nodeId] ?? '';
    if (preg_match('/\/Type\s*\/Page\b/i', $dict) && !preg_match('/\/Type\s*\/Pages\b/i', $dict)) {
        $pageObjectIds[] = $nodeId;
        return;
    }

    if (preg_match('/\/Kids\s*\[(.*?)\]/is', $dict, $mKids)) {
        if (preg_match_all('/(\d+)\s+\d+\s+R/i', $mKids[1], $mRefs)) {
            foreach ($mRefs[1] as $childId) {
                coletarNosPagina((int)$childId, $objects, $pageObjectIds);
            }
        }
    }
}

function descompactarTextoStreamPdf($stream) {
    $uncompressed = @gzuncompress($stream);
    $text = "";
    if ($uncompressed !== false) {
        $uncompressed = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $uncompressed);

        if (preg_match_all('/\((.*?)\)\s*Tj/s', $uncompressed, $tMatches)) {
            $text .= implode(' ', $tMatches[1]) . " ";
        }
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $uncompressed, $tMatches)) {
            foreach ($tMatches[1] as $tj) {
                if (preg_match_all('/\((.*?)\)/s', $tj, $subMatches)) {
                    $text .= implode('', $subMatches[1]);
                }
            }
            $text .= " ";
        }
    } else {
        if (preg_match_all('/\((.*?)\)\s*Tj/s', $stream, $tMatches)) {
            $text .= implode(' ', $tMatches[1]) . " ";
        }
    }
    return $text;
}

// 4. Download HTTP resiliente
function downloadHttp($url, $timeout = 7) {
    $data = false;
    $httpCode = 0;
    $errorMsg = '';

    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DTCEA-SJ Dashboard BCA Monitor');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errorMsg = curl_error($ch);
        curl_close($ch);

        if ($data !== false && $httpCode >= 200 && $httpCode < 300 && strlen($data) > 0) {
            return ['success' => true, 'code' => $httpCode, 'data' => $data, 'error' => ''];
        }
    }
    
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) DTCEA-SJ Dashboard BCA Monitor',
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false && strlen($data) > 0) {
        return ['success' => true, 'code' => 200, 'data' => $data, 'error' => ''];
    }

    return ['success' => false, 'code' => $httpCode, 'data' => null, 'error' => $errorMsg ?: 'Falha na conexão'];
}

// 5. Função principal de execução da sincronização e análise
function executarSincronizacaoBca($logFile, $cacheFile, $db_host, $db_port, $db_name, $db_user, $db_pass) {
    $inicioExecucao = microtime(true);
    $bcaDir = dirname($cacheFile);
    
    if (!is_dir($bcaDir)) {
        @mkdir($bcaDir, 0777, true);
        @chmod($bcaDir, 0777);
    }
    
    registrarLogBca("==================================================================", $logFile);
    registrarLogBca("INÍCIO DA ROTINA AUTOMÁTICA DE SINCRONIZAÇÃO E ANÁLISE DO BCA (06:00)", $logFile);
    registrarLogBca("==================================================================", $logFile);

    $baseUrl = 'http://www.cendoc.intraer/sisbca/';
    $urlsParaTentar = [
        'http://www.cendoc.intraer/sisbca/',
        'http://www.cendoc.intraer/sisbca/index.php',
        'http://cendoc.intraer/sisbca/',
        'http://cendoc.intraer/sisbca/index.php'
    ];

    $logTentativas = [];
    $htmlCendoc = null;
    $urlSucesso = null;

    registrarLogBca("Consultando portal SISBCA (CENDOC) na Intraer...", $logFile);

    foreach ($urlsParaTentar as $u) {
        $res = downloadHttp($u, 5);
        $logTentativas[] = [
            'url' => $u,
            'code' => $res['code'],
            'success' => $res['success'],
            'error' => $res['error']
        ];
        if ($res['success'] && !empty($res['data'])) {
            $htmlCendoc = $res['data'];
            $urlSucesso = $u;
            registrarLogBca("-> Conexão com CENDOC estabelecida com sucesso via: {$u} (HTTP {$res['code']})", $logFile);
            break;
        }
    }

    $bcaNumero = null;
    $bcaData = null;
    $bcaPdfHref = null;
    $statusDownload = 'Não executado';

    if ($htmlCendoc) {
        if (preg_match('/(?:[ÚU\xC3\xDA]|&Uacute;)?ltimo\s+Boletim\s+Ostensivo.*?BCA\s*n[ºo\?\.\s:]*([0-9]+)\s+de\s+([0-9]{1,2}[_\-\/][0-9]{1,2}[_\-\/][0-9]{2,4}).*?href=[\'"]([^\'"]+?)[\'"]/is', $htmlCendoc, $m)) {
            $bcaNumero = trim($m[1]);
            $bcaData = trim(str_replace('_', '/', $m[2]));
            $bcaPdfHref = trim($m[3]);
        } elseif (preg_match('/bca[_\-\s]*([0-9]+)[_\-\s]*([0-9]{2}[_\-\/][0-9]{2}[_\-\/][0-9]{4})\.pdf/i', $htmlCendoc, $m)) {
            $bcaNumero = trim($m[1]);
            $bcaData = trim(str_replace('_', '/', $m[2]));
            if (preg_match('/href=[\'"]([^\'"]*?' . preg_quote($m[0], '/') . ')[\'"]/i', $htmlCendoc, $mHref)) {
                $bcaPdfHref = $mHref[1];
            }
        }
        registrarLogBca("-> Boletim identificado no SISBCA: BCA nº " . ($bcaNumero ?: 'Desconhecido') . " de " . ($bcaData ?: 'Data não identificada'), $logFile);
    } else {
        registrarLogBca("[AVISO] Servidores do SISBCA (CENDOC) inacessíveis no momento. Buscando arquivo na pasta bca/...", $logFile);
    }

    if (!$bcaPdfHref && $bcaNumero && $bcaData) {
        $anoAtual = date('Y');
        $bcaPdfHref = "bca_pdf/{$anoAtual}/bca_{$bcaNumero}_" . str_replace('/', '-', $bcaData) . ".pdf";
    }

    if ($bcaPdfHref) {
        if (!preg_match('/^https?:\/\//i', $bcaPdfHref)) {
            $pdfUrl = rtrim($baseUrl, '/') . '/' . ltrim($bcaPdfHref, '/');
        } else {
            $pdfUrl = $bcaPdfHref;
        }

        $pdfFileName = basename(parse_url($pdfUrl, PHP_URL_PATH));
        if (!$pdfFileName || substr($pdfFileName, -4) !== '.pdf') {
            $pdfFileName = "bca_{$bcaNumero}_" . str_replace('/', '-', $bcaData) . ".pdf";
        }
    } else {
        $pdfFileName = null;
        $pdfUrl = null;
    }

    $pdfContent = null;
    $latestPdfNome = 'bca_desconhecido.pdf';
    $caminhoArquivoFinal = null;

    // Destino e leitura exclusivos da pasta bca/ do projeto
    if ($pdfFileName) {
        $pathVerificar = $bcaDir . DIRECTORY_SEPARATOR . $pdfFileName;
        
        // 1. Verifica se já está na pasta bca/
        if (file_exists($pathVerificar) && filesize($pathVerificar) > 50000) {
            $statusDownload = "Arquivo já existente em bca/{$pdfFileName}";
            $caminhoArquivoFinal = $pathVerificar;
            $pdfContent = file_get_contents($pathVerificar);
            $latestPdfNome = $pdfFileName;
            registrarLogBca("-> {$statusDownload}", $logFile);
        } else {
            // Se existia em /tmp de execuções anteriores, migra para bca/
            $tmpPath = '/tmp/' . $pdfFileName;
            if (file_exists($tmpPath) && filesize($tmpPath) > 50000) {
                @copy($tmpPath, $pathVerificar);
                @chmod($pathVerificar, 0777);
                if (file_exists($pathVerificar)) {
                    $pdfContent = file_get_contents($pathVerificar);
                    $caminhoArquivoFinal = $pathVerificar;
                    $latestPdfNome = $pdfFileName;
                    $statusDownload = "PDF importado para pasta bca/{$pdfFileName}";
                    registrarLogBca("-> {$statusDownload}", $logFile);
                }
            }
        }

        // 2. Se ainda não temos o conteúdo, faz o download para a pasta bca/
        if (!$pdfContent && $pdfUrl) {
            registrarLogBca("-> Baixando novo PDF do BCA a partir de: {$pdfUrl} ...", $logFile);
            $resPdf = downloadHttp($pdfUrl, 25);
            if ($resPdf['success'] && strlen($resPdf['data']) >= 50000) {
                $pdfContent = $resPdf['data'];
                $latestPdfNome = $pdfFileName;
                $caminhoSalvar = $bcaDir . DIRECTORY_SEPARATOR . $pdfFileName;
                
                @file_put_contents($caminhoSalvar, $pdfContent);
                @chmod($caminhoSalvar, 0777);
                $caminhoArquivoFinal = $caminhoSalvar;

                $statusDownload = "Download concluído com sucesso e salvo em: bca/{$pdfFileName}";
                registrarLogBca("-> {$statusDownload} (Tamanho: " . round(strlen($pdfContent) / 1024, 1) . " KB)", $logFile);
            } else {
                $statusDownload = "Falha no download: " . ($resPdf['error'] ?: 'Arquivo incompleto ou inacessível');
                registrarLogBca("[ERRO] {$statusDownload}", $logFile);
            }
        }
    }

    // 3. Se ainda não temos PDF, busca o PDF mais recente presente na pasta bca/
    if (!$pdfContent) {
        $files = glob($bcaDir . DIRECTORY_SEPARATOR . 'bca_*.pdf');
        
        if (empty($files)) {
            $fallbackDirs = ['/tmp'];
            $home = getenv('HOME') ?: getenv('USERPROFILE');
            if ($home && is_dir($home . '/Downloads')) {
                $fallbackDirs[] = $home . '/Downloads';
            }
            foreach ($fallbackDirs as $fDir) {
                $foundExt = glob(rtrim($fDir, '/\\') . DIRECTORY_SEPARATOR . 'bca_*.pdf');
                if (!empty($foundExt)) {
                    foreach ($foundExt as $fExt) {
                        $destFile = $bcaDir . DIRECTORY_SEPARATOR . basename($fExt);
                        if (!file_exists($destFile)) {
                            @copy($fExt, $destFile);
                            @chmod($destFile, 0777);
                        }
                    }
                }
            }
            $files = glob($bcaDir . DIRECTORY_SEPARATOR . 'bca_*.pdf');
        }

        if (!empty($files)) {
            usort($files, function($a, $b) {
                $numA = 0; $numB = 0;
                if (preg_match('/bca[_\-\s]*([0-9]+)/i', basename($a), $ma)) $numA = (int)$ma[1];
                if (preg_match('/bca[_\-\s]*([0-9]+)/i', basename($b), $mb)) $numB = (int)$mb[1];
                if ($numA !== $numB) {
                    return $numB <=> $numA;
                }
                return filemtime($b) <=> filemtime($a);
            });
            $caminhoArquivoFinal = $files[0];
            $pdfContent = file_get_contents($files[0]);
            $latestPdfNome = basename($files[0]);
            registrarLogBca("-> Utilizando arquivo encontrado na pasta bca/: {$latestPdfNome}", $logFile);
        }
    }

    if (!$pdfContent) {
        registrarLogBca("[FALHA CRÍTICA] Nenhum boletim PDF disponível na pasta bca/ para análise.", $logFile);
        $resultadoErro = [
            'success' => false,
            'message' => 'Nenhum boletim PDF disponível na pasta bca/ para análise.',
            'ultima_atualizacao' => date('d/m/Y H:i:s'),
            'status_download' => $statusDownload,
            'total_ocorrencias' => 0,
            'ocorrencias' => []
        ];
        @file_put_contents($cacheFile, json_encode($resultadoErro, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $resultadoErro;
    }

    // Extração estruturada do texto do PDF página por página física
    registrarLogBca("Iniciando extração do texto página a página do PDF ({$latestPdfNome})...", $logFile);
    $paginas = extrairPaginasDoPdf($pdfContent, $caminhoArquivoFinal);
    $totalPaginas = count($paginas);
    registrarLogBca("-> Total de páginas físicas identificadas e mapeadas: {$totalPaginas}", $logFile);

    // Identificação de Número e Data do BCA caso não obtidos via HTML
    $primeiraPaginaTxt = $paginas[1]['texto_bruto'] ?? '';
    if (!$bcaNumero) {
        if (preg_match('/bca[_\-\s]*([0-9]+)/i', $latestPdfNome, $mNum)) {
            $bcaNumero = $mNum[1];
        } elseif (preg_match('/BOLETIM DO COMANDO DA AERON[AÁ]UTICA N[ºO\?\s]*([0-9]+)/iu', $primeiraPaginaTxt, $mNum)) {
            $bcaNumero = $mNum[1];
        }
    }

    if (!$bcaData) {
        if (preg_match('/([0-9]{2})[_\-]([0-9]{2})[_\-](20[0-9]{2})/', $latestPdfNome, $mDate)) {
            $bcaData = "{$mDate[1]}/{$mDate[2]}/{$mDate[3]}";
        } elseif (preg_match('/([0-9]{1,2}\s+de\s+[a-zç]+\s+de\s+20[0-9]{2})/iu', $primeiraPaginaTxt, $mDate)) {
            $bcaData = trim($mDate[1]);
        }
    }

    // Consulta de militares no banco de dados
    registrarLogBca("Consultando relação de militares ativos no banco de dados `{$db_name}`...", $logFile);
    $militaresMonitorados = [];
    try {
        $db = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $secTable = 'sections';
        $secCol = 'name';
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('sections', $tables)) {
            $secTable = 'sections';
        } elseif (in_array('secoes', $tables)) {
            $secTable = 'secoes';
        }
        $cols = $db->query("SHOW COLUMNS FROM `$secTable`")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('name', $cols)) {
            $secCol = 'name';
        } elseif (in_array('nome', $cols)) {
            $secCol = 'nome';
        } elseif (in_array('sigla', $cols)) {
            $secCol = 'sigla';
        }

        $stmt = $db->query("
            SELECT 
                u.id, u.name, u.war_name, u.grade, u.saram,
                s.`$secCol` as secao_nome
            FROM users u
            LEFT JOIN `$secTable` s ON u.section_id = s.id
            WHERE u.deleted_at IS NULL
            ORDER BY u.name ASC
        ");
        $militaresMonitorados = $stmt->fetchAll();
        registrarLogBca("-> Total de militares carregados para monitoramento: " . count($militaresMonitorados), $logFile);
    } catch (Exception $e) {
        registrarLogBca("[ERRO DB] Falha ao conectar ao banco MySQL: " . $e->getMessage(), $logFile);
    }

    // Processamento estrito e focado das ocorrências por PÁGINA EXATA DO PDF
    $ocorrencias = [];
    $ocorrenciasHashes = [];

    registrarLogBca("Iniciando varredura por termos e parâmetros no BCA nº {$bcaNumero}...", $logFile);

    // Padrões de busca para Unidade
    $padroesUnidade = [
        '/\bDTCEA[\s\-_]*SJ\b/iu' => 'DTCEA-SJ',
        '/\bDESTACAMENTO\s+DE\s+CONTROLE\s+DO\s+ESPA[ÇC]O\s+A[ÉE]REO\s+DE\s+S[ÃA]O\s+JOS[ÉE]\s+DOS\s+CAMPOS\b/iu' => 'DESTACAMENTO DE CONTROLE DO ESPAÇO AÉREO DE SÃO JOSÉ DOS CAMPOS'
    ];

    // Varredura página por página física
    foreach ($paginas as $numPagina => $pData) {
        $txtBruto = $pData['texto_bruto'];
        $txtNorm = $pData['texto_norm'];
        $rotuloPagina = "Pág. {$numPagina}";

        // A) Busca por Unidade nesta página
        foreach ($padroesUnidade as $padraoRegex => $nomeExibicao) {
            if (preg_match_all($padraoRegex, $txtBruto, $mMatches)) {
                foreach ($mMatches[0] as $match) {
                    $termoEncontrado = trim($match);
                    $hash = md5("UNIDADE_{$numPagina}_" . substr($termoEncontrado, 0, 30));

                    if (!isset($ocorrenciasHashes[$hash])) {
                        $ocorrenciasHashes[$hash] = true;
                        $ocItem = [
                            'tipo' => 'unidade',
                            'titulo' => 'Citação Oficial do DTCEA-SJ',
                            'termo_encontrado' => $termoEncontrado,
                            'pagina' => $rotuloPagina,
                            'numero_pagina' => $numPagina,
                            'militar_nome' => 'DESTACAMENTO DE CONTROLE DO ESPAÇO AÉREO DE SÃO JOSÉ DOS CAMPOS',
                            'militar_guerra' => 'DTCEA-SJ',
                            'militar_saram' => 'OM',
                            'militar_secao' => 'Comando / Efetivo'
                        ];
                        $ocorrencias[] = $ocItem;
                        registrarLogBca("   [CITAÇÃO UNIDADE] {$nomeExibicao} | Termo: '{$termoEncontrado}' | Localização: {$rotuloPagina}", $logFile);
                    }
                }
            }
        }

        // B) Busca por Militares nesta página
        foreach ($militaresMonitorados as $m) {
            $nomeCompleto = trim($m['name'] ?? '');
            $nomeGuerra = trim($m['war_name'] ?? '');
            $grade = trim($m['grade'] ?? '');
            $saram = trim($m['saram'] ?? '');
            $secao = trim($m['secao_nome'] ?? 'Geral');

            $nomeFormatado = trim("{$grade} " . ($nomeGuerra ?: $nomeCompleto));

            // 1. Busca por SARAM com limites estritos de número
            if (!empty($saram)) {
                $saramDigitos = preg_replace('/[^0-9]/', '', $saram);
                if (strlen($saramDigitos) >= 6) {
                    $regexSaram = '/(?<![0-9])' . preg_quote($saramDigitos, '/') . '(?![0-9])/i';
                    if (preg_match($regexSaram, $txtBruto)) {
                        $hash = md5("MILITAR_{$m['id']}_SARAM_{$numPagina}");

                        if (!isset($ocorrenciasHashes[$hash])) {
                            $ocorrenciasHashes[$hash] = true;
                            $ocItem = [
                                'tipo' => 'militar',
                                'titulo' => "Citação do militar {$nomeFormatado}",
                                'termo_encontrado' => "SARAM {$saram}",
                                'pagina' => $rotuloPagina,
                                'numero_pagina' => $numPagina,
                                'militar_nome' => $nomeCompleto,
                                'militar_guerra' => $nomeFormatado,
                                'militar_saram' => $saram,
                                'militar_secao' => $secao
                            ];
                            $ocorrencias[] = $ocItem;
                            registrarLogBca("   [CITAÇÃO MILITAR] {$nomeFormatado} (SARAM: {$saram} | Seção: {$secao}) | Termo: SARAM {$saram} | Localização: {$rotuloPagina}", $logFile);
                        }
                    }
                }
            }

            // 2. Busca por Nome Completo estrito (mínimo 10 caracteres e 2 palavras)
            if (!empty($nomeCompleto) && mb_strlen($nomeCompleto) >= 10 && strpos($nomeCompleto, ' ') !== false) {
                $nomeNorm = normalizarTextoBusca($nomeCompleto);
                $regexNome = '/\b' . preg_replace('/\s+/', '\s+', preg_quote($nomeNorm, '/')) . '\b/i';
                
                if (preg_match($regexNome, $txtNorm)) {
                    $hash = md5("MILITAR_{$m['id']}_NOME_{$numPagina}");

                    if (!isset($ocorrenciasHashes[$hash])) {
                        $ocorrenciasHashes[$hash] = true;
                        $ocItem = [
                            'tipo' => 'militar',
                            'titulo' => "Citação do militar {$nomeFormatado}",
                            'termo_encontrado' => $nomeCompleto,
                            'pagina' => $rotuloPagina,
                            'numero_pagina' => $numPagina,
                            'militar_nome' => $nomeCompleto,
                            'militar_guerra' => $nomeFormatado,
                            'militar_saram' => $saram ?: 'Não inf.',
                            'militar_secao' => $secao
                        ];
                        $ocorrencias[] = $ocItem;
                        registrarLogBca("   [CITAÇÃO MILITAR] {$nomeFormatado} ({$nomeCompleto} | Seção: {$secao}) | Termo: Nome Completo | Localização: {$rotuloPagina}", $logFile);
                    }
                }
            }
        }
    }

    $totalOcorrencias = count($ocorrencias);
    if ($totalOcorrencias > 0) {
        $resumo = "Encontrada" . ($totalOcorrencias > 1 ? "s {$totalOcorrencias} ocorrências" : " 1 ocorrência") . " para o efetivo/OM do DTCEA-SJ no BCA nº {$bcaNumero}.";
    } else {
        $resumo = "Nenhuma ocorrência encontrada para os militares cadastrados ou para o termo DTCEA-SJ no BCA nº " . ($bcaNumero ?: 'recente') . ($bcaData ? " de {$bcaData}" : "") . ".";
    }

    $tempoTotal = round(microtime(true) - $inicioExecucao, 2);

    $resultadoFinal = [
        'success' => true,
        'arquivo' => $latestPdfNome,
        'caminho_arquivo' => 'bca/' . $latestPdfNome,
        'bca_numero' => $bcaNumero,
        'bca_data' => $bcaData,
        'total_paginas' => $totalPaginas,
        'total_ocorrencias' => $totalOcorrencias,
        'total_militares_monitorados' => count($militaresMonitorados),
        'ultima_atualizacao' => date('d/m/Y H:i:s'),
        'tempo_execucao_segundos' => $tempoTotal,
        'status_download' => $statusDownload,
        'resumo' => $resumo,
        'ocorrencias' => $ocorrencias
    ];

    // Grava no arquivo de cache JSON
    @file_put_contents($cacheFile, json_encode($resultadoFinal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    @chmod($cacheFile, 0777);

    registrarLogBca("RESUMO DA EXECUÇÃO:", $logFile);
    registrarLogBca("-> Boletim: BCA nº {$bcaNumero} ({$bcaData}) | Total de Páginas: {$totalPaginas}", $logFile);
    registrarLogBca("-> Ocorrências localizadas: {$totalOcorrencias}", $logFile);
    registrarLogBca("-> PDF armazenado em: bca/{$latestPdfNome}", $logFile);
    registrarLogBca("-> Cache JSON atualizado em: bca/bca_cache.json", $logFile);
    registrarLogBca("-> Tempo total de processamento: {$tempoTotal}s", $logFile);
    registrarLogBca("[SUCESSO] Sincronização e análise concluídas com êxito.", $logFile);
    registrarLogBca("==================================================================" . PHP_EOL, $logFile);

    return $resultadoFinal;
}

// Se chamado diretamente pela linha de comando (CLI) ou script agendado
if (php_sapi_name() === 'cli' || isset($_GET['executar_agora'])) {
    executarSincronizacaoBca($logFile, $cacheFile, $db_host, $db_port, $db_name, $db_user, $db_pass);
}
