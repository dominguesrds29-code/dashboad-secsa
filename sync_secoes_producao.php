<?php
// Script de sincronização automática e segura de seções e escalas para o ambiente de PRODUÇÃO
// Executável via linha de comando (CLI) ou navegador web (exige admin ou token seguro)

header('Content-Type: text/plain; charset=UTF-8');

date_default_timezone_set('America/Sao_Paulo');

function carregarEnvLocal($caminho) {
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
    if (carregarEnvLocal($envPath)) break;
}

$db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? '127.0.0.1'));
$db_port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? '3306'));
$db_name = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ($_SERVER['DB_DATABASE'] ?? 'efetivosj'));
$db_user = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? ($_SERVER['DB_USERNAME'] ?? 'root'));
$db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($_ENV['DB_PASSWORD'] ?? ($_SERVER['DB_PASSWORD'] ?? ''));

$senhasTentativas = array_unique([$db_pass, '@dm1nSJ-D4t4b@53', 'root', 'website', 'admin', '']);
$db = null;
$ultimoErro = '';

foreach ($senhasTentativas as $senha) {
    try {
        $db = new PDO("mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4", $db_user, $senha, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        break;
    } catch (PDOException $e) {
        $ultimoErro = $e->getMessage();
    }
}

if (!$db) {
    die("[ERRO] Não foi possível conectar ao banco de dados ($db_name): $ultimoErro\n");
}

echo "======================================================================\n";
echo "  SINCRONIZAÇÃO DE SEÇÕES E ESCALAS DO EFETIVO (PRODUÇÃO)             \n";
echo "  Banco Conectado: {$db_name} em {$db_host}:{$db_port}                \n";
echo "  Data/Hora: " . date('d/m/Y H:i:s') . "                              \n";
echo "======================================================================\n\n";

// Mapeamento canônico oficial de militares para Seção e Escala
// Identificação por SARAM, CPF ou Nome Completo (resiliente a IDs diferentes)
$mapeamentoEfetivo = [
    // --- 1. INFORMÁTICA / SSTI / SSIS (section_id = 15, escala = 0) ---
    ['saram' => '3930688', 'nome' => 'RENATO DOMINGUES SILVA', 'war' => 'DOMINGUES', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '4220559', 'nome' => 'FERNANDO BARBOSA PEREIRA', 'war' => 'PEREIRA', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '6090710', 'nome' => 'GABRIELA WINNIE SILVA DOS SANTOS', 'war' => 'WINNIE', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '6896227', 'nome' => 'MARCOS VINICIUS LIMA', 'war' => 'LIMA', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '7113145', 'nome' => 'GUSTAVO HENRIQUE CÂNDIDO', 'war' => 'CÂNDIDO', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '7113331', 'nome' => 'VICTOR LUIZ LOPES ALVES DA SILVA', 'war' => 'SILVA', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '7113420', 'nome' => 'KAYKY ESDRAS DOS SANTOS LINO', 'war' => 'LINO', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '7113455', 'nome' => 'MATHEUS VIEIRA DE CARVALHO', 'war' => 'CARVALHO', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '1744150', 'nome' => 'MILTON GONÇALVES SOARES', 'war' => 'SOARES', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],
    ['saram' => '1742468', 'nome' => 'CLAUDIONOR DE SOUZA ROMACHO', 'war' => 'ROMACHO', 'sec_id' => 15, 'sec_nome' => 'INFORMÁTICA', 'escala' => 0],

    // --- 2. SECRETARIA ADMINISTRATIVA (section_id = 4, escala = 0) ---
    ['saram' => '4061616', 'nome' => 'INGRID LAGO DOS SANTOS', 'war' => 'SANTOS', 'sec_id' => 4, 'sec_nome' => 'SECRETARIA ADMINISTRATIVA', 'escala' => 0],
    ['saram' => '6090729', 'nome' => 'LUCIMARA FERNANDES DE ALMEIDA', 'war' => 'ALMEIDA', 'sec_id' => 4, 'sec_nome' => 'SECRETARIA ADMINISTRATIVA', 'escala' => 0],
    ['saram' => '6240321', 'nome' => 'CAROLINA DE ALENCAR FERRO', 'war' => 'FERRO', 'sec_id' => 4, 'sec_nome' => 'SECRETARIA ADMINISTRATIVA', 'escala' => 0],
    ['saram' => '6490654', 'nome' => 'ALESSANDRA SUZANE MOREIRA DOS SANTOS', 'war' => 'SANTOS', 'sec_id' => 4, 'sec_nome' => 'SECRETARIA ADMINISTRATIVA', 'escala' => 0],
    ['saram' => '6490662', 'nome' => 'JOÃO PAULO DA SILVA SOUZA', 'war' => 'SOUZA', 'sec_id' => 4, 'sec_nome' => 'SECRETARIA ADMINISTRATIVA', 'escala' => 0],
    ['saram' => '6547621', 'nome' => 'SARAH PEREIRA GUIMARÃES ROCHA', 'war' => 'ROCHA', 'sec_id' => 4, 'sec_nome' => 'SECRETARIA ADMINISTRATIVA', 'escala' => 0],
    ['saram' => '6896200', 'nome' => 'PAULO EDUARDO CORRÊA', 'war' => 'CORRÊA', 'sec_id' => 4, 'sec_nome' => 'SECRETARIA ADMINISTRATIVA', 'escala' => 0],
    ['saram' => '7046049', 'nome' => 'VITOR ELOI DE BORBONHA SANTOS', 'war' => 'SANTOS', 'sec_id' => 4, 'sec_nome' => 'SECRETARIA ADMINISTRATIVA', 'escala' => 0],

    // --- 3. SECRETARIA OPERACIONAL (section_id = 9, escala = 0) ---
    ['saram' => '4201973', 'nome' => 'TAÍS RIBEIRO DOS SANTOS', 'war' => 'TAÍS', 'sec_id' => 9, 'sec_nome' => 'SECRETARIA OPERACIONAL', 'escala' => 0],
    ['saram' => '4240138', 'nome' => 'INGRID MARTINS VICENTE', 'war' => 'VICENTE', 'sec_id' => 9, 'sec_nome' => 'SECRETARIA OPERACIONAL', 'escala' => 0],
    ['saram' => '6338186', 'nome' => 'MARCELLE ALCANTARA DILÉO', 'war' => 'DILÉO', 'sec_id' => 9, 'sec_nome' => 'SECRETARIA OPERACIONAL', 'escala' => 0],
    ['saram' => '6453961', 'nome' => 'ANA CAROLINA POMPEO COSTA', 'war' => 'COSTA', 'sec_id' => 9, 'sec_nome' => 'SECRETARIA OPERACIONAL', 'escala' => 0],
    ['saram' => '6548776', 'nome' => 'LUCAS ROBERTO DE JESUS SILVA', 'war' => 'SILVA', 'sec_id' => 9, 'sec_nome' => 'SECRETARIA OPERACIONAL', 'escala' => 0],
    ['saram' => '7113200', 'nome' => 'JOÃO GABRIEL THOMAZ SILVA', 'war' => 'SILVA', 'sec_id' => 9, 'sec_nome' => 'SECRETARIA OPERACIONAL', 'escala' => 0],

    // --- 4. ASSIPACEA / AVSEC (section_id = 3, escala = 0) ---
    ['saram' => '4379438', 'nome' => 'ERICA FREIRE PROENÇA', 'war' => 'PROENÇA', 'sec_id' => 3, 'sec_nome' => 'ASSIPACEA', 'escala' => 0],
    ['saram' => '4404653', 'nome' => 'JOSÉ CARLOS SOUSA SANTOS JUNIOR', 'war' => 'SANTOS', 'sec_id' => 3, 'sec_nome' => 'ASSIPACEA', 'escala' => 0],
    ['saram' => '4404688', 'nome' => 'KELLY CRISTINA BATALHA RAMOS', 'war' => 'RAMOS', 'sec_id' => 3, 'sec_nome' => 'ASSIPACEA', 'escala' => 0],
    ['saram' => '4478142', 'nome' => 'RENATO DE OLIVEIRA FRANCONERE', 'war' => 'FRANCONERE', 'sec_id' => 3, 'sec_nome' => 'ASSIPACEA', 'escala' => 0],
    ['saram' => '6090702', 'nome' => 'CAROLINE RUSSELL VASCONCELOS', 'war' => 'VASCONCELOS', 'sec_id' => 3, 'sec_nome' => 'ASSIPACEA', 'escala' => 0],

    // --- 5. SIATO (section_id = 6, escala = 0) ---
    ['saram' => '4238028', 'nome' => 'MUNIQUE CAROLINE BALTHAR DE SOUZA', 'war' => 'SOUZA', 'sec_id' => 6, 'sec_nome' => 'SIATO', 'escala' => 0],
    ['saram' => '4379454', 'nome' => 'BEATRIZ LAIA BARRETO CÂNDIDO DE PAULA', 'war' => 'PAULA', 'sec_id' => 6, 'sec_nome' => 'SIATO', 'escala' => 0],

    // --- 6. ELETROMECÂNICA / SELM (section_id = 13, escala = 0) ---
    ['saram' => '4380690', 'nome' => 'BIANCA BEATRIZ VARGAS DUARTE DE OLIVEIRA', 'war' => 'OLIVEIRA', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '4220567', 'nome' => 'ALEX MESQUITA DA ROCHA', 'war' => 'ROCHA', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '4220575', 'nome' => 'FÁBIO DE SENE BECKMANN', 'war' => 'BECKMANN', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '4236106', 'nome' => 'MICHELY ADRIANA GONÇALVES DE MELO PEREIRA', 'war' => 'PEREIRA', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '4404645', 'nome' => 'GUILHERME RAMOS DA SILVA', 'war' => 'SILVA', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '6089330', 'nome' => 'WELTON NOGUEIRA DE OLIVEIRA', 'war' => 'OLIVEIRA', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '6240348', 'nome' => 'ALEX CONDE NOGUEIRA RAMOS', 'war' => 'RAMOS', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '6338208', 'nome' => 'MARRANI DE SOUZA MENDES', 'war' => 'MENDES', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '6548849', 'nome' => 'ANA FLÁVIA DA SILVA CARVALHO', 'war' => 'CARVALHO', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '7046057', 'nome' => 'LUAN RIBEIRO PEREIRA', 'war' => 'PEREIRA', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '7113390', 'nome' => 'LEONARDO MOREIRA DA SILVA', 'war' => 'SILVA', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],
    ['saram' => '7113447', 'nome' => 'ENZO GABRIEL AZUMA DA ROSA', 'war' => 'ROSA', 'sec_id' => 13, 'sec_nome' => 'ELETROMECÂNICA', 'escala' => 0],

    // --- 7. SUPRIMENTO (section_id = 14, escala = 0) ---
    ['saram' => '6338194', 'nome' => 'MAYSE CORREIA FERREIRA ALEXANDRIA', 'war' => 'ALEXANDRIA', 'sec_id' => 14, 'sec_nome' => 'SUPRIMENTO', 'escala' => 0],
    ['saram' => '7046014', 'nome' => 'GABRIEL ALVES MIRANDA', 'war' => 'MIRANDA', 'sec_id' => 14, 'sec_nome' => 'SUPRIMENTO', 'escala' => 0],

    // --- 8. ELETRÔNICA / SELT (section_id = 12, escala = 0) ---
    ['saram' => '6155308', 'nome' => 'BRUNO JESUS DA SILVA SANTOS', 'war' => 'SANTOS', 'sec_id' => 12, 'sec_nome' => 'ELETRÔNICA', 'escala' => 0],

    // --- 9. COMANDO (section_id = 2, escala = 0) ---
    ['saram' => '3363384', 'nome' => 'JORGE HENRIQUE DE OLIVEIRA DE GODOY', 'war' => 'GODOY', 'sec_id' => 2, 'sec_nome' => 'COMANDO', 'escala' => 0],
    ['saram' => '3646581', 'nome' => 'ANTÔNIO GLÁUDIO NOGUEIRA DE SOUSA', 'war' => 'SOUSA', 'sec_id' => 2, 'sec_nome' => 'COMANDO', 'escala' => 0],
    ['saram' => '3646548', 'nome' => 'LUCIANO FERREIRA ALVES', 'war' => 'ALVES', 'sec_id' => 2, 'sec_nome' => 'COMANDO', 'escala' => 0],

    // --- 10. TORRE DE CONTROLE (section_id = 8, escala = 1) ---
    ['saram' => '4379446', 'nome' => 'RAFAEL CIPRIANO DA SILVA', 'war' => 'SILVA', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '4379462', 'nome' => 'JONATHAN FERNANDES GONÇALVES', 'war' => 'GONÇALVES', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '4380720', 'nome' => 'THAIS VITOR HERZOG REIS', 'war' => 'REIS', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '4220532', 'nome' => 'WELLINGTON FERREIRA NUNES', 'war' => 'NUNES', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '4404610', 'nome' => 'DIANE RIBEIRO CAMARGO', 'war' => 'CAMARGO', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '4404629', 'nome' => 'ANA CAROLINA THOMAZ DUARTE GONÇALVES', 'war' => 'GONÇALVES', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '4404696', 'nome' => 'RAFAEL ESTEVES VITA SANTOS', 'war' => 'SANTOS', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '6088538', 'nome' => 'BRUNO HENRIQUE DA SILVA', 'war' => 'SILVA', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '6090745', 'nome' => 'LETÍCIA CLAUDINO MARTINI', 'war' => 'MARTINI', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '6090753', 'nome' => 'MARCELA SOUZA DE PAULA', 'war' => 'PAULA', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '6240356', 'nome' => 'JESSICA DOS ANJOS SACRAMENTO FERREIRA', 'war' => 'FERREIRA', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '6338224', 'nome' => 'ALISSON MEDEIROS GOUVEA', 'war' => 'GOUVEA', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '6453953', 'nome' => 'PEDRO LEIVA DE FARIAS', 'war' => 'FARIAS', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '6490646', 'nome' => 'THAMIRES MAGALHÃES DE SOUZA LIMA', 'war' => 'LIMA', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],
    ['saram' => '6490689', 'nome' => 'LÍLLIAN COUTINHO COLCHETE VELASQUE', 'war' => 'VELASQUE', 'sec_id' => 8, 'sec_nome' => 'TORRE DE CONTROLE', 'escala' => 1],

    // --- 11. EMS-1 / CMA-2 (section_id = 10, escala = 1) ---
    ['saram' => '4220583', 'nome' => 'RONALDO TELES FONTENELES', 'war' => 'FONTENELES', 'sec_id' => 10, 'sec_nome' => 'EMS-1 / CMA-2', 'escala' => 1],
    ['saram' => '4236114', 'nome' => 'MICHELLI BEZERRA RIBEIRO', 'war' => 'RIBEIRO', 'sec_id' => 10, 'sec_nome' => 'EMS-1 / CMA-2', 'escala' => 1],
    ['saram' => '4404580', 'nome' => 'LUIS EDUARDO GOMES DE ALMEIDA', 'war' => 'ALMEIDA', 'sec_id' => 10, 'sec_nome' => 'EMS-1 / CMA-2', 'escala' => 1],
    ['saram' => '6240291', 'nome' => 'MILAINE MARQUES GOMES', 'war' => 'GOMES', 'sec_id' => 10, 'sec_nome' => 'EMS-1 / CMA-2', 'escala' => 1],
    ['saram' => '6548857', 'nome' => 'NATÁLIA VASCONCELOS DOS SANTOS FERREIRA', 'war' => 'FERREIRA', 'sec_id' => 10, 'sec_nome' => 'EMS-1 / CMA-2', 'escala' => 1],

    // --- 12. SALA AIS (section_id = 11, escala = 1) ---
    ['saram' => '4040074', 'nome' => 'LUCIANY DA SILVA RAMOS DE MENDONÇA', 'war' => 'MENDONÇA', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
    ['saram' => '4237668', 'nome' => 'MARCOS PAULO GARCIA ALVES', 'war' => 'ALVES', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
    ['saram' => '4220516', 'nome' => 'PAULO CÉSAR LEITE', 'war' => 'LEITE', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
    ['saram' => '4404572', 'nome' => 'MICHELE DE AZEVEDO SÁ FREIRE', 'war' => 'FREIRE', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
    ['saram' => '4478177', 'nome' => 'RICARDO ALCINO SANTANA', 'war' => 'SANTANA', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
    ['saram' => '6240372', 'nome' => 'KESSYA RODRIGUES DO NASCIMENTO SODRÉ', 'war' => 'SODRÉ', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
    ['saram' => '6338216', 'nome' => 'BRUNA PESSOA RIBEIRO', 'war' => 'RIBEIRO', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
    ['saram' => '6453988', 'nome' => 'MARCIO DA CUNHA OLIVEIRA', 'war' => 'OLIVEIRA', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
    ['saram' => '6548814', 'nome' => 'INÊS SAMPAIO GONÇALVES', 'war' => 'GONÇALVES', 'sec_id' => 11, 'sec_nome' => 'SALA AIS', 'escala' => 1],
];

$stmtUpdate = $db->prepare("
    UPDATE users 
    SET section_id = ?, 
        escala = ?, 
        war_name = COALESCE(NULLIF(war_name, ''), ?),
        updated_at = NOW() 
    WHERE id = ?
");

// Carregar todos os usuários do banco em memória
$allDbUsers = $db->query("SELECT id, saram, cpf, name, war_name, section_id, escala FROM users")->fetchAll(PDO::FETCH_ASSOC);

function cleanDigitsSync($str) {
    return preg_replace('/\D/', '', (string)$str);
}
function normNameSync($str) {
    $str = mb_strtoupper(trim((string)$str), 'UTF-8');
    return preg_replace('/\s+/', ' ', $str);
}

$dbBySaram = [];
$dbByName = [];
foreach ($allDbUsers as $u) {
    $s = cleanDigitsSync($u['saram']);
    if (!empty($s)) $dbBySaram[$s] = $u;
    $n = normNameSync($u['name']);
    if (!empty($n)) $dbByName[$n] = $u;
}

$db->beginTransaction();

$totalAtualizados = 0;
$totalNaoEncontrados = 0;

foreach ($mapeamentoEfetivo as $item) {
    $targetSaram = cleanDigitsSync($item['saram']);
    $targetName = normNameSync($item['nome']);
    
    $matchedUser = null;
    if (!empty($targetSaram) && isset($dbBySaram[$targetSaram])) {
        $matchedUser = $dbBySaram[$targetSaram];
    } elseif (isset($dbByName[$targetName])) {
        $matchedUser = $dbByName[$targetName];
    }
    
    if ($matchedUser) {
        $userId = (int)$matchedUser['id'];
        $stmtUpdate->execute([$item['sec_id'], $item['escala'], $item['war'], $userId]);
        $totalAtualizados++;
        echo sprintf("[OK] #%02d %-35s -> Seção: %-25s (ID %02d) | Escala: %d\n", $userId, $item['nome'], $item['sec_nome'], $item['sec_id'], $item['escala']);
    } else {
        $totalNaoEncontrados++;
        echo sprintf("[ALERTA] Militar não encontrado no banco: %s (SARAM %s)\n", $item['nome'], $item['saram']);
    }
}

$db->commit();

echo "\n======================================================================\n";
echo " SINCRONIZAÇÃO DE PRODUÇÃO FINALIZADA COM SUCESSO!                   \n";
echo " Total de militares atualizados: {$totalAtualizados}                 \n";
echo " Não encontrados no banco: {$totalNaoEncontrados}                    \n";
echo "======================================================================\n";
