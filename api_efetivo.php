<?php
// api_efetivo.php
// Endpoint de integração em tempo real entre o Controle de Efetivo (ctr_efetivo) e o Painel SECSA

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

date_default_timezone_set('America/Sao_Paulo');

// Função auxiliar para carregar .env do projeto ctr_efetivo se existir
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

// Tenta carregar as configurações do .env do ctr_efetivo
carregarEnv(__DIR__ . '/../ctr_efetivo/public/.env');

$db_host = getenv('DB_HOST') ?: '127.0.0.1';
$db_port = getenv('DB_PORT') ?: '3306';
$db_name = getenv('DB_DATABASE') ?: 'efetivosj';
$db_user = getenv('DB_USERNAME') ?: 'root';
$db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '';

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

    // Filtro para incluir pessoal do expediente e excluir operacionais / sem seção
    $filterExpediente = "
        AND u.escala = 0 
        AND u.section_id IS NOT NULL 
        AND u.section_id > 1
        AND s.id IS NOT NULL
        AND s.id NOT IN (1, 8, 10, 11)
        AND TRIM(COALESCE(s.`$secCol`, '')) NOT IN ('Torre de Controle', 'TORRE DE CONTROLE', 'TWR', 'EMS', 'EMS1', 'EMS-1 / CMA-2', 'Sala AIS', 'SALA AIS', 'AIS', 'Sem Seção', '')
    ";

    // 1. Total Geral do Efetivo do Expediente
    $stmtGeral = $db->query("
        SELECT COUNT(u.id) as total 
        FROM users u 
        JOIN `$secTable` s ON u.section_id = s.id 
        WHERE u.deleted_at IS NULL $filterExpediente
    ");
    $totalEfetivo = (int)($stmtGeral->fetch()['total'] ?? 0);

    // 2. Presença Geral do dia Selecionado
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
    $stmtPresenca->execute([$dataConsulta]);
    $stats = $stmtPresenca->fetch();

    $presentes = (int)($stats['presentes'] ?? 0);
    $ausentes = (int)($stats['ausentes'] ?? 0);
    $ferias = (int)($stats['ferias'] ?? 0);
    $dm = (int)($stats['dm'] ?? 0);
    $afastados = (int)($stats['afastados'] ?? 0);
    $totalRespondido = (int)($stats['total_respondido'] ?? 0);

    // Taxa de prontidão: se houver chamadas respondidas, calcula em relação aos respondidos ou total
    $taxaPresenca = $totalRespondido > 0 ? round(($presentes / $totalRespondido) * 100, 1) : 0;
    $taxaProntidaoTotal = $totalEfetivo > 0 ? round(($presentes / $totalEfetivo) * 100, 1) : 0;

    // 3. Detalhamento por Seção
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
        GROUP BY s.id, secao
        ORDER BY secao ASC
    ");
    $stmtSecoes->execute([$dataConsulta]);
    $secoesRaw = $stmtSecoes->fetchAll();

    $secoes = [];
    foreach ($secoesRaw as $s) {
        $tot = (int)$s['total_secao'];
        $pres = (int)$s['presentes_secao'];
        $perc = $tot > 0 ? round(($pres / $tot) * 100) : 0;
        $secoes[] = [
            'id' => (int)$s['secao_id'],
            'secao' => $s['secao'],
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

    // 4. Militares Afastados / Condições Especiais Hoje
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
    $stmtAfastados->execute([$dataConsulta]);
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
            'nome_completo' => $m['name'],
            'nome_formatado' => formatarMilitar($m),
            'grade' => $m['grade'] ?? '',
            'war_name' => $m['war_name'] ?? '',
            'saram' => $m['saram'] ?? '',
            'secao' => $m['secao'],
            'status' => $st,
            'status_label' => $stInfo['label'],
            'status_tipo' => $stInfo['tipo'],
            'status_class' => $stInfo['class']
        ];
    }

    // 5. Lista Geral de Militares do Expediente (com status do dia) para visualização rápida
    $stmtTodos = $db->prepare("
        SELECT 
            u.id, u.name, u.war_name, u.grade, u.saram,
            s.`$secCol` as secao,
            COALESCE(p.status, 'SEM_CHAMADA') as status
        FROM users u
        JOIN `$secTable` s ON u.section_id = s.id
        LEFT JOIN presencas p ON u.id = p.militar_id AND p.data = ?
        WHERE u.deleted_at IS NULL 
          $filterExpediente
        ORDER BY s.`$secCol` ASC, u.grade DESC, u.name ASC
    ");
    $stmtTodos->execute([$dataConsulta]);
    $todosRaw = $stmtTodos->fetchAll();

    $efetivoDetalhado = [];
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
            'secao' => $m['secao'],
            'status' => $st,
            'status_label' => $stInfo['label'],
            'status_class' => $stInfo['class']
        ];
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
            'taxa_prontidao_total' => $taxaProntidaoTotal
        ],
        'secoes' => $secoes,
        'militares_afastados' => $militaresAfastados,
        'efetivo_detalhado' => $efetivoDetalhado
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao consultar banco de dados: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
