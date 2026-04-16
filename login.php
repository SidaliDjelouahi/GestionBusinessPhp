<?php
// ============================================================
//  G-Business — login.php
//  Auth: Hostinger DB (primary) → WAMP localhost (fallback)
// ============================================================
session_start();

// Already logged in? Go straight to dashboard
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

$error   = '';
$success = false;

// ---- DB helper: returns a PDO connection or throws ----
function makeConnection(string $host, string $db, string $user, string $pass): PDO
{
    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,          // connection timeout (seconds)
    ];
    return new PDO($dsn, $user, $pass, $options);
}

// ---- Process form submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Sanitize inputs
    $username = trim(htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'));
    $password = trim($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Veuillez remplir tous les champs.';
    } else {

        $pdo = null;

        // --- 1. Try Hostinger ---
        try {
            $pdo = makeConnection(
                'localhost',
                'u174726466_g_business',
                'u174726466_g_business',
                'Business@2027'
            );
        } catch (PDOException $e) {
            // Hostinger unreachable → try localhost
            $pdo = null;
        }

        // --- 2. Fallback to WAMP localhost ---
        if ($pdo === null) {
            try {
                $pdo = makeConnection('localhost', 'gestion_business', 'root', '');
            } catch (PDOException $e) {
                $error = 'Impossible de se connecter à la base de données. Veuillez réessayer plus tard.';
            }
        }

        // --- 3. Query users table ---
        if ($pdo !== null && $error === '') {
            try {
                $stmt = $pdo->prepare(
                    "SELECT * FROM utilisateurs WHERE nom = :username LIMIT 1"
                );
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch();

                if ($user) {
                    // Support plain-text, MD5, and password_hash passwords
                    $storedPass = $user['password'];
                    $matched    = false;

                    if (password_verify($password, $storedPass)) {
                        // bcrypt / Argon2
                        $matched = true;
                    } elseif ($storedPass === md5($password)) {
                        // Legacy MD5
                        $matched = true;
                    } elseif ($storedPass === $password) {
                        // Plain text (dev only)
                        $matched = true;
                    }

                    if ($matched) {
                        // Regenerate session ID to prevent fixation
                        session_regenerate_id(true);

                        $_SESSION['logged_in'] = true;
                        $_SESSION['username']  = htmlspecialchars($user['nom'],      ENT_QUOTES, 'UTF-8');
                        $_SESSION['rank']      = htmlspecialchars($user['rank']  ?? 'user', ENT_QUOTES, 'UTF-8');
                        $_SESSION['user_id']   = $user['id'] ?? null;
                        $_SESSION['user']      = $user;

                        header('Location: dashboard.php');
                        exit;
                    } else {
                        $error = 'Identifiants incorrects. Veuillez réessayer.';
                    }
                } else {
                    $error = 'Identifiants incorrects. Veuillez réessayer.';
                }
            } catch (PDOException $e) {
                $error = 'Erreur lors de la vérification des identifiants.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Connexion — G-Business</title>
  <meta name="description" content="Connectez-vous à votre espace G-Business." />
  <meta name="robots" content="noindex, nofollow" />

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
  <!-- FontAwesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" />
  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet" />

  <style>
    /* ===== ROOT ===== */
    :root {
      --primary:       #6C63FF;
      --primary-dark:  #4e46e5;
      --primary-light: #a5a0ff;
      --accent2:       #43CBFF;
      --accent:        #FF6584;
      --dark:          #0d0e1a;
      --dark-2:        #12132a;
      --dark-3:        #1a1b35;
      --glass-bg:      rgba(255,255,255,0.05);
      --glass-border:  rgba(255,255,255,0.10);
      --text-primary:  #f0f1ff;
      --text-muted:    #9394b8;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }

    body {
      font-family: 'Inter', sans-serif;
      background:
        radial-gradient(ellipse 80% 60% at 20% 30%, rgba(108,99,255,0.15) 0%, transparent 60%),
        radial-gradient(ellipse 60% 50% at 80% 80%, rgba(67,203,255,0.10) 0%, transparent 60%),
        linear-gradient(160deg, var(--dark-2) 0%, var(--dark) 60%, var(--dark-3) 100%);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      color: var(--text-primary);
      padding: 2rem 1rem;
    }

    /* ===== BACK LINK ===== */
    .back-link {
      position: fixed;
      top: 1.25rem;
      left: 1.5rem;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.83rem;
      font-weight: 600;
      color: var(--text-muted);
      text-decoration: none;
      padding: 0.45rem 0.9rem;
      border: 1px solid var(--glass-border);
      border-radius: 10px;
      background: var(--glass-bg);
      backdrop-filter: blur(10px);
      transition: all 0.3s ease;
      z-index: 99;
    }
    .back-link:hover { color: var(--primary-light); border-color: rgba(108,99,255,0.4); background: rgba(108,99,255,0.08); }

    /* ===== LOGIN CARD ===== */
    .login-card {
      width: 100%;
      max-width: 440px;
      background: rgba(255,255,255,0.04);
      border: 1px solid var(--glass-border);
      border-radius: 24px;
      padding: 2.75rem 2.5rem 2.5rem;
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      box-shadow:
        0 30px 80px rgba(0,0,0,0.45),
        0 0 0 1px rgba(255,255,255,0.04) inset;
      position: relative;
      overflow: hidden;
      animation: cardIn 0.5s cubic-bezier(.4,0,.2,1) both;
    }

    /* Top gradient border */
    .login-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      background: linear-gradient(90deg, var(--primary), var(--accent2), var(--accent));
    }

    @keyframes cardIn {
      from { opacity: 0; transform: translateY(30px) scale(0.97); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* ===== LOGO ===== */
    .login-logo {
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-bottom: 2rem;
    }
    .logo-icon {
      width: 56px;
      height: 56px;
      border-radius: 16px;
      background: linear-gradient(135deg, var(--primary), var(--accent2));
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.4rem;
      color: white;
      margin-bottom: 0.9rem;
      box-shadow: 0 8px 24px rgba(108,99,255,0.4);
      animation: logoPulse 3s ease-in-out infinite;
    }
    @keyframes logoPulse {
      0%,100% { box-shadow: 0 8px 24px rgba(108,99,255,0.4); }
      50%      { box-shadow: 0 12px 36px rgba(108,99,255,0.65); }
    }
    .logo-name {
      font-size: 1.6rem;
      font-weight: 800;
      letter-spacing: -0.5px;
      background: linear-gradient(135deg, var(--primary-light), var(--accent2));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
    .logo-tagline {
      font-size: 0.8rem;
      color: var(--text-muted);
      margin-top: 0.25rem;
      font-weight: 400;
    }

    /* ===== SECTION HEADING ===== */
    .card-heading {
      font-size: 1.15rem;
      font-weight: 700;
      color: var(--text-primary);
      margin-bottom: 0.3rem;
    }
    .card-subheading {
      font-size: 0.83rem;
      color: var(--text-muted);
      margin-bottom: 1.75rem;
    }

    /* ===== FORM ELEMENTS ===== */
    .form-label-dark {
      display: block;
      font-size: 0.82rem;
      font-weight: 600;
      color: var(--text-muted);
      margin-bottom: 0.4rem;
    }

    .input-group-dark {
      position: relative;
      margin-bottom: 1.1rem;
    }

    .input-icon {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: var(--text-muted);
      font-size: 0.9rem;
      pointer-events: none;
      transition: color 0.3s ease;
      z-index: 2;
    }

    .form-control-dark {
      width: 100%;
      background: rgba(255,255,255,0.05);
      border: 1px solid var(--glass-border);
      border-radius: 12px;
      color: var(--text-primary);
      padding: 0.75rem 1rem 0.75rem 2.75rem;
      font-size: 0.92rem;
      font-family: 'Inter', sans-serif;
      transition: all 0.3s ease;
      outline: none;
    }
    .form-control-dark::placeholder { color: rgba(147,148,184,0.6); }
    .form-control-dark:focus {
      border-color: rgba(108,99,255,0.55);
      background: rgba(108,99,255,0.07);
      box-shadow: 0 0 0 3px rgba(108,99,255,0.2);
      color: var(--text-primary);
    }
    .form-control-dark:focus + .input-icon,
    .input-group-dark:focus-within .input-icon { color: var(--primary-light); }

    /* Password toggle eye */
    .pw-toggle {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: var(--text-muted);
      font-size: 0.9rem;
      padding: 0;
      line-height: 1;
      transition: color 0.3s ease;
      z-index: 2;
    }
    .pw-toggle:hover { color: var(--primary-light); }

    /* Password field gets extra right padding for the eye icon */
    #password { padding-right: 2.75rem; }

    /* ===== ERROR ALERT ===== */
    .alert-dark-danger {
      background: rgba(229,62,62,0.12);
      border: 1px solid rgba(229,62,62,0.35);
      color: #fc8b8b;
      border-radius: 12px;
      padding: 0.75rem 1rem;
      font-size: 0.85rem;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 0.6rem;
      margin-bottom: 1.25rem;
      animation: shake 0.4s ease;
    }
    @keyframes shake {
      0%,100% { transform: translateX(0); }
      20%      { transform: translateX(-6px); }
      40%      { transform: translateX(6px); }
      60%      { transform: translateX(-4px); }
      80%      { transform: translateX(4px); }
    }

    /* ===== SUBMIT BUTTON ===== */
    .btn-submit {
      width: 100%;
      background: linear-gradient(135deg, var(--primary), var(--primary-dark));
      color: white;
      border: none;
      border-radius: 12px;
      padding: 0.85rem 1rem;
      font-size: 0.95rem;
      font-weight: 700;
      font-family: 'Inter', sans-serif;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 8px 25px rgba(108,99,255,0.35);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      margin-top: 0.5rem;
      position: relative;
      overflow: hidden;
    }
    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 14px 35px rgba(108,99,255,0.5);
    }
    .btn-submit:active { transform: translateY(0); }
    .btn-submit .spinner {
      display: none;
      width: 18px;
      height: 18px;
      border: 2px solid rgba(255,255,255,0.3);
      border-top-color: white;
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ===== DIVIDER ===== */
    .form-divider {
      display: flex;
      align-items: center;
      gap: 0.8rem;
      margin: 1.5rem 0;
      color: var(--text-muted);
      font-size: 0.78rem;
    }
    .form-divider::before,
    .form-divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--glass-border);
    }

    /* ===== SIGNUP LINK ===== */
    .signup-row {
      text-align: center;
      font-size: 0.85rem;
      color: var(--text-muted);
      margin-top: 1.25rem;
    }
    .signup-row a {
      color: var(--primary-light);
      font-weight: 600;
      text-decoration: none;
      transition: color 0.3s ease;
    }
    .signup-row a:hover { color: white; text-decoration: underline; }

    /* ===== FOOTER ===== */
    .page-footer {
      margin-top: 2rem;
      font-size: 0.75rem;
      color: var(--text-muted);
      text-align: center;
    }
    .page-footer a { color: var(--text-muted); text-decoration: none; }
    .page-footer a:hover { color: var(--primary-light); }

    /* ===== RESPONSIVE ===== */
    @media (max-width: 480px) {
      .login-card { padding: 2rem 1.5rem 2rem; border-radius: 18px; }
    }
  </style>
</head>

<body>

  <!-- Back to landing -->
  <a href="index.html" class="back-link" id="backToHome">
    <i class="fas fa-arrow-left"></i> Accueil
  </a>

  <!-- ===== LOGIN CARD ===== -->
  <div class="login-card">

    <!-- Logo -->
    <div class="login-logo">
      <div class="logo-icon"><i class="fas fa-chart-line"></i></div>
      <div class="logo-name">G-Business</div>
      <div class="logo-tagline">Gérez votre business intelligemment</div>
    </div>

    <div class="card-heading">Bon retour 👋</div>
    <div class="card-subheading">Connectez-vous pour accéder à votre espace.</div>

    <!-- Error alert (PHP) -->
    <?php if ($error !== ''): ?>
    <div class="alert-dark-danger" id="loginError" role="alert">
      <i class="fas fa-circle-exclamation"></i>
      <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <!-- Login Form -->
    <form method="POST" action="login.php" id="loginForm" novalidate>

      <!-- Username -->
      <div class="mb-1">
        <label class="form-label-dark" for="username">Nom d'utilisateur</label>
      </div>
      <div class="input-group-dark">
        <i class="fas fa-user input-icon"></i>
        <input
          type="text"
          id="username"
          name="username"
          class="form-control-dark"
          placeholder="Entrez votre nom d'utilisateur"
          value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
          autocomplete="username"
          required
        />
      </div>

      <!-- Password -->
      <div class="mb-1 mt-3">
        <label class="form-label-dark" for="password">Mot de passe</label>
      </div>
      <div class="input-group-dark">
        <i class="fas fa-lock input-icon"></i>
        <input
          type="password"
          id="password"
          name="password"
          class="form-control-dark"
          placeholder="Entrez votre mot de passe"
          autocomplete="current-password"
          required
        />
        <button type="button" class="pw-toggle" id="pwToggle" aria-label="Afficher/masquer le mot de passe">
          <i class="fas fa-eye" id="pwToggleIcon"></i>
        </button>
      </div>

      <!-- Submit -->
      <button type="submit" class="btn-submit" id="loginBtn">
        <span class="spinner" id="loginSpinner"></span>
        <i class="fas fa-sign-in-alt" id="loginIcon"></i>
        <span id="loginBtnText">Se connecter</span>
      </button>

      <div class="form-divider">ou</div>

    </form>

    <!-- Signup link -->
    <div class="signup-row">
      Pas encore de compte ?
      <a href="signup.php" id="signupLink">Créer un compte</a>
    </div>
  </div>

  <!-- Page footer -->
  <div class="page-footer">
    &copy; <?= date('Y') ?> G-Business &nbsp;·&nbsp;
    <a href="#">Confidentialité</a> &nbsp;·&nbsp;
    <a href="#">Conditions d'utilisation</a>
  </div>

  <!-- Bootstrap 5 JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

  <script>
    // ---- Password show/hide toggle ----
    const pwToggle     = document.getElementById('pwToggle');
    const pwField      = document.getElementById('password');
    const pwToggleIcon = document.getElementById('pwToggleIcon');

    pwToggle.addEventListener('click', () => {
      const isPassword = pwField.type === 'password';
      pwField.type = isPassword ? 'text' : 'password';
      pwToggleIcon.classList.toggle('fa-eye',      !isPassword);
      pwToggleIcon.classList.toggle('fa-eye-slash', isPassword);
    });

    // ---- Loading state on submit ----
    document.getElementById('loginForm').addEventListener('submit', function (e) {
      const btn      = document.getElementById('loginBtn');
      const spinner  = document.getElementById('loginSpinner');
      const icon     = document.getElementById('loginIcon');
      const btnText  = document.getElementById('loginBtnText');

      spinner.style.display = 'block';
      icon.style.display    = 'none';
      btnText.textContent   = 'Connexion en cours…';
      btn.disabled = true;
    });

    // ---- Client-side validation feedback ----
    document.getElementById('loginForm').addEventListener('submit', function (e) {
      const username = document.getElementById('username').value.trim();
      const password = document.getElementById('password').value.trim();

      if (!username || !password) {
        e.preventDefault();
        // Reset loading state
        document.getElementById('loginBtn').disabled = false;
        document.getElementById('loginSpinner').style.display = 'none';
        document.getElementById('loginIcon').style.display    = '';
        document.getElementById('loginBtnText').textContent   = 'Se connecter';
      }
    }, true /* capture phase, runs before the loading handler */);
  </script>

</body>
</html>
