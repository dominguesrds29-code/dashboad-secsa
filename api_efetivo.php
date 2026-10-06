<?php
// api_efetivo.php
// Endpoint oficial de integração em tempo real entre CTR_EFETIVO e Painel SECSA
// 100% Espelhado com as consultas e conexões do ctr_efetivo/public/dashboard.php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

date_default_timezone_set('America/Sao_Paulo');

// 1. CARREGAR CONEXÃO E CONFIGURAÇÕES DO CTR_EFETIVO
$configLoaded = false;
$possiveisConfig = [
    __DIR__ . '/../ctr_efetivo/public/config.php',
    __DIR__ . '/../ctr_efetivo/config.php',
    dirname(__DIR__) . '/ctr_efetivo/public/config.php',
    dirname(__DIR__) . '/ctr_efetivo/config.php'
];

foreach ($possiveisConfig as $cfgPath) {
    if (file_exists($cfgPath)) {
        require_once $cfgPath;
        $configLoaded = true;
        break;
    }
}

// Fallback caso config.php não seja encontrado diretamente
if (!$configLoaded || !isset($db)) {
    function loadEnvFallback($path) {
        if (!file_exists($path)) return;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim(trim($parts[1]), "\"'");
                putenv("$name=$value");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    $possiveisEnv = [
        __DIR__ . '/../ctr_efetivo/public/.env',
        __DIR__ . '/../ctr_efetivo/.env',
        __DIR__ . '/.env',
        dirname(__DIR__) . '/ctr_efetivo/public/.env',
        dirname(__DIR__) . '/ctr_efetivo/.env'
    ];
    foreach ($possiveisEnv as $envPath) {
        if (file_exists($envPath)) {
            loadEnvFallback($envPath);
            break;
        }
    }

    $db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? '127.0.0.1');
    $db_port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? '3306');
    $db_name = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? 'efetivosj');
    $db_user = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? 'root');
    $db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($_ENV['DB_PASSWORD'] ?? '');

    try {
        $db = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            'success' => false,
            'error' => 'Erro de conexão com o banco MySQL: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!function_exists('getSecaoInfo')) {
        function getSecaoInfo($db) {
            $table = 'sections';
            $nameCol = 'name';
            $codeCol = 'code';
            try {
                $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('sections', $tables)) {
                    $table = 'sections';
                } elseif (in_array('secoes', $tables)) {
                    $table = 'secoes';
                }
                $cols = $db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('name', $cols)) $nameCol = 'name';
                elseif (in_array('nome', $cols)) $nameCol = 'nome';
                elseif (in_array('sigla', $cols)) $nameCol = 'sigla';
                if (in_array('code', $cols)) $codeCol = 'code';
                elseif (in_array('sigla', $cols)) $codeCol = 'sigla';
            } catch (Exception $e) {}
            return ['table' => $table, 'name_col' => $nameCol, 'code_col' => $codeCol];
        }
    }

    if (!function_exists('formatarNomeMilitar')) {
        function formatarNomeMilitar($m) {
            if (!$m) return '';
            $posto = '';
            $gradeField = $m['grade'] ?? ($m['posto_grad'] ?? '');
            if (!empty($gradeField) && strtoupper(trim($gradeField)) !== 'MILITAR') {
                $posto = trim($gradeField) . ' ';
            }
            $nomeGuerra = $m['war_name'] ?? ($m['nome_guerra'] ?? '');
            if (!empty($nomeGuerra) && trim($nomeGuerra) !== '-' && trim($nomeGuerra) !== '') {
                $nomeStr = trim($nomeGuerra);
            } else {
                $nomeStr = trim($m['name'] ?? ($m['nome'] ?? ''));
            }
            return trim($posto . $nomeStr);
        }
    }
}

$statusMap = [
    'P'   => ['label' => 'Presente',          'tipo' => 'presente',  'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'EA'  => ['label' => 'Exp. Administrativo', 'tipo' => 'presente',  'class' => 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'HO'  => ['label' => 'Home Office',       'tipo' => 'presente',  'class' => 'bg-teal-50 text-teal-700 border-teal-200'],
    'O'   => ['label' => 'Operacional',       'tipo' => 'presente',  'class' => 'bg-blue-50 text-blue-700 border-blue-200'],
    'A'   => ['label' => 'Ausente',           'tipo' => 'ausente',   'class' => 'bg-rose-50 text-rose-700 border-rose-200'],
    'PA'  => ['label' => 'Falert A',          'tipo' => 'ausente',   'class' => 'bg-rose-50 text-rose-700 border-rose-200'],
    'PB'  => ['label' => 'Falert B',          'tipo' => 'ausente',   'class' => 'bg-rose-50 text-rose-700 border-rose-200'],
    'F'   => ['label' => 'Férias',            'tipo' => 'ferias',    'class' => 'bg-amber-50 text-amber-700 border-amber-200'],
    'DM'  => ['label' => 'Dispensa Médica',   'tipo' => 'saude',     'class' => 'bg-rose-50 text-rose-700 border-rose-200'],
    'INS' => ['label' => 'Instalação',        'tipo' => 'saude',     'class' => 'bg-purple-50 text-purple-700 border-purple-200'],
    'LPM' => ['label' => 'Licença Própria',   'tipo' => 'saude',     'class' => 'bg-rose-50 text-rose-700 border-rose-200'],
    'D'   => ['label' => 'Dispensado',        'tipo' => 'afastado',  'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
    'DP'  => ['label' => 'Dispensa Parcial',  'tipo' => 'afastado',  'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
    'C'   => ['label' => 'Curso',             'tipo' => 'afastado',  'class' => 'bg-blue-50 text-blue-700 border-blue-200'],
    'M'   => ['label' => 'Missão',            'tipo' => 'afastado',  'class' => 'bg-indigo-50 text-indigo-700 border-indigo-200'],
    'SV'  => ['label' => 'Serviço',           'tipo' => 'servico',   'class' => 'bg-cyan-50 text-cyan-700 border-cyan-200'],
    'SSV' => ['label' => 'Saindo de Serviço', 'tipo' => 'servico',   'class' => 'bg-cyan-50 text-cyan-700 border-cyan-200'],
    'FR'  => ['label' => 'Feriado',           'tipo' => 'afastado',  'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
    'FM'  => ['label' => 'Formatura',         'tipo' => 'afastado',  'class' => 'bg-blue-50 text-blue-700 border-blue-200'],
    'FS'  => ['label' => 'Folga Sobreaviso',  'tipo' => 'afastado',  'class' => 'bg-slate-100 text-slate-700 border-slate-200']
];

function abreviarNomeSecao($secao) {
    if (!$secao) return '';
    $trimmed = trim($secao);
    $upper = mb_strtoupper($trimmed, 'UTF-8');
    if ($upper === 'SECRETARIA ADMINISTRATIVA') return 'SEC. ADMINISTRATIVA';
    if ($upper === 'SECRETARIA OPERACIONAL') return 'SEC. OPERACIONAL';
    $replaced = preg_replace('/^SECRETARIA\s+ADMINISTRATIVA\b/i', 'SEC. ADMINISTRATIVA', $trimmed);
    $replaced = preg_replace('/^SECRETARIA\s+OPERACIONAL\b/i', 'SEC. OPERACIONAL', $replaced);
    return $replaced;
}

function parseDataValidadeFlex($dataStr) {
    if (empty($dataStr)) return null;
    $dataStr = trim((string)$dataStr);
    if ($dataStr === '0000-00-00' || $dataStr === '00/00/0000' || $dataStr === '-' || $dataStr === 'null') return null;
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $dataStr, $m)) {
        return new DateTime(sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]));
    }
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})/', $dataStr, $m)) {
        return new DateTime(sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]));
    }
    return null;
}

try {
    $selectedDate = preg_replace('/[^0-9\-]/', '', $_GET['date'] ?? date('Y-m-d'));
    if (empty($selectedDate)) {
        $selectedDate = date('Y-m-d');
    }

    $sec = getSecaoInfo($db);
    $secTable = $sec['table'];
    $secCol = $sec['name_col'];

    // Filtro Oficial do Expediente (IDÊNTICO ao dashboard.php do ctr_efetivo)
    $filterExpediente = "
        AND u.escala = 0 
        AND u.section_id IS NOT NULL 
        AND u.section_id > 1
        AND s.id IS NOT NULL
        AND s.id NOT IN (1, 8, 10, 11)
        AND TRIM(COALESCE(s.`$secCol`, '')) NOT IN ('Torre de Controle', 'TORRE DE CONTROLE', 'TWR', 'EMS', 'EMS1', 'EMS-1 / CMA-2', 'Sala AIS', 'SALA AIS', 'AIS', 'Sem Seção', '')
    ";

    // 1. Total do Efetivo do Expediente
    $stmtGeral = $db->query("
        SELECT COUNT(u.id) as total 
        FROM users u 
        JOIN `$secTable` s ON u.section_id = s.id 
        WHERE u.deleted_at IS NULL $filterExpediente
    ");
    $totalEfetivo = (int)($stmtGeral->fetch()['total'] ?? 0);

    // 2. Presença Geral do dia Selecionado (Apenas Expediente)
    $stmtPresenca = $db->prepare("
        SELECT 
            SUM(CASE WHEN p.status IN ('P', 'EA', 'HO', 'O') THEN 1 ELSE 0 END) as presentes,
            SUM(CASE WHEN p.status IN ('A', 'PA', 'PB') THEN 1 ELSE 0 END) as ausentes,
            SUM(CASE WHEN p.status = 'F' THEN 1 ELSE 0 END) as ferias,
            SUM(CASE WHEN p.status IN ('DM', 'INS', 'LPM', 'D', 'DP') THEN 1 ELSE 0 END) as dm,
            SUM(CASE WHEN p.status IN ('C', 'M') THEN 1 ELSE 0 END) as afastados,
            SUM(CASE WHEN p.status IS NOT NULL THEN 1 ELSE 0 END) as total_respondido
        FROM presencas p
        JOIN users u ON p.militar_id = u.id
        JOIN `$secTable` s ON u.section_id = s.id
        WHERE p.data = ? 
          AND u.deleted_at IS NULL 
          $filterExpediente
    ");
    $stmtPresenca->execute([$selectedDate]);
    $stats = $stmtPresenca->fetch();

    $presentes = (int)($stats['presentes'] ?? 0);
    $ausentes = (int)($stats['ausentes'] ?? 0);
    $ferias = (int)($stats['ferias'] ?? 0);
    $dm = (int)($stats['dm'] ?? 0);
    $afastados = (int)($stats['afastados'] ?? 0);
    $totalRespondido = (int)($stats['total_respondido'] ?? 0);

    $taxaPresenca = $totalRespondido > 0 ? round(($presentes / $totalRespondido) * 100, 1) : 0;
    $taxaProntidaoTotal = $totalEfetivo > 0 ? round(($presentes / $totalEfetivo) * 100, 1) : 0;

    // 3. Detalhamento por Seção (Apenas Expediente)
    $stmtSecoes = $db->prepare("
        SELECT 
            s.id as secao_id,
            s.`$secCol` as secao,
            COUNT(u.id) as total_secao,
            SUM(CASE WHEN p.status IN ('P', 'EA', 'HO', 'O') THEN 1 ELSE 0 END) as presentes_secao,
            SUM(CASE WHEN p.status IN ('A', 'PA', 'PB') THEN 1 ELSE 0 END) as ausentes_secao,
            SUM(CASE WHEN p.status = 'F' THEN 1 ELSE 0 END) as ferias_secao,
            SUM(CASE WHEN p.status IN ('DM', 'INS', 'LPM', 'D', 'DP') THEN 1 ELSE 0 END) as dm_secao,
            SUM(CASE WHEN p.status IN ('C', 'M') THEN 1 ELSE 0 END) as afastados_secao,
            SUM(CASE WHEN p.status IS NOT NULL THEN 1 ELSE 0 END) as respondidos_secao
        FROM users u
        JOIN `$secTable` s ON u.section_id = s.id
        LEFT JOIN presencas p ON u.id = p.militar_id AND p.data = ?
        WHERE u.deleted_at IS NULL 
          $filterExpediente
        GROUP BY u.section_id, s.`$secCol`, s.id
        ORDER BY secao ASC
    ");
    $stmtSecoes->execute([$selectedDate]);
    $secoesRaw = $stmtSecoes->fetchAll();

    $secoes = [];
    foreach ($secoesRaw as $s) {
        $tot = (int)$s['total_secao'];
        $pres = (int)$s['presentes_secao'];
        $perc = $tot > 0 ? round(($pres / $tot) * 100) : 0;
        $secoes[] = [
            'id' => (int)$s['secao_id'],
            'secao' => abreviarNomeSecao($s['secao']),
            'secao_original' => $s['secao'],
            'total' => $tot,
            'presentes' => $pres,
            'ausentes' => (int)$s['ausentes_secao'],
            'ferias' => (int)$s['ferias_secao'],
            'dm' => (int)$s['dm_secao'],
            'afastados' => (int)$s['afastados_secao'],
            'respondidos' => (int)$s['respondidos_secao'],
            'percentual' => $perc
        ];
    }

    // 4. Militares Afastados / Condições Especiais Hoje (Apenas Expediente)
    $stmtAfastados = $db->prepare("
        SELECT 
            u.id, u.name, u.war_name, u.grade, u.saram,
            s.`$secCol` as secao, 
            p.status
        FROM users u
        JOIN presencas p ON u.id = p.militar_id
        JOIN `$secTable` s ON u.section_id = s.id
        WHERE p.data = ? 
          AND p.status NOT IN ('P', 'EA', 'HO', 'O')
          AND u.deleted_at IS NULL 
          $filterExpediente
        ORDER BY p.status ASC, secao ASC, u.name ASC
    ");
    $stmtAfastados->execute([$selectedDate]);
    $afastadosRaw = $stmtAfastados->fetchAll();

    $militaresAfastados = [];
    foreach ($afastadosRaw as $m) {
        $st = $m['status'];
        $stInfo = $statusMap[$st] ?? [
            'label' => $st,
            'tipo'  => 'outro',
            'class' => 'bg-slate-100 text-slate-700 border-slate-200'
        ];

        $militaresAfastados[] = [
            'id' => (int)$m['id'],
            'nome_completo' => $m['name'] ?? '',
            'nome_formatado' => formatarNomeMilitar($m),
            'grade' => $m['grade'] ?? '',
            'war_name' => $m['war_name'] ?? '',
            'saram' => $m['saram'] ?? '',
            'secao' => abreviarNomeSecao($m['secao'] ?? ''),
            'secao_original' => $m['secao'] ?? '',
            'status' => $st,
            'status_label' => $stInfo['label'],
            'status_tipo' => $stInfo['tipo'],
            'status_class' => $stInfo['class']
        ];
    }

    // 5. Relação Nominal Completa do Efetivo
    $stmtEfetivo = $db->prepare("
        SELECT 
            u.id, u.name, u.war_name, u.grade, u.saram, u.specialty,
            s.`$secCol` as secao, 
            p.status
        FROM users u
        JOIN `$secTable` s ON u.section_id = s.id
        LEFT JOIN presencas p ON u.id = p.militar_id AND p.data = ?
        WHERE u.deleted_at IS NULL 
          $filterExpediente
        ORDER BY secao ASC, u.name ASC
    ");
    $stmtEfetivo->execute([$selectedDate]);
    $efetivoRaw = $stmtEfetivo->fetchAll();

    $efetivoDetalhado = [];
    foreach ($efetivoRaw as $m) {
        $st = $m['status'] ?? 'SEM_CHAMADA';
        $stInfo = $statusMap[$st] ?? [
            'label' => 'Pendente',
            'tipo'  => 'pendente',
            'class' => 'bg-slate-100 text-slate-500 border-slate-200'
        ];

        $efetivoDetalhado[] = [
            'id' => (int)$m['id'],
            'nome_formatado' => formatarNomeMilitar($m),
            'grade' => $m['grade'] ?? '',
            'war_name' => $m['war_name'] ?? '',
            'secao' => abreviarNomeSecao($m['secao'] ?? ''),
            'secao_original' => $m['secao'] ?? '',
            'status' => $st,
            'status_label' => $stInfo['label'],
            'status_class' => $stInfo['class']
        ];
    }

    // 6. Alertas de Inspeção de Saúde
    $alertasInspecao = [];
    $userCols = [];
    try {
        $userCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    $colValidadeInsp = in_array('validade_insp_saude', $userCols) ? 'validade_insp_saude' : null;
    $colDataInsp = in_array('data_insp_saude', $userCols) ? 'data_insp_saude' : null;

    if ($colValidadeInsp || $colDataInsp) {
        try {
            $stmtInsp = $db->query("
                SELECT u.id, u.name, u.war_name, u.grade, u.saram, u.specialty, u.section_id,
                       u.data_insp_saude, u.validade_insp_saude,
                       s.`$secCol` as secao
                FROM users u
                LEFT JOIN `$secTable` s ON u.section_id = s.id
                WHERE u.deleted_at IS NULL
                  AND (u.validade_insp_saude IS NOT NULL OR u.data_insp_saude IS NOT NULL)
            ");
            $inspRaw = $stmtInsp->fetchAll();
            $hojeObj = new DateTime('today');

            foreach ($inspRaw as $m) {
                $valStr = trim((string)($m['validade_insp_saude'] ?? ''));
                $dtStr = trim((string)($m['data_insp_saude'] ?? ''));

                $valObj = parseDataValidadeFlex($valStr);
                $dtInspObj = parseDataValidadeFlex($dtStr);

                if (!$valObj && $dtInspObj) {
                    $valObj = clone $dtInspObj;
                    $valObj->modify('+1 year');
                }

                if (!$valObj) continue;
                $valObj->setTime(0, 0, 0);

                $diffDays = (int)$hojeObj->diff($valObj)->format('%r%a');

                if ($diffDays <= 90) {
                    if ($diffDays < 0) {
                        $statusTipo = 'vencida';
                        $diasVenc = abs($diffDays);
                        $statusClass = 'bg-rose-50/60 border-rose-200';
                        $urgenciaBadgeClass = 'bg-rose-100 text-rose-800 border-rose-200';
                        $urgenciaTexto = $diasVenc === 1 ? "Vencida há 1 dia" : "Vencida há {$diasVenc} dias";
                    } elseif ($diffDays === 0) {
                        $statusTipo = 'hoje';
                        $statusClass = 'bg-rose-100/70 border-rose-300';
                        $urgenciaBadgeClass = 'bg-rose-600 text-white border-rose-700 animate-pulse';
                        $urgenciaTexto = "Vence Hoje!";
                    } elseif ($diffDays <= 30) {
                        $statusTipo = 'critico';
                        $statusClass = 'bg-rose-50/50 border-rose-200';
                        $urgenciaBadgeClass = 'bg-rose-100 text-rose-800 border-rose-200';
                        $urgenciaTexto = "Vence em {$diffDays}d";
                    } elseif ($diffDays <= 60) {
                        $statusTipo = 'alerta';
                        $statusClass = 'bg-amber-50/50 border-amber-200';
                        $urgenciaBadgeClass = 'bg-amber-100 text-amber-800 border-amber-200';
                        $urgenciaTexto = "Vence em {$diffDays}d";
                    } else {
                        $statusTipo = 'aviso';
                        $statusClass = 'bg-sky-50 border-sky-200';
                        $urgenciaBadgeClass = 'bg-sky-100 text-sky-800 border-sky-200';
                        $urgenciaTexto = "Vence em {$diffDays}d";
                    }

                    $alertasInspecao[] = [
                        'tipo_item' => 'inspecao_saude',
                        'id' => (int)$m['id'],
                        'nome_completo' => $m['name'] ?? '',
                        'nome_guerra' => $m['war_name'] ?? '',
                        'posto_grad' => $m['grade'] ?? '',
                        'nome_formatado' => formatarNomeMilitar($m),
                        'especialidade' => $m['specialty'] ?? '',
                        'saram' => $m['saram'] ?? '',
                        'secao' => abreviarNomeSecao($m['secao'] ?? 'DTCEA-SJ'),
                        'secao_original' => $m['secao'] ?? 'DTCEA-SJ',
                        'data_insp_saude' => $dtInspObj ? $dtInspObj->format('d/m/Y') : null,
                        'validade_insp_saude' => $valObj->format('d/m/Y'),
                        'validade_iso' => $valObj->format('Y-m-d'),
                        'dias_restantes' => $diffDays,
                        'status_tipo' => $statusTipo,
                        'status_class' => $statusClass,
                        'urgencia_badge_class' => $urgenciaBadgeClass,
                        'urgencia_texto' => $urgenciaTexto
                    ];
                }
            }

            usort($alertasInspecao, function($a, $b) {
                return $a['dias_restantes'] <=> $b['dias_restantes'];
            });
        } catch (Exception $e) {}
    }

    $responsePayload = [
        'success' => true,
        'timestamp' => time(),
        'data_consulta' => $selectedDate,
        'data_formatada' => date('d/m/Y', strtotime($selectedDate)),
        'hora_atualizacao' => date('H:i:s'),
        'stats' => [
            'total_efetivo' => $totalEfetivo,
            'presentes' => $presentes,
            'ausentes' => $ausentes,
            'ferias' => $ferias,
            'dm' => $dm,
            'afastados' => $afastados,
            'total_respondido' => $totalRespondido,
            'taxa_presenca' => $taxaPresenca,
            'taxa_prontidao_total' => $taxaProntidaoTotal,
            'total_inspecoes_alerta' => count($alertasInspecao)
        ],
        'secoes' => $secoes,
        'militares_afastados' => $militaresAfastados,
        'efetivo_detalhado' => $efetivoDetalhado,
        'alertas_inspecao' => $alertasInspecao
    ];

    if (isset($_GET['debug'])) {
        $responsePayload['debug'] = [
            'config_loaded' => $configLoaded,
            'secTable' => $secTable,
            'secCol' => $secCol,
            'totalEfetivo' => $totalEfetivo,
            'totalSecoes' => count($secoes),
            'totalAfastados' => count($militaresAfastados)
        ];
    }

    echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao consultar banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
