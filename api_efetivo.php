<?php
// api_efetivo.php
// Endpoint de integração em tempo real entre o Controle de Efetivo (ctr_efetivo) e o Painel SECSA

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

date_default_timezone_set('America/Sao_Paulo');

// Função auxiliar para carregar .env de múltiplos caminhos possíveis
function carregarEnv($caminho) {
    if (!file_exists($caminho)) return false;
    $linhas = file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '' || strpos($linha, '#') === 0) continue;
        $partes = explode('=', $linha, 2);
        if (count($partes) === 2) {
            $nome = trim($partes[0]);
            $valor = trim(trim($partes[1]), "\"'");
            putenv("$nome=$valor");
            $_ENV[$nome] = $valor;
            $_SERVER[$nome] = $valor;
        }
    }
    return true;
}

// Procura o .env nos caminhos padrões do ctr_efetivo ou locais
$possiveisEnv = [
    __DIR__ . '/../ctr_efetivo/public/.env',
    __DIR__ . '/../ctr_efetivo/.env',
    __DIR__ . '/.env',
    dirname(__DIR__) . '/ctr_efetivo/public/.env',
    dirname(__DIR__) . '/ctr_efetivo/.env'
];
foreach ($possiveisEnv as $envPath) {
    if (carregarEnv($envPath)) {
        break;
    }
}

$db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? '127.0.0.1'));
$db_port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? '3306'));
$db_name = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ($_SERVER['DB_DATABASE'] ?? 'efetivosj'));
$db_user = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? ($_SERVER['DB_USERNAME'] ?? 'root'));
$db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($_ENV['DB_PASSWORD'] ?? ($_SERVER['DB_PASSWORD'] ?? ''));

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

function formatarMilitar($m) {
    if (!$m) return '';
    $posto = '';
    $gradeField = $m['grade'] ?? $m['posto_grad'] ?? '';
    if (!empty($gradeField) && strtoupper(trim($gradeField)) !== 'MILITAR') {
        $posto = trim($gradeField) . ' ';
    }
    $nomeGuerra = $m['war_name'] ?? $m['nome_guerra'] ?? '';
    if (!empty($nomeGuerra) && trim($nomeGuerra) !== '-' && trim($nomeGuerra) !== '') {
        $nomeStr = trim($nomeGuerra);
    } else {
        $nomeStr = trim($m['name'] ?? $m['nome'] ?? '');
    }
    return trim($posto . $nomeStr);
}

// Abreviar nomes longos de seções exclusivamente para este painel
function abreviarNomeSecao($secao) {
    if (!$secao) return '';
    $trimmed = trim($secao);
    $upper = mb_strtoupper($trimmed, 'UTF-8');

    if ($upper === 'SECRETARIA ADMINISTRATIVA') {
        return 'SEC. ADMINISTRATIVA';
    }
    if ($upper === 'SECRETARIA OPERACIONAL') {
        return 'SEC. OPERACIONAL';
    }

    $replaced = preg_replace('/^SECRETARIA\s+ADMINISTRATIVA\b/i', 'SEC. ADMINISTRATIVA', $trimmed);
    $replaced = preg_replace('/^SECRETARIA\s+OPERACIONAL\b/i', 'SEC. OPERACIONAL', $replaced);
    return $replaced;
}

try {
    $db = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $dataConsulta = preg_replace('/[^0-9\-]/', '', $_GET['date'] ?? date('Y-m-d'));
    if (empty($dataConsulta)) {
        $dataConsulta = date('Y-m-d');
    }

    // Identificar a tabela e colunas de seções
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

    // Identificar colunas disponíveis na tabela users
    $userCols = [];
    try {
        $userCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    $hasDeletedAt = in_array('deleted_at', $userCols);
    $hasEscala = in_array('escala', $userCols);
    $hasValidadeInsp = in_array('validade_insp_saude', $userCols);
    $hasDataInsp = in_array('data_insp_saude', $userCols);
    $hasSpecialty = in_array('specialty', $userCols);
    $hasWarName = in_array('war_name', $userCols);
    $hasGrade = in_array('grade', $userCols);
    $hasSaram = in_array('saram', $userCols);

    // Se faltar colunas críticas de inspeção, tenta criar dinamicamente
    if (!$hasValidadeInsp) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN validade_insp_saude DATE NULL");
            $hasValidadeInsp = true;
        } catch (Exception $e) {}
    }
    if (!$hasDataInsp) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN data_insp_saude DATE NULL");
            $hasDataInsp = true;
        } catch (Exception $e) {}
    }

    // Filtro para incluir pessoal do expediente e excluir operacionais / sem seção
    $whereDeleted = $hasDeletedAt ? "AND u.deleted_at IS NULL" : "";
    $whereEscala = $hasEscala ? "AND u.escala = 0" : "";

    $filterExpediente = "
        $whereEscala
        AND u.section_id IS NOT NULL 
        AND u.section_id > 1
        AND s.id IS NOT NULL
        AND s.id NOT IN (1, 8, 10, 11)
        AND TRIM(COALESCE(s.`$secCol`, '')) NOT IN ('Torre de Controle', 'TORRE DE CONTROLE', 'TWR', 'EMS', 'EMS1', 'EMS-1 / CMA-2', 'Sala AIS', 'SALA AIS', 'AIS', 'Sem Seção', '')
    ";

    // 1. Total Geral do Efetivo do Expediente
    $totalEfetivo = 0;
    try {
        $stmtGeral = $db->query("
            SELECT COUNT(u.id) as total 
            FROM users u 
            JOIN `$secTable` s ON u.section_id = s.id 
            WHERE 1=1 $whereDeleted $filterExpediente
        ");
        $totalEfetivo = (int)($stmtGeral->fetch()['total'] ?? 0);
    } catch (Exception $e) {
        error_log("Erro no calculo do total de efetivo: " . $e->getMessage());
    }

    // 2. Presença Geral do dia Selecionado
    $presentes = 0;
    $ausentes = 0;
    $ferias = 0;
    $dm = 0;
    $afastados = 0;
    $totalRespondido = 0;

    try {
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
              $whereDeleted 
              $filterExpediente
        ");
        $stmtPresenca->execute([$dataConsulta]);
        $stats = $stmtPresenca->fetch();

        $presentes = (int)($stats['presentes'] ?? 0);
        $ausentes = (int)($stats['ausentes'] ?? 0);
        $ferias = (int)($stats['ferias'] ?? 0);
        $dm = (int)($stats['dm'] ?? 0);
        $afastados = (int)($stats['afastados'] ?? 0);
        $totalRespondido = (int)($stats['total_respondido'] ?? 0);
    } catch (Exception $e) {
        error_log("Erro no calculo de presenças: " . $e->getMessage());
    }

    // Taxa de prontidão: se houver chamadas respondidas, calcula em relação aos respondidos ou total
    $taxaPresenca = $totalRespondido > 0 ? round(($presentes / $totalRespondido) * 100, 1) : 0;
    $taxaProntidaoTotal = $totalEfetivo > 0 ? round(($presentes / $totalEfetivo) * 100, 1) : 0;

    // 3. Detalhamento por Seção
    $secoes = [];
    try {
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
            WHERE 1=1 
              $whereDeleted 
              $filterExpediente
            GROUP BY s.id, secao
            ORDER BY secao ASC
        ");
        $stmtSecoes->execute([$dataConsulta]);
        $secoesRaw = $stmtSecoes->fetchAll();

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
    } catch (Exception $e) {
        error_log("Erro no calculo de secoes: " . $e->getMessage());
    }

    // 4. Militares Afastados / Condições Especiais Hoje
    $militaresAfastados = [];
    try {
        $stmtAfastados = $db->prepare("
            SELECT 
                u.id, u.name, 
                " . ($hasWarName ? "u.war_name," : "'' as war_name,") . "
                " . ($hasGrade ? "u.grade," : "'' as grade,") . "
                " . ($hasSaram ? "u.saram," : "'' as saram,") . "
                s.`$secCol` as secao, 
                p.status
            FROM users u
            JOIN presencas p ON u.id = p.militar_id
            JOIN `$secTable` s ON u.section_id = s.id
            WHERE p.data = ? 
              AND p.status NOT IN ('P', 'EA', 'HO', 'O')
              $whereDeleted 
              $filterExpediente
            ORDER BY p.status ASC, secao ASC, u.name ASC
        ");
        $stmtAfastados->execute([$dataConsulta]);
        $afastadosRaw = $stmtAfastados->fetchAll();

        foreach ($afastadosRaw as $m) {
            $st = $m['status'];
            $stInfo = $statusMap[$st] ?? [
                'label' => $st,
                'tipo'  => 'outro',
                'class' => 'bg-slate-100 text-slate-700 border-slate-200'
            ];

            $militaresAfastados[] = [
                'id' => (int)$m['id'],
                'nome_completo' => $m['name'],
                'nome_formatado' => formatarMilitar($m),
                'grade' => $m['grade'] ?? '',
                'war_name' => $m['war_name'] ?? '',
                'saram' => $m['saram'] ?? '',
                'secao' => abreviarNomeSecao($m['secao']),
                'secao_original' => $m['secao'],
                'status' => $st,
                'status_label' => $stInfo['label'],
                'status_tipo' => $stInfo['tipo'],
                'status_class' => $stInfo['class']
            ];
        }
    } catch (Exception $e) {
        error_log("Erro no calculo de afastados: " . $e->getMessage());
    }

    // 5. Lista Geral de Militares do Expediente (com status do dia) para visualização rápida
    $efetivoDetalhado = [];
    try {
        $stmtTodos = $db->prepare("
            SELECT 
                u.id, u.name,
                " . ($hasWarName ? "u.war_name," : "'' as war_name,") . "
                " . ($hasGrade ? "u.grade," : "'' as grade,") . "
                " . ($hasSaram ? "u.saram," : "'' as saram,") . "
                s.`$secCol` as secao,
                COALESCE(p.status, 'SEM_CHAMADA') as status
            FROM users u
            JOIN `$secTable` s ON u.section_id = s.id
            LEFT JOIN presencas p ON u.id = p.militar_id AND p.data = ?
            WHERE 1=1
              $whereDeleted 
              $filterExpediente
            ORDER BY s.`$secCol` ASC, u.name ASC
        ");
        $stmtTodos->execute([$dataConsulta]);
        $todosRaw = $stmtTodos->fetchAll();

        foreach ($todosRaw as $m) {
            $st = $m['status'];
            $stInfo = $statusMap[$st] ?? [
                'label' => $st === 'SEM_CHAMADA' ? 'Pendente' : $st,
                'tipo'  => $st === 'SEM_CHAMADA' ? 'pendente' : 'outro',
                'class' => $st === 'SEM_CHAMADA' ? 'bg-slate-100 text-slate-500 border-slate-200' : 'bg-slate-100 text-slate-700 border-slate-200'
            ];

            $efetivoDetalhado[] = [
                'id' => (int)$m['id'],
                'nome_formatado' => formatarMilitar($m),
                'grade' => $m['grade'] ?? '',
                'war_name' => $m['war_name'] ?? '',
                'secao' => abreviarNomeSecao($m['secao']),
                'secao_original' => $m['secao'],
                'status' => $st,
                'status_label' => $stInfo['label'],
                'status_class' => $stInfo['class']
            ];
        }
    } catch (Exception $e) {
        error_log("Erro no calculo de efetivo detalhado: " . $e->getMessage());
    }

    // Função robusta e resiliente para conversão de datas de inspeção e prazos
    function parseDataValidadeFlexible($valStr) {
        if (empty($valStr)) return null;
        $valStr = trim($valStr);
        if ($valStr === '0000-00-00' || $valStr === '00/00/0000' || $valStr === '-' || $valStr === 'null' || $valStr === 'undefined') return null;

        // YYYY-MM-DD
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $valStr, $m)) {
            return DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]));
        }
        // DD/MM/YYYY ou DD-MM-YYYY ou DD.MM.YYYY
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})/', $valStr, $m)) {
            return DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]));
        }
        // DD/MM/YY
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2})$/', $valStr, $m)) {
            $ano = (int)$m[3];
            $anoComp = ($ano < 50) ? (2000 + $ano) : (1900 + $ano);
            return DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $anoComp, $m[2], $m[1]));
        }
        // DDMMAAAA sem barra
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $valStr, $m)) {
            return DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]));
        }
        // Fallback DateTime
        try {
            return new DateTime($valStr);
        } catch (Exception $e) {
            return null;
        }
    }

    // 6. Alertas de Inspeções de Saúde a Vencer (Próximos 90 dias / Vencidas)
    // Baseado ESTRITAMENTE na Validade Insp. Saúde (validade_insp_saude) configurada no banco efetivosj
    $alertasInspecao = [];
    if ($hasValidadeInsp) {
        try {
            $stmtInsp = $db->query("
                SELECT 
                    u.id, u.name,
                    " . ($hasWarName ? "u.war_name," : "'' as war_name,") . "
                    " . ($hasGrade ? "u.grade," : "'' as grade,") . "
                    " . ($hasSaram ? "u.saram," : "'' as saram,") . "
                    " . ($hasSpecialty ? "u.specialty," : "'' as specialty,") . "
                    " . ($hasDataInsp ? "u.data_insp_saude," : "NULL as data_insp_saude,") . "
                    u.validade_insp_saude,
                    s.`$secCol` as secao
                FROM users u
                LEFT JOIN `$secTable` s ON u.section_id = s.id
                WHERE 1=1
                  $whereDeleted
                  AND u.validade_insp_saude IS NOT NULL 
                  AND TRIM(u.validade_insp_saude) != ''
                  AND u.validade_insp_saude != '0000-00-00'
                  AND u.validade_insp_saude != '00/00/0000'
                ORDER BY u.validade_insp_saude ASC
            ");
            $inspecoesRaw = $stmtInsp->fetchAll();

            $hojeObj = new DateTime('today');

            foreach ($inspecoesRaw as $m) {
                $valStr = trim($m['validade_insp_saude'] ?? '');
                if (empty($valStr) || $valStr === '0000-00-00' || $valStr === '00/00/0000' || $valStr === '-' || $valStr === 'null') {
                    continue;
                }

                $valObj = parseDataValidadeFlexible($valStr);
                if (!$valObj) continue;

                $diffDays = (int)$hojeObj->diff($valObj)->format('%r%a');

                // Filtra militares com inspeção a vencer nos próximos 90 dias ou vencidas (até 365 dias)
                if ($diffDays <= 90 && $diffDays >= -365) {
                    $statusTipo = 'valida';
                    $statusClass = 'bg-blue-50/50 border-blue-200';
                    $urgenciaBadgeClass = 'bg-blue-100 text-blue-800 border-blue-200';
                    $urgenciaTexto = "Vence em {$diffDays}d";

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
                        $statusClass = 'bg-slate-50 border-slate-200';
                        $urgenciaBadgeClass = 'bg-slate-100 text-slate-700 border-slate-200';
                        $urgenciaTexto = "Vence em {$diffDays}d";
                    }

                    $dtInspObj = !empty($m['data_insp_saude']) ? parseDataValidadeFlexible($m['data_insp_saude']) : null;

                    $alertasInspecao[] = [
                        'tipo_item' => 'inspecao_saude',
                        'id' => (int)$m['id'],
                        'nome_completo' => $m['name'],
                        'nome_guerra' => $m['war_name'] ?? '',
                        'posto_grad' => $m['grade'] ?? '',
                        'nome_formatado' => formatarMilitar($m),
                        'especialidade' => $m['specialty'] ?? '',
                        'saram' => $m['saram'] ?? '',
                        'secao' => abreviarNomeSecao($m['secao']),
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

            // Ordena para que os prazos mais urgentes e próximos de vencer apareçam primeiro
            usort($alertasInspecao, function($a, $b) {
                return strcmp($a['validade_iso'], $b['validade_iso']);
            });
        } catch (Exception $e) {
            error_log("Erro no calculo de alertas de inspecao: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'timestamp' => time(),
        'data_consulta' => $dataConsulta,
        'data_formatada' => date('d/m/Y', strtotime($dataConsulta)),
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
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao consultar banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
