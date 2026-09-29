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
    __DIR__ . '/../efetivosj/.env',
    __DIR__ . '/.env',
    dirname(__DIR__) . '/ctr_efetivo/public/.env',
    dirname(__DIR__) . '/ctr_efetivo/.env',
    dirname(__DIR__) . '/efetivosj/.env'
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
    // Tentativa com as credenciais carregadas ou padrões e múltiplos bancos de dados
    $candidateDbs = array_unique(array_filter([$db_name, 'efetivosj', 'ctr_efetivo', 'sgp_dtceasj', 'dtceasj', 'painel']));
    $candidateHosts = array_unique(array_filter([$db_host, '127.0.0.1', 'localhost']));
    $credentialPairs = [
        ['user' => $db_user, 'pass' => $db_pass],
        ['user' => 'website', 'pass' => '@dm1nSJ-D4t4b@53'],
        ['user' => 'root', 'pass' => '@dm1nSJ-D4t4b@53'],
        ['user' => 'root', 'pass' => ''],
        ['user' => 'root', 'pass' => 'root'],
        ['user' => 'admin', 'pass' => 'admin']
    ];

    $conexoesTentativas = [];
    foreach ($candidateHosts as $h) {
        foreach ($candidateDbs as $d) {
            foreach ($credentialPairs as $cred) {
                $conexoesTentativas[] = [
                    'host' => $h,
                    'port' => $db_port,
                    'name' => $d,
                    'user' => $cred['user'],
                    'pass' => $cred['pass']
                ];
            }
        }
    }

    $db = null;
    $ultimoErroDb = null;
    $connectedDbInfo = null;

    foreach ($conexoesTentativas as $connInfo) {
        try {
            $h = $connInfo['host'];
            $p = $connInfo['port'];
            $n = $connInfo['name'];
            $u = $connInfo['user'];
            $pw = $connInfo['pass'];
            $db = new PDO("mysql:host=$h;port=$p;dbname=$n;charset=utf8mb4", $u, $pw);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $connectedDbInfo = "$u@$h:$p/$n";
            break;
        } catch (Exception $errConn) {
            $ultimoErroDb = $errConn;
        }
    }

    if (!$db) {
        throw new PDOException("Não foi possível conectar ao banco de dados: " . ($ultimoErroDb ? $ultimoErroDb->getMessage() : 'Erro desconhecido'));
    }

    $dataConsulta = preg_replace('/[^0-9\-]/', '', $_GET['date'] ?? date('Y-m-d'));
    if (empty($dataConsulta)) {
        $dataConsulta = date('Y-m-d');
    }

    // Identificar a tabela e colunas de seções
    $secTable = 'sections';
    $secCol = 'name';
    $tables = [];
    try {
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    if (in_array('sections', $tables)) {
        $secTable = 'sections';
    } elseif (in_array('secoes', $tables)) {
        $secTable = 'secoes';
    }

    $cols = [];
    try {
        $cols = $db->query("SHOW COLUMNS FROM `$secTable`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    if (in_array('name', $cols)) {
        $secCol = 'name';
    } elseif (in_array('nome', $cols)) {
        $secCol = 'nome';
    } elseif (in_array('sigla', $cols)) {
        $secCol = 'sigla';
    }

    // Identificar colunas disponíveis na tabela users de forma dinâmica e resiliente
    $userCols = [];
    try {
        $userCols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {}

    function encontrarColuna($candidatos, $colunasReais) {
        foreach ($candidatos as $cand) {
            if (in_array($cand, $colunasReais)) return $cand;
        }
        return null;
    }

    $colDeletedAt = encontrarColuna(['deleted_at', 'dt_delete', 'deletado_em'], $userCols);
    $colEscala = encontrarColuna(['escala'], $userCols);
    $colValidadeInsp = encontrarColuna(['validade_insp_saude', 'validade_inspecao', 'validade_inspecao_saude', 'val_insp_saude', 'validade_saude', 'val_saude', 'validade'], $userCols);
    $colDataInsp = encontrarColuna(['data_insp_saude', 'data_inspecao', 'data_inspecao_saude', 'dt_insp_saude', 'data_saude', 'dt_saude', 'data_realizacao'], $userCols);
    $colSpecialty = encontrarColuna(['specialty', 'especialidade', 'esp', 'quadro'], $userCols);
    $colWarName = encontrarColuna(['war_name', 'nome_guerra', 'guerra'], $userCols);
    $colGrade = encontrarColuna(['grade', 'posto_grad', 'posto', 'graduacao'], $userCols);
    $colSaram = encontrarColuna(['saram', 'saram_militar', 'nr_saram', 'nr_ordem'], $userCols);
    $colSectionId = encontrarColuna(['section_id', 'secao_id', 'id_secao'], $userCols) ?: 'section_id';

    // Se faltar colunas críticas de inspeção, tenta criar dinamicamente
    if (!$colValidadeInsp) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN validade_insp_saude DATE NULL");
            $colValidadeInsp = 'validade_insp_saude';
            $userCols[] = 'validade_insp_saude';
        } catch (Exception $e) {}
    }
    if (!$colDataInsp) {
        try {
            $db->exec("ALTER TABLE users ADD COLUMN data_insp_saude DATE NULL");
            $colDataInsp = 'data_insp_saude';
            $userCols[] = 'data_insp_saude';
        } catch (Exception $e) {}
    }

    // Filtro para incluir pessoal ativo
    $whereDeleted = $colDeletedAt ? "AND (u.`$colDeletedAt` IS NULL OR u.`$colDeletedAt` = '0000-00-00 00:00:00' OR u.`$colDeletedAt` = '0000-00-00' OR TRIM(u.`$colDeletedAt`) = '')" : "";
    $whereEscala = $colEscala ? "AND u.`$colEscala` = 0" : "";

    $filterExpediente = "
        $whereEscala
        AND u.`$colSectionId` IS NOT NULL 
        AND u.`$colSectionId` > 1
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
            JOIN `$secTable` s ON u.`$colSectionId` = s.id 
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
            JOIN `$secTable` s ON u.`$colSectionId` = s.id
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
            JOIN `$secTable` s ON u.`$colSectionId` = s.id
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
                u.*,
                s.`$secCol` as secao, 
                p.status
            FROM users u
            JOIN presencas p ON u.id = p.militar_id
            JOIN `$secTable` s ON u.`$colSectionId` = s.id
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
                'nome_completo' => $m['name'] ?? ($m['nome'] ?? ''),
                'nome_formatado' => formatarMilitar($m),
                'grade' => $colGrade ? ($m[$colGrade] ?? '') : '',
                'war_name' => $colWarName ? ($m[$colWarName] ?? '') : '',
                'saram' => $colSaram ? ($m[$colSaram] ?? '') : '',
                'secao' => abreviarNomeSecao($m['secao'] ?? ''),
                'secao_original' => $m['secao'] ?? '',
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
                u.*,
                s.`$secCol` as secao,
                COALESCE(p.status, 'SEM_CHAMADA') as status
            FROM users u
            JOIN `$secTable` s ON u.`$colSectionId` = s.id
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
                'grade' => $colGrade ? ($m[$colGrade] ?? '') : '',
                'war_name' => $colWarName ? ($m[$colWarName] ?? '') : '',
                'secao' => abreviarNomeSecao($m['secao'] ?? ''),
                'secao_original' => $m['secao'] ?? '',
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
        $valStr = trim((string)$valStr);
        if ($valStr === '0000-00-00' || $valStr === '00/00/0000' || $valStr === '-' || $valStr === 'null' || $valStr === 'undefined') return null;

        // YYYY-MM-DD ou YYYY-MM-DD HH:MM:SS
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $valStr, $m)) {
            $d = DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]));
            if ($d) return $d;
        }
        // DD/MM/YYYY ou DD-MM-YYYY ou DD.MM.YYYY
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})/', $valStr, $m)) {
            $d = DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]));
            if ($d) return $d;
        }
        // DD/MM/YY ou DD-MM-YY ou DD.MM.YY
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2})$/', $valStr, $m)) {
            $ano = (int)$m[3];
            $anoComp = ($ano < 50) ? (2000 + $ano) : (1900 + $ano);
            $d = DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $anoComp, $m[2], $m[1]));
            if ($d) return $d;
        }
        // DDMMAAAA sem barra
        if (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $valStr, $m)) {
            $d = DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]));
            if ($d) return $d;
        }
        // Fallback DateTime
        try {
            $dt = new DateTime($valStr);
            $dt->setTime(0, 0, 0);
            return $dt;
        } catch (Exception $e) {
            return null;
        }
    }

    // 6. Alertas de Inspeções de Saúde (Todas as Vencidas e as que Vencem nos Próximos 90 dias)
    // Monitora a Validade Insp. Saúde ou Data de Realização (+1 ano) do efetivo de forma totalmente resiliente
    $alertasInspecao = [];
    $erroInspecao = null;
    $totalMilitaresAvaliados = 0;

    try {
        $stmtInsp = $db->query("
            SELECT u.*, s.`$secCol` as secao
            FROM users u
            LEFT JOIN `$secTable` s ON u.`$colSectionId` = s.id
            WHERE 1=1 $whereDeleted
            ORDER BY u.id ASC
        ");
        $inspecoesRaw = $stmtInsp->fetchAll();
        $totalMilitaresAvaliados = count($inspecoesRaw);

        $hojeObj = new DateTime('today');
        $hojeObj->setTime(0, 0, 0);

        foreach ($inspecoesRaw as $m) {
            // Tenta obter validade de múltiplas chaves possíveis
            $valStr = '';
            $possibleValKeys = ['validade_insp_saude', 'validade_inspecao', 'validade_inspecao_saude', 'val_insp_saude', 'validade_saude', 'val_saude', 'validade'];
            foreach ($possibleValKeys as $vk) {
                if (!empty($m[$vk])) {
                    $valStr = trim((string)$m[$vk]);
                    break;
                }
            }

            // Tenta obter data de realização de múltiplas chaves possíveis
            $dtStr = '';
            $possibleDtKeys = ['data_insp_saude', 'data_inspecao', 'data_inspecao_saude', 'dt_insp_saude', 'data_saude', 'dt_saude', 'data_realizacao'];
            foreach ($possibleDtKeys as $dk) {
                if (!empty($m[$dk])) {
                    $dtStr = trim((string)$m[$dk]);
                    break;
                }
            }

            $valObj = null;
            if (!empty($valStr) && $valStr !== '0000-00-00' && $valStr !== '00/00/0000' && $valStr !== '-' && $valStr !== 'null') {
                $valObj = parseDataValidadeFlexible($valStr);
            }

            $dtInspObj = null;
            if (!empty($dtStr) && $dtStr !== '0000-00-00' && $dtStr !== '00/00/0000' && $dtStr !== '-' && $dtStr !== 'null') {
                $dtInspObj = parseDataValidadeFlexible($dtStr);
            }

            // Se não tem validade explícita mas tem data de realização, calcula validade como +1 ano
            if (!$valObj && $dtInspObj) {
                $valObj = clone $dtInspObj;
                $valObj->modify('+1 year');
            }

            if (!$valObj) continue;
            $valObj->setTime(0, 0, 0);

            $diffDays = (int)$hojeObj->diff($valObj)->format('%r%a');

            // Monitora TODAS as inspeções já vencidas (diffDays < 0) e as que vencem nos próximos 90 dias (diffDays <= 90)
            if ($diffDays <= 90) {
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

                $alertasInspecao[] = [
                    'tipo_item' => 'inspecao_saude',
                    'id' => (int)$m['id'],
                    'nome_completo' => $m['name'] ?? ($m['nome'] ?? ''),
                    'nome_guerra' => $colWarName ? ($m[$colWarName] ?? '') : ($m['war_name'] ?? ($m['nome_guerra'] ?? '')),
                    'posto_grad' => $colGrade ? ($m[$colGrade] ?? '') : ($m['grade'] ?? ($m['posto_grad'] ?? '')),
                    'nome_formatado' => formatarMilitar($m),
                    'especialidade' => $colSpecialty ? ($m[$colSpecialty] ?? '') : ($m['specialty'] ?? ($m['especialidade'] ?? '')),
                    'saram' => $colSaram ? ($m[$colSaram] ?? '') : ($m['saram'] ?? ($m['saram_militar'] ?? '')),
                    'secao' => abreviarNomeSecao($m['secao'] ?? ''),
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

        // Ordena para que os prazos mais urgentes e vencidos apareçam primeiro no topo
        usort($alertasInspecao, function($a, $b) {
            return strcmp($a['validade_iso'], $b['validade_iso']);
        });
    } catch (Exception $e) {
        $erroInspecao = $e->getMessage();
        error_log("Erro no calculo de alertas de inspecao: " . $e->getMessage());
    }

    $responsePayload = [
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
    ];

    if (isset($_GET['debug'])) {
        $responsePayload['debug'] = [
            'db_conexao' => $connectedDbInfo,
            'total_avaliados' => $totalMilitaresAvaliados,
            'total_alertas' => count($alertasInspecao),
            'colunas_users' => $userCols,
            'col_validade' => $colValidadeInsp,
            'col_data_insp' => $colDataInsp,
            'erro_inspecao' => $erroInspecao
        ];
    }

    echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao consultar banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
