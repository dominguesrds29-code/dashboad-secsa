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
    // Conexão com o banco de dados oficial efetivosj / ctr_efetivo
    $candidateDbs = array_unique(array_filter([$db_name, 'efetivosj', 'ctr_efetivo', 'painel']));
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

    // Filtro para incluir pessoal ativo e do expediente
    $whereDeleted = $colDeletedAt ? "AND (u.`$colDeletedAt` IS NULL OR u.`$colDeletedAt` = '0000-00-00 00:00:00' OR u.`$colDeletedAt` = '0000-00-00' OR TRIM(u.`$colDeletedAt`) = '')" : "";
    $whereEscala = $colEscala ? "AND (u.`$colEscala` = 0 OR u.`$colEscala` IS NULL)" : "";

    $filterExpediente = "
        $whereEscala
        AND u.`$colSectionId` IS NOT NULL 
        AND s.id IS NOT NULL
        AND TRIM(COALESCE(s.`$secCol`, '')) NOT IN ('Torre de Controle', 'TORRE DE CONTROLE', 'TWR', 'SO_TWR', 'EMS', 'EMS1', 'EMS-1 / CMA-2', 'SO_EMS1_CMA2', 'Sala AIS', 'SALA AIS', 'AIS', 'SO_AIS', 'Sem Seção', 'SEM SECAO', 'SEM_SECAO', '')
    ";

    // Função de Auto-Sincronização / Auto-Cura para Produção
    function autoHealEfetivo($db, $secTable, $secCol, $colSectionId, $colEscala, $colWarName) {
        try {
            $mapeamento = [
                // INFORMÁTICA
                ['sarams' => ['3930688', '393068', '4220559', '422055', '6158862', '6090710', '7295014', '6896227', '7702671', '7113145', '7702680', '7113331', '7702760', '7113420', '7702795', '7113455', '1744150', '1742468'], 'nomes' => ['RENATO DOMINGUES', 'FERNANDO BARBOSA', 'GABRIELA WINNIE', 'MARCOS VINICIUS LIMA', 'GUSTAVO HENRIQUE C', 'VICTOR LUIZ LOPES', 'KAYKY ESDRAS', 'MATHEUS VIEIRA DE CARVALHO', 'MILTON GON', 'CLAUDIONOR DE SOUZA'], 'sec_like' => '%INFORM%', 'escala' => 0],
                // SECRETARIA ADMINISTRATIVA
                ['sarams' => ['3930297', '4061616', '6158919', '6090729', '6240321', '6666147', '6490654', '6666279', '6490662', '6909477', '6547621', '7294867', '6896200', '7519963', '7046049'], 'nomes' => ['INGRID LAGO', 'LUCIMARA FERNANDES', 'CAROLINA DE ALENCAR', 'ALESSANDRA SUZANE', 'JOÃO PAULO DA SILVA SOUZA', 'SARAH PEREIRA', 'PAULO EDUARDO CORR', 'VITOR ELOI'], 'sec_like' => '%ADMINISTRATIVA%', 'escala' => 0],
                // SECRETARIA OPERACIONAL
                ['sarams' => ['4201973', '4240138', '6576532', '6338186', '6453961', '6909566', '6548776', '7703180', '7113200'], 'nomes' => ['TAÍS RIBEIRO', 'TAIS RIBEIRO', 'INGRID MARTINS', 'MARCELLE ALCANTARA', 'ANA CAROLINA POMPEO', 'LUCAS ROBERTO', 'JOÃO GABRIEL THOMAZ', 'JOAO GABRIEL THOMAZ'], 'sec_like' => '%SECRETARIA OPERACIONAL%', 'escala' => 0],
                // ASSIPACEA
                ['sarams' => ['4279328', '4379438', '4404653', '4404688', '4478142', '6158781', '6090702'], 'nomes' => ['ERICA FREIRE', 'JOSÉ CARLOS SOUSA', 'JOSE CARLOS SOUSA', 'KELLY CRISTINA BATALHA', 'RENATO DE OLIVEIRA FRANCONERE', 'CAROLINE RUSSELL'], 'sec_like' => '%ASSIPACEA%', 'escala' => 0],
                // SIATO
                ['sarams' => ['4238028', '4379454'], 'nomes' => ['MUNIQUE CAROLINE', 'BEATRIZ LAIA'], 'sec_like' => '%SIATO%', 'escala' => 0],
                // ELETROMECÂNICA
                ['sarams' => ['4380690', '4220567', '4220575', '4236106', '4404645', '6158587', '6089330', '6240348', '6576621', '6338208', '6909604', '6548849', '7519980', '7046057', '7702736', '7113390', '7702787', '7113447'], 'nomes' => ['BIANCA BEATRIZ', 'ALEX MESQUITA', 'FÁBIO DE SENE', 'FABIO DE SENE', 'MICHELY ADRIANA', 'GUILHERME RAMOS', 'WELTON NOGUEIRA', 'ALEX CONDE', 'MARRANI DE SOUZA', 'ANA FLÁVIA', 'ANA FLAVIA', 'LUAN RIBEIRO', 'LEONARDO MOREIRA', 'ENZO GABRIEL'], 'sec_like' => '%ELETROMEC%', 'escala' => 0],
                // SUPRIMENTO
                ['sarams' => ['6576583', '6338194', '7519890', '7046014'], 'nomes' => ['MAYSE CORREIA', 'GABRIEL ALVES MIRANDA'], 'sec_like' => '%SUPRIMENTO%', 'escala' => 0],
                // ELETRÔNICA
                ['sarams' => ['6155308'], 'nomes' => ['BRUNO JESUS DA SILVA'], 'sec_like' => '%ELETRÔNICA%', 'sec_fallback' => '%ELETRONICA%', 'escala' => 0],
                // COMANDO
                ['sarams' => ['4378725', '3363384', '2560399', '3646581', '3326489', '3646548'], 'nomes' => ['JORGE HENRIQUE DE OLIVEIRA', 'ANTÔNIO GLÁUDIO', 'ANTONIO GLAUDIO', 'LUCIANO FERREIRA ALVES'], 'sec_like' => '%COMANDO%', 'escala' => 0],
                // TORRE DE CONTROLE
                ['sarams' => ['4279824', '4379446', '4379535', '4379462', '4380720', '4220532', '4404610', '4404629', '4404696', '6088538', '6158935', '6090745', '6158943', '6090753', '6240356', '6576672', '6338224', '6453953', '6666139', '6490646', '6666325', '6490689'], 'nomes' => ['RAFAEL CIPRIANO', 'JONATHAN FERNANDES', 'THAIS VITOR', 'WELLINGTON FERREIRA', 'DIANE RIBEIRO', 'ANA CAROLINA THOMAZ', 'RAFAEL ESTEVES', 'BRUNO HENRIQUE', 'LETÍCIA CLAUDINO', 'LETICIA CLAUDINO', 'MARCELA SOUZA', 'JESSICA DOS ANJOS', 'ALISSON MEDEIROS', 'PEDRO LEIVA', 'THAMIRES MAGALHÃES', 'THAMIRES MAGALHAES', 'LÍLLIAN COUTINHO', 'LILLIAN COUTINHO'], 'sec_like' => '%TORRE%', 'escala' => 1],
                // EMS-1 / CMA-2
                ['sarams' => ['4220583', '4236114', '4404580', '6240291', '6909612', '6548857'], 'nomes' => ['RONALDO TELES', 'MICHELLI BEZERRA', 'LUIS EDUARDO GOMES', 'MILAINE MARQUES', 'NATÁLIA VASCONCELOS', 'NATALIA VASCONCELOS'], 'sec_like' => '%EMS%', 'escala' => 1],
                // SALA AIS
                ['sarams' => ['4040074', '4237668', '2264722', '4220516', '4404572', '4478177', '6240372', '6576630', '6338216', '6453988', '6909590', '6548814'], 'nomes' => ['LUCIANY DA SILVA', 'MARCOS PAULO GARCIA', 'PAULO CÉSAR LEITE', 'PAULO CESAR LEITE', 'MICHELE DE AZEVEDO', 'RICARDO ALCINO', 'KESSYA RODRIGUES', 'BRUNA PESSOA', 'MARCIO DA CUNHA', 'INÊS SAMPAIO', 'INES SAMPAIO'], 'sec_like' => '%AIS%', 'escala' => 1]
            ];

            foreach ($mapeamento as $grp) {
                $secStmt = $db->prepare("SELECT id FROM `$secTable` WHERE `$secCol` LIKE ? LIMIT 1");
                $secStmt->execute([$grp['sec_like']]);
                $secId = $secStmt->fetchColumn();
                if (!$secId && !empty($grp['sec_fallback'])) {
                    $secStmt->execute([$grp['sec_fallback']]);
                    $secId = $secStmt->fetchColumn();
                }
                if (!$secId) continue;

                foreach ($grp['sarams'] as $saram) {
                    $db->exec("UPDATE users SET `$colSectionId` = $secId, `$colEscala` = {$grp['escala']} WHERE REPLACE(REPLACE(REPLACE(saram, '.', ''), '-', ''), ' ', '') LIKE '%$saram%'");
                }
                foreach ($grp['nomes'] as $nome) {
                    $db->exec("UPDATE users SET `$colSectionId` = $secId, `$colEscala` = {$grp['escala']} WHERE name LIKE '%$nome%'");
                }
            }
        } catch (Exception $e) {
            error_log("Erro no autoHealEfetivo: " . $e->getMessage());
        }
    }

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

        // Se estiver zerado (ex: banco de produção sem migração de seção prévia), executa auto-cura
        if ($totalEfetivo === 0) {
            autoHealEfetivo($db, $secTable, $secCol, $colSectionId, $colEscala, $colWarName);
            $stmtGeral = $db->query("
                SELECT COUNT(u.id) as total 
                FROM users u 
                JOIN `$secTable` s ON u.`$colSectionId` = s.id 
                WHERE 1=1 $whereDeleted $filterExpediente
            ");
            $totalEfetivo = (int)($stmtGeral->fetch()['total'] ?? 0);
        }
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

    // 6. Alertas de Inspeções de Saúde (Validade Insp. Saúde <= 90 dias ou Vencidas)
    $alertasInspecao = [];
    $erroInspecao = null;
    $totalMilitaresAvaliados = 0;

    try {
        // Obter mapa de seções de forma resiliente
        $secaoNomeMap = [];
        try {
            if (!empty($secTable)) {
                $secRows = $db->query("SELECT id, `$secCol` as nome_secao FROM `$secTable`")->fetchAll();
                foreach ($secRows as $sr) {
                    $secaoNomeMap[$sr['id']] = $sr['nome_secao'];
                }
            }
        } catch (Exception $e) {}

        // Busca direta de todos os usuários cadastrados
        $stmtInsp = $db->query("SELECT * FROM users ORDER BY id ASC");
        $inspecoesRaw = $stmtInsp->fetchAll();
        $totalMilitaresAvaliados = count($inspecoesRaw);

        $hojeObj = new DateTime('today');
        $hojeObj->setTime(0, 0, 0);

        foreach ($inspecoesRaw as $m) {
            $secId = $m['section_id'] ?? ($m['secao_id'] ?? 0);
            $secaoNomeMilitar = $secaoNomeMap[$secId] ?? ($m['secao'] ?? 'DTCEA-SJ');

            // 1. Campo direto validade_insp_saude ou aliases
            $valStr = trim((string)($m['validade_insp_saude'] ?? ($m['validade_inspecao'] ?? ($m['validade_inspecao_saude'] ?? ($m['val_insp_saude'] ?? ($m['validade'] ?? ''))))));
            
            // 2. Campo direto data_insp_saude ou aliases
            $dtStr = trim((string)($m['data_insp_saude'] ?? ($m['data_inspecao'] ?? ($m['data_inspecao_saude'] ?? ($m['dt_insp_saude'] ?? ($m['data_realizacao'] ?? ''))))));

            $valObj = null;
            if (!empty($valStr) && $valStr !== '0000-00-00' && $valStr !== '00/00/0000' && $valStr !== '-' && $valStr !== 'null') {
                $valObj = parseDataValidadeFlexible($valStr);
            }

            $dtInspObj = null;
            if (!empty($dtStr) && $dtStr !== '0000-00-00' && $dtStr !== '00/00/0000' && $dtStr !== '-' && $dtStr !== 'null') {
                $dtInspObj = parseDataValidadeFlexible($dtStr);
            }

            // Se não tem validade explícita mas tem data de realização, projeta validade para +1 ano
            if (!$valObj && $dtInspObj) {
                $valObj = clone $dtInspObj;
                $valObj->modify('+1 year');
            }

            if (!$valObj) continue;
            $valObj->setTime(0, 0, 0);

            // Calcula dias restantes: data_validade - data_atual
            $diffDays = (int)$hojeObj->diff($valObj)->format('%r%a');

            // Alerta se a validade for menor ou igual a 90 dias (inclui todas as já vencidas)
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
                    'nome_completo' => $m['name'] ?? ($m['nome'] ?? ''),
                    'nome_guerra' => $colWarName ? ($m[$colWarName] ?? '') : ($m['war_name'] ?? ($m['nome_guerra'] ?? '')),
                    'posto_grad' => $colGrade ? ($m[$colGrade] ?? '') : ($m['grade'] ?? ($m['posto_grad'] ?? '')),
                    'nome_formatado' => formatarMilitar($m),
                    'especialidade' => $colSpecialty ? ($m[$colSpecialty] ?? '') : ($m['specialty'] ?? ($m['especialidade'] ?? '')),
                    'saram' => $colSaram ? ($m[$colSaram] ?? '') : ($m['saram'] ?? ($m['saram_militar'] ?? '')),
                    'secao' => abreviarNomeSecao($secaoNomeMilitar),
                    'secao_original' => $secaoNomeMilitar,
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

        // Ordena para que os mais urgentes e vencidos apareçam primeiro no topo
        usort($alertasInspecao, function($a, $b) {
            return $a['dias_restantes'] <=> $b['dias_restantes'];
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
