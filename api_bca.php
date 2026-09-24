<?php
// api_bca.php
// Endpoint para busca de ocorrências no Boletim da Aeronáutica (BCA)
// Filtra automaticamente por SARAM e Nome do efetivo cadastrado no banco de dados (efetivosj) e pelo termo DTCEA-SJ

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

date_default_timezone_set('America/Sao_Paulo');

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
                // Caso o stream não esteja comprimido
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

// 5. Diretórios permitidos para gravação e leitura de PDFs (Exclusivamente /tmp, downloads e temp do sistema)
function getBcaDirectories() {
    $dirs = [];
    
    // 1. Diretório /tmp padrão do Linux
    if (is_dir('/tmp')) {
        $dirs[] = '/tmp';
    }
    
    // 2. Diretório Downloads do usuário (Linux / Windows)
    $home = getenv('HOME') ?: getenv('USERPROFILE');
    if ($home && is_dir($home . '/Downloads')) {
        $dirs[] = $home . '/Downloads';
    }
    
    // 3. Diretório temporário do sistema operacional
    $sysTemp = sys_get_temp_dir();
    if ($sysTemp && is_dir($sysTemp)) {
        $dirs[] = rtrim($sysTemp, '/\\');
    }

    return array_unique($dirs);
}

// 6. Sincronização com o SISBCA (CENDOC)
function sincronizarUltimoBcaCendoc() {
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
            break;
        }
    }

    if (!$htmlCendoc) {
        return [
            'tentou_download' => false,
            'sucesso' => false,
            'mensagem' => 'Sem conexão com os servidores SISBCA (CENDOC). Usando cache local.',
            'url_acessada' => null,
            'bca_numero' => null,
            'bca_data' => null,
            'pdf_arquivo' => null,
            'tentativas' => $logTentativas
        ];
    }

    $bcaNumero = null;
    $bcaData = null;
    $bcaPdfHref = null;

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

    if (!$bcaPdfHref) {
        $anoAtual = date('Y');
        $bcaPdfHref = "bca_pdf/{$anoAtual}/bca_{$bcaNumero}_" . str_replace('/', '-', $bcaData) . ".pdf";
    }

    if (!preg_match('/^https?:\/\//i', $bcaPdfHref)) {
        $pdfUrl = rtrim($baseUrl, '/') . '/' . ltrim($bcaPdfHref, '/');
    } else {
        $pdfUrl = $bcaPdfHref;
    }

    $pdfFileName = basename(parse_url($pdfUrl, PHP_URL_PATH));
    if (!$pdfFileName || substr($pdfFileName, -4) !== '.pdf') {
        $pdfFileName = "bca_{$bcaNumero}_" . str_replace('/', '-', $bcaData) . ".pdf";
    }

    $dirs = getBcaDirectories();
    
    // Verifica se o arquivo já existe em algum dos diretórios
    foreach ($dirs as $d) {
        $pathVerificar = rtrim($d, '/\\') . DIRECTORY_SEPARATOR . $pdfFileName;
        if (file_exists($pathVerificar) && filesize($pathVerificar) > 50000) {
            return [
                'tentou_download' => true,
                'sucesso' => true,
                'mensagem' => "BCA nº {$bcaNumero} já baixado em {$pathVerificar}",
                'url_acessada' => $urlSucesso,
                'bca_numero' => $bcaNumero,
                'bca_data' => $bcaData,
                'pdf_arquivo' => $pdfFileName,
                'caminho_completo' => $pathVerificar,
                'tentativas' => $logTentativas
            ];
        }
    }

    // Realiza o download do PDF
    $resPdf = downloadHttp($pdfUrl, 15);
    if (!$resPdf['success'] || strlen($resPdf['data']) < 50000) {
        return [
            'tentou_download' => true,
            'sucesso' => false,
            'mensagem' => "Falha ao baixar PDF do BCA em {$pdfUrl}: " . $resPdf['error'],
            'url_acessada' => $pdfUrl,
            'bca_numero' => $bcaNumero,
            'bca_data' => $bcaData,
            'pdf_arquivo' => $pdfFileName,
            'tentativas' => $logTentativas
        ];
    }

    // Salva diretamente na raiz de /tmp ou nos diretórios configurados
    $gravouEm = null;
    foreach ($dirs as $d) {
        $caminhoSalvar = rtrim($d, '/\\') . DIRECTORY_SEPARATOR . $pdfFileName;
        $saved = @file_put_contents($caminhoSalvar, $resPdf['data']);
        if ($saved !== false) {
            @chmod($caminhoSalvar, 0777);
            $gravouEm = $caminhoSalvar;
            break;
        }
    }

    return [
        'tentou_download' => true,
        'sucesso' => true,
        'mensagem' => "BCA nº {$bcaNumero} baixado com sucesso!",
        'url_acessada' => $pdfUrl,
        'bca_numero' => $bcaNumero,
        'bca_data' => $bcaData,
        'pdf_arquivo' => $pdfFileName,
        'caminho_completo' => $gravouEm,
        'pdf_conteudo_memoria' => $resPdf['data'],
        'tentativas' => $logTentativas
    ];
}

// Executa sincronização com o CENDOC
$syncCendoc = sincronizarUltimoBcaCendoc();

// 7. Busca o PDF mais recente disponível
$allDirs = getBcaDirectories();
$files = [];
foreach ($allDirs as $d) {
    $encontrados = glob(rtrim($d, '/\\') . DIRECTORY_SEPARATOR . 'bca_*.pdf');
    if (!empty($encontrados)) {
        $files = array_merge($files, $encontrados);
    }
}
$files = array_unique($files);

$pdfContent = null;
$latestPdfNome = 'bca_desconhecido.pdf';

if (!empty($syncCendoc['pdf_conteudo_memoria'])) {
    $pdfContent = $syncCendoc['pdf_conteudo_memoria'];
    $latestPdfNome = $syncCendoc['pdf_arquivo'] ?? 'bca_cendoc_recente.pdf';
} elseif (!empty($syncCendoc['caminho_completo']) && file_exists($syncCendoc['caminho_completo'])) {
    $pdfContent = file_get_contents($syncCendoc['caminho_completo']);
    $latestPdfNome = basename($syncCendoc['caminho_completo']);
} elseif (!empty($files)) {
    usort($files, function($a, $b) {
        $numA = 0;
        $numB = 0;
        if (preg_match('/bca[_\-\s]*([0-9]+)/i', basename($a), $ma)) $numA = (int)$ma[1];
        if (preg_match('/bca[_\-\s]*([0-9]+)/i', basename($b), $mb)) $numB = (int)$mb[1];
        if ($numA !== $numB) {
            return $numB <=> $numA;
        }
        return filemtime($b) <=> filemtime($a);
    });
    $pdfContent = file_get_contents($files[0]);
    $latestPdfNome = basename($files[0]);
}

if (!$pdfContent) {
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum boletim PDF encontrado em /tmp e SISBCA indisponível.',
        'sync_cendoc' => $syncCendoc,
        'ocorrencias' => [],
        'total_ocorrencias' => 0
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Extrai texto bruto e texto normalizado do PDF
$pdfTextBruto = extractTextFromPdfContent($pdfContent);
$pdfTextNorm = normalizarTextoBusca($pdfTextBruto);

// Identifica Número do BCA e Data
$bcaNumero = '';
$bcaData = '';

if (!empty($syncCendoc['bca_numero'])) {
    $bcaNumero = $syncCendoc['bca_numero'];
} elseif (preg_match('/bca[_\-\s]*([0-9]+)/i', $latestPdfNome, $mNum)) {
    $bcaNumero = $mNum[1];
} elseif (preg_match('/BOLETIM DO COMANDO DA AERON[AÁ]UTICA N[ºO\?\s]*([0-9]+)/iu', $pdfTextBruto, $mNum)) {
    $bcaNumero = $mNum[1];
}

if (!empty($syncCendoc['bca_data'])) {
    $bcaData = $syncCendoc['bca_data'];
} elseif (preg_match('/([0-9]{2})[_\-]([0-9]{2})[_\-](20[0-9]{2})/', $latestPdfNome, $mDate)) {
    $bcaData = "{$mDate[1]}/{$mDate[2]}/{$mDate[3]}";
} elseif (preg_match('/([0-9]{1,2}\s+de\s+[a-zç]+\s+de\s+20[0-9]{2})/iu', $pdfTextBruto, $mDate)) {
    $bcaData = trim($mDate[1]);
}

// 8. Consulta militares ativos no banco de dados para criar chaves de busca
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
} catch (Exception $e) {
    // Se o banco estiver indisponível no momento
    $militaresMonitorados = [];
}

// 9. Processamento de ocorrências: Busca por SARAM, Nome Completo e DTCEA-SJ
$ocorrencias = [];
$ocorrenciasHashes = [];

// Função auxiliar para extrair trecho contextual envolto ao termo encontrado
function extrairContextoAto($pos, $textoBruto, $tamanhoContexto = 260) {
    $inicio = max(0, $pos - 100);
    $fim = min(strlen($textoBruto), $pos + $tamanhoContexto);
    
    // Tenta expandir para o início da frase/ato anterior
    $trecho = substr($textoBruto, $inicio, $fim - $inicio);
    $trecho = trim(preg_replace('/\s+/', ' ', $trecho));
    
    // Remove cabeçalhos repetitivos de paginação do BCA
    $trecho = preg_replace('/Fl\.\s*n[ºo\?\s]*.*?15[0-9]{3}/iu', ' ', $trecho);
    $trecho = preg_replace('/\(Continuação.*?\)/iu', ' ', $trecho);
    return trim(preg_replace('/\s+/', ' ', $trecho));
}

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
            $ocorrencias[] = [
                'tipo' => 'unidade',
                'titulo' => 'Citação Oficial do DTCEA-SJ',
                'termo_encontrado' => $termoU,
                'militar_nome' => 'DESTACAMENTO DE CONTROLE DO ESPAÇO AÉREO DE SÃO JOSÉ DOS CAMPOS',
                'militar_guerra' => 'DTCEA-SJ',
                'militar_saram' => 'OM',
                'militar_secao' => 'Comando / Efetivo',
                'contexto' => $contexto
            ];
        }
        $offset = $pos + strlen($termoNorm);
        if (count($ocorrencias) >= 20) break;
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

    // 1. Busca por SARAM (formatado ou apenas dígitos)
    if (!empty($saram)) {
        $saramDigitos = preg_replace('/[^0-9]/', '', $saram);
        $saramFormatado = $saram;
        
        // Padrões de SARAM no PDF: com traço (393068-8), com ponto, ou dígitos diretos
        $padroesSaram = [];
        if (strlen($saramDigitos) >= 6) {
            $padroesSaram[] = normalizarTextoBusca($saramFormatado);
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
                    $ocorrencias[] = [
                        'tipo' => 'militar',
                        'titulo' => "Citação do militar {$nomeFormatado}",
                        'termo_encontrado' => "SARAM {$saram}",
                        'militar_nome' => $nomeCompleto,
                        'militar_guerra' => $nomeFormatado,
                        'militar_saram' => $saram,
                        'militar_secao' => $secao,
                        'contexto' => $contexto
                    ];
                }
                break; // Evita duplicar se casou em múltiplos formatos de SARAM
            }
        }
    }

    // 2. Busca por Nome Completo do militar
    if (!empty($nomeCompleto) && mb_strlen($nomeCompleto) >= 8) {
        $nomeNorm = normalizarTextoBusca($nomeCompleto);
        $pos = strpos($pdfTextNorm, $nomeNorm);
        
        if ($pos !== false) {
            $contexto = extrairContextoAto($pos, $pdfTextBruto, 280);
            $hash = md5($m['id'] . '_NOME_' . substr($contexto, 0, 80));
            
            if (!isset($ocorrenciasHashes[$hash]) && strlen($contexto) > 20) {
                $ocorrenciasHashes[$hash] = true;
                $ocorrencias[] = [
                    'tipo' => 'militar',
                    'titulo' => "Citação do militar {$nomeFormatado}",
                    'termo_encontrado' => $nomeCompleto,
                    'militar_nome' => $nomeCompleto,
                    'militar_guerra' => $nomeFormatado,
                    'militar_saram' => $saram ?: 'Não inf.',
                    'militar_secao' => $secao,
                    'contexto' => $contexto
                ];
            }
        }
    }
}

// Resumo textual
$totalOcorrencias = count($ocorrencias);
if ($totalOcorrencias > 0) {
    $resumo = "Encontrada" . ($totalOcorrencias > 1 ? "s {$totalOcorrencias} ocorrências" : " 1 ocorrência") . " para o efetivo/OM do DTCEA-SJ no BCA nº {$bcaNumero}.";
} else {
    $resumo = "Nenhuma ocorrência encontrada para os militares cadastrados ou para o termo DTCEA-SJ no BCA nº " . ($bcaNumero ?: 'recente') . ($bcaData ? " de {$bcaData}" : "") . ".";
}

echo json_encode([
    'success' => true,
    'arquivo' => $latestPdfNome,
    'bca_numero' => $bcaNumero,
    'bca_data' => $bcaData,
    'total_ocorrencias' => $totalOcorrencias,
    'total_militares_monitorados' => count($militaresMonitorados),
    'hora_leitura' => date('H:i:s'),
    'sync_cendoc' => $syncCendoc,
    'resumo' => $resumo,
    'ocorrencias' => $ocorrencias
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
