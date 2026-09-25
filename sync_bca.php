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

    // Se ainda exceder por falta de delimitadores, descarta linhas mais antigas
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
    rotacionarLogSeNecessario($logFile, 10485760); // 10 MB (10 * 1024 * 1024 bytes)
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

// 2. Extração de texto de PDF nativo com descompressão FlateDecode
function extractTextFromPdfContent($content) {
    $text = "";
    if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches)) {
        foreach ($matches[1] as $stream) {
            $uncompressed = @gzuncompress($stream);
            if ($uncompressed !== false) {
                $uncompressed = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
                    return chr(octdec($m[1]));
                }, $uncompressed);

                if (preg_match_all('/\((.*?)\)\s*Tj/s', $uncompressed, $tMatches)) {
                    $text .= implode(' ', $tMatches[1]) . "\n";
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $uncompressed, $tMatches)) {
                    foreach ($tMatches[1] as $tj) {
                        if (preg_match_all('/\((.*?)\)/s', $tj, $subMatches)) {
                            $text .= implode('', $subMatches[1]);
                        }
                    }
                    $text .= "\n";
                }
            } else {
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $stream, $tMatches)) {
                    $text .= implode(' ', $tMatches[1]) . "\n";
                }
            }
        }
    }

    $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
    $text = str_replace(['\\(', '\\)', '\\-'], ['(', ')', '-'], $text);
    $text = str_replace('\\', '', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return $text;
}

// 3. Normalização de texto sem acentos para busca insensível
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

    // O destino e leitura são EXCLUSIVOS da pasta bca/ do projeto
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
            // Se existia em /tmp de execuções anteriores, migra para a pasta bca/
            $tmpPath = '/tmp/' . $pdfFileName;
            if (file_exists($tmpPath) && filesize($tmpPath) > 50000) {
                @copy($tmpPath, $pathVerificar);
                @chmod($pathVerificar, 0777);
                if (file_exists($pathVerificar)) {
                    $pdfContent = file_get_contents($pathVerificar);
                    $caminhoArquivoFinal = $pathVerificar;
                    $latestPdfNome = $pdfFileName;
                    $statusDownload = "PDF importado com sucesso para pasta bca/{$pdfFileName}";
                    registrarLogBca("-> {$statusDownload}", $logFile);
                }
            }
        }

        // 2. Se ainda não temos o conteúdo, faz o download diretamente para a pasta bca/
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

    // 3. Se ainda não temos PDF, busca o PDF mais recente presente exclusivamente na pasta bca/
    if (!$pdfContent) {
        $files = glob($bcaDir . DIRECTORY_SEPARATOR . 'bca_*.pdf');
        
        // Fallback: se a pasta bca/ estiver vazia, importa de /tmp ou Downloads
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

    // Extração e normalização do texto do PDF
    registrarLogBca("Iniciando extração e descompressão do texto do PDF ({$latestPdfNome})...", $logFile);
    $pdfTextBruto = extractTextFromPdfContent($pdfContent);
    $pdfTextNorm = normalizarTextoBusca($pdfTextBruto);
    registrarLogBca("-> Texto extraído: " . strlen($pdfTextBruto) . " caracteres (" . strlen($pdfTextNorm) . " normalizados)", $logFile);

    // Identificação de Número e Data do BCA caso não obtidos via HTML
    if (!$bcaNumero) {
        if (preg_match('/bca[_\-\s]*([0-9]+)/i', $latestPdfNome, $mNum)) {
            $bcaNumero = $mNum[1];
        } elseif (preg_match('/BOLETIM DO COMANDO DA AERON[AÁ]UTICA N[ºO\?\s]*([0-9]+)/iu', $pdfTextBruto, $mNum)) {
            $bcaNumero = $mNum[1];
        }
    }

    if (!$bcaData) {
        if (preg_match('/([0-9]{2})[_\-]([0-9]{2})[_\-](20[0-9]{2})/', $latestPdfNome, $mDate)) {
            $bcaData = "{$mDate[1]}/{$mDate[2]}/{$mDate[3]}";
        } elseif (preg_match('/([0-9]{1,2}\s+de\s+[a-zç]+\s+de\s+20[0-9]{2})/iu', $pdfTextBruto, $mDate)) {
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

    // Processamento de ocorrências
    $ocorrencias = [];
    $ocorrenciasHashes = [];

    function extrairContextoAto($pos, $textoBruto, $tamanhoContexto = 260) {
        $inicio = max(0, $pos - 100);
        $fim = min(strlen($textoBruto), $pos + $tamanhoContexto);
        $trecho = substr($textoBruto, $inicio, $fim - $inicio);
        $trecho = trim(preg_replace('/\s+/', ' ', $trecho));
        $trecho = preg_replace('/Fl\.\s*n[ºo\?\s]*.*?15[0-9]{3}/iu', ' ', $trecho);
        $trecho = preg_replace('/\(Continuação.*?\)/iu', ' ', $trecho);
        return trim(preg_replace('/\s+/', ' ', $trecho));
    }

    registrarLogBca("Iniciando varredura por termos e parâmetros no BCA nº {$bcaNumero}...", $logFile);

    // A) Busca pelo termo DTCEA-SJ e variações da Unidade
    $termosUnidade = [
        'DTCEA-SJ',
        'DTCEA SJ',
        'DTCEA - SJ',
        'DESTACAMENTO DE CONTROLE DO ESPACO AEREO DE SAO JOSE DOS CAMPOS'
    ];

    foreach ($termosUnidade as $termoU) {
        $termoNorm = normalizarTextoBusca($termoU);
        $offset = 0;
        while (($pos = strpos($pdfTextNorm, $termoNorm, $offset)) !== false) {
            $contexto = extrairContextoAto($pos, $pdfTextBruto, 280);
            $hash = md5('UNIDADE_' . substr($contexto, 0, 80));
            
            if (!isset($ocorrenciasHashes[$hash]) && strlen($contexto) > 20) {
                $ocorrenciasHashes[$hash] = true;
                $ocItem = [
                    'tipo' => 'unidade',
                    'titulo' => 'Citação Oficial do DTCEA-SJ',
                    'termo_encontrado' => $termoU,
                    'militar_nome' => 'DESTACAMENTO DE CONTROLE DO ESPAÇO AÉREO DE SÃO JOSÉ DOS CAMPOS',
                    'militar_guerra' => 'DTCEA-SJ',
                    'militar_saram' => 'OM',
                    'militar_secao' => 'Comando / Efetivo',
                    'contexto' => $contexto
                ];
                $ocorrencias[] = $ocItem;
                registrarLogBca("   [CITAÇÃO OM] Termo: '{$termoU}' | Trecho: \"{$contexto}\"", $logFile);
            }
            $offset = $pos + strlen($termoNorm);
            if (count($ocorrencias) >= 25) break;
        }
    }

    // B) Busca por cada militar do efetivo (SARAM e Nome Completo)
    foreach ($militaresMonitorados as $m) {
        $nomeCompleto = trim($m['name'] ?? '');
        $nomeGuerra = trim($m['war_name'] ?? '');
        $grade = trim($m['grade'] ?? '');
        $saram = trim($m['saram'] ?? '');
        $secao = trim($m['secao_nome'] ?? 'Geral');

        $nomeFormatado = trim("{$grade} " . ($nomeGuerra ?: $nomeCompleto));

        // 1. Busca por SARAM
        if (!empty($saram)) {
            $saramDigitos = preg_replace('/[^0-9]/', '', $saram);
            $padroesSaram = [];
            if (strlen($saramDigitos) >= 6) {
                $padroesSaram[] = normalizarTextoBusca($saram);
                $padroesSaram[] = $saramDigitos;
                if (strlen($saramDigitos) == 7) {
                    $padroesSaram[] = substr($saramDigitos, 0, 6) . '-' . substr($saramDigitos, 6, 1);
                    $padroesSaram[] = substr($saramDigitos, 0, 6) . ' ' . substr($saramDigitos, 6, 1);
                }
            }

            foreach (array_unique($padroesSaram) as $padrao) {
                if (empty($padrao)) continue;
                $pos = strpos($pdfTextNorm, $padrao);
                if ($pos !== false) {
                    $contexto = extrairContextoAto($pos, $pdfTextBruto, 280);
                    $hash = md5($m['id'] . '_SARAM_' . substr($contexto, 0, 80));
                    
                    if (!isset($ocorrenciasHashes[$hash]) && strlen($contexto) > 20) {
                        $ocorrenciasHashes[$hash] = true;
                        $ocItem = [
                            'tipo' => 'militar',
                            'titulo' => "Citação do militar {$nomeFormatado}",
                            'termo_encontrado' => "SARAM {$saram}",
                            'militar_nome' => $nomeCompleto,
                            'militar_guerra' => $nomeFormatado,
                            'militar_saram' => $saram,
                            'militar_secao' => $secao,
                            'contexto' => $contexto
                        ];
                        $ocorrencias[] = $ocItem;
                        registrarLogBca("   [CITAÇÃO MILITAR - SARAM] Militar: {$nomeFormatado} (SARAM: {$saram} | Seção: {$secao}) | Trecho: \"{$contexto}\"", $logFile);
                    }
                    break;
                }
            }
        }

        // 2. Busca por Nome Completo
        if (!empty($nomeCompleto) && mb_strlen($nomeCompleto) >= 8) {
            $nomeNorm = normalizarTextoBusca($nomeCompleto);
            $pos = strpos($pdfTextNorm, $nomeNorm);
            
            if ($pos !== false) {
                $contexto = extrairContextoAto($pos, $pdfTextBruto, 280);
                $hash = md5($m['id'] . '_NOME_' . substr($contexto, 0, 80));
                
                if (!isset($ocorrenciasHashes[$hash]) && strlen($contexto) > 20) {
                    $ocorrenciasHashes[$hash] = true;
                    $ocItem = [
                        'tipo' => 'militar',
                        'titulo' => "Citação do militar {$nomeFormatado}",
                        'termo_encontrado' => $nomeCompleto,
                        'militar_nome' => $nomeCompleto,
                        'militar_guerra' => $nomeFormatado,
                        'militar_saram' => $saram ?: 'Não inf.',
                        'militar_secao' => $secao,
                        'contexto' => $contexto
                    ];
                    $ocorrencias[] = $ocItem;
                    registrarLogBca("   [CITAÇÃO MILITAR - NOME] Militar: {$nomeFormatado} ({$nomeCompleto} | Seção: {$secao}) | Trecho: \"{$contexto}\"", $logFile);
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
    registrarLogBca("-> Boletim: BCA nº {$bcaNumero} ({$bcaData})", $logFile);
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
