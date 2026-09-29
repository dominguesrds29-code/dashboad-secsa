<?php
// login.php
// Tela de Autenticação para Acesso Administrativo ao Painel SECSA DTCEA-SJ

require_once __DIR__ . '/auth.php';

// Se já estiver logado como administrador, redireciona para o painel principal
if (isAdmin()) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = sanitizeAuth($_POST['usuario'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if (!empty($loginInput) && !empty($senha)) {
        try {
            $db = getDatabaseConnection();

            // Identificar dinamicamente a tabela de seções
            $secTable = 'sections';
            $secCol = 'name';
            try {
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
            } catch (Exception $e) {}

            $cleanLogin = str_replace(['.', '-', '/', ' '], '', $loginInput);

            $stmt = $db->prepare("
                SELECT u.*, 
                       COALESCE(s.`$secCol`, 'Sem Seção') AS secao_nome
                FROM users u
                LEFT JOIN `$secTable` s ON u.section_id = s.id
                WHERE (
                    u.saram = ? 
                    OR REPLACE(REPLACE(REPLACE(u.saram, '.', ''), '-', ''), ' ', '') = ?
                    OR u.email = ? 
                    OR u.war_name = ?
                    OR u.name = ?
                )
                AND u.deleted_at IS NULL
                LIMIT 1
            ");
            $stmt->execute([$loginInput, $cleanLogin, $loginInput, $loginInput, $loginInput]);
            $user = $stmt->fetch();

            if ($user && password_verify($senha, $user['password'])) {
                // Verificar permissão de administrador
                $isAdminUser = false;
                if (!empty($user['is_admin']) && (int)$user['is_admin'] === 1) {
                    $isAdminUser = true;
                }

                if (!$isAdminUser) {
                    $error = 'Acesso restrito: apenas administradores podem acessar o Painel SECSA.';
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_saram'] = $user['saram'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_nome'] = formatarNomeMilitarAuth($user);
                    $_SESSION['user_perfil'] = 'admin';
                    $_SESSION['user_secao_id'] = $user['section_id'] ?? null;
                    $_SESSION['user_secao'] = $user['secao_nome'] ?? 'SECSA';

                    header("Location: index.php");
                    exit;
                }
            } else {
                $error = 'SARAM, E-mail ou senha incorretos.';
            }
        } catch (PDOException $e) {
            $error = 'Erro no servidor de banco de dados: ' . $e->getMessage();
        }
    } else {
        $error = 'Por favor, preencha todos os campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logon - Painel SECSA DTCEA-SJ</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #002F6C;      /* Azul FAB */
            --primary-dark: #001F4C;
            --primary-light: #1E4E8C;
            --accent: #FEC111;       /* Amarelo Ouro */
            --accent-hover: #E5AE0F;
            --bg: #F3F6FA;
            --card-bg: #FFFFFF;
            --text: #2D3748;
            --text-muted: #718096;
            --border: #E2E8F0;
            --danger: #E53E3E;
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.6;
        }

        .login-body {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-light) 100%);
            position: relative;
            overflow: hidden;
            padding: 20px;
        }

        .login-body::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background-image: radial-gradient(rgba(255, 255, 255, 0.05) 1px, transparent 0);
            background-size: 24px 24px;
            top: 0;
            left: 0;
            pointer-events: none;
        }

        .login-card {
            background: white;
            border-radius: 16px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: var(--shadow-lg);
            z-index: 10;
            border-top: 6px solid var(--accent);
            text-align: center;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-logo {
            width: 120px;
            height: auto;
            margin-bottom: 18px;
            object-fit: contain;
        }

        .login-card h2 {
            color: var(--primary);
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .login-card p.subtitle {
            color: var(--text-muted);
            font-size: 0.85rem;
            margin-bottom: 25px;
            font-weight: 500;
        }

        .alert {
            background-color: #FFF5F5;
            border: 1px solid var(--danger);
            color: var(--danger);
            padding: 12px;
            border-radius: 8px;
            font-size: 0.88rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            text-align: left;
            gap: 10px;
            font-weight: 500;
        }

        .alert svg {
            flex-shrink: 0;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 6px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0, 47, 108, 0.15);
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
            border: none;
            width: 100%;
            padding: 13px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 10px;
            font-family: inherit;
        }

        .btn-primary:hover {
            background-color: var(--primary-dark);
        }

        .btn-primary:active {
            transform: scale(0.99);
        }

        .admin-badge-info {
            margin-top: 20px;
            font-size: 0.75rem;
            color: var(--text-muted);
            border-top: 1px solid var(--border);
            padding-top: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
    </style>
</head>
<body class="login-body">
    <div class="login-card">
        <img src="dtcea_sj_logo.png" alt="Logo DTCEA-SJ" class="login-logo">
        <h2>DTCEA-SJ</h2>
        <p class="subtitle">Painel de Gestão Administrativa SECSA</p>

        <?php if (!empty($error)): ?>
            <div class="alert">
                <svg width="20" height="20" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label for="usuario">SARAM ou E-mail FAB</label>
                <input type="text" name="usuario" id="usuario" class="form-input" placeholder="Ex: 393.068-8 ou email@fab.mil.br" required autofocus autocomplete="off" value="<?= isset($loginInput) ? htmlspecialchars($loginInput) : '' ?>">
            </div>

            <div class="form-group">
                <label for="senha">Senha</label>
                <input type="password" name="senha" id="senha" class="form-input" placeholder="Sua senha do sistema" required>
            </div>

            <button type="submit" class="btn-primary">Acessar Painel</button>
        </form>

        <div class="admin-badge-info">
            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            <span>Área de Acesso Restrito a Administradores</span>
        </div>
    </div>
</body>
</html>
