<?php
// auth.php
// Sistema de Autenticação e Controle de Acesso do Painel SECSA

if (session_status() === PHP_SESSION_NONE) {
    // Configurações de sessão segura
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');

// Função auxiliar para carregar .env de múltiplos caminhos possíveis
function carregarEnvAuth($caminho) {
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

// Procura o .env
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
    if (carregarEnvAuth($envPath)) {
        break;
    }
}

function getDatabaseConnection() {
    static $db = null;
    if ($db !== null) return $db;

    $db_host = getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? ($_SERVER['DB_HOST'] ?? '127.0.0.1'));
    $db_port = getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? ($_SERVER['DB_PORT'] ?? '3306'));
    $db_name = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ($_SERVER['DB_DATABASE'] ?? 'efetivosj'));
    $db_user = getenv('DB_USERNAME') ?: ($_ENV['DB_USERNAME'] ?? ($_SERVER['DB_USERNAME'] ?? 'root'));
    $db_pass = getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : ($_ENV['DB_PASSWORD'] ?? ($_SERVER['DB_PASSWORD'] ?? ''));

    // Lista de tentativas com credenciais operacionais conhecidas caso a padrão falhe
    $senhasTentativas = array_unique([$db_pass, '@dm1nSJ-D4t4b@53', 'root', 'website', '']);

    foreach ($senhasTentativas as $senha) {
        try {
            $db = new PDO(
                "mysql:host=$db_host;port=$db_port;dbname=$db_name;charset=utf8mb4",
                $db_user,
                $senha,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 3
                ]
            );
            return $db;
        } catch (PDOException $e) {
            continue;
        }
    }

    throw new PDOException("Não foi possível conectar ao banco de dados MySQL ($db_name).");
}

function sanitizeAuth($data) {
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

function formatarNomeMilitarAuth($m) {
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

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function isAdmin() {
    return isLoggedIn() && isset($_SESSION['user_perfil']) && $_SESSION['user_perfil'] === 'admin';
}

function requireAdmin() {
    if (!isAdmin()) {
        header("Location: login.php");
        exit;
    }
}

function getCurrentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'saram' => $_SESSION['user_saram'] ?? '',
        'email' => $_SESSION['user_email'] ?? '',
        'nome' => $_SESSION['user_nome'] ?? 'Administrador',
        'perfil' => $_SESSION['user_perfil'] ?? 'admin',
        'secao_id' => $_SESSION['user_secao_id'] ?? null,
        'secao' => $_SESSION['user_secao'] ?? 'SECSA'
    ];
}
