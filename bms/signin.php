<?php
require_once __DIR__ . '/config/paths.php';

session_start(); // Start the session at the very beginning
require_once BASE_PATH . 'includes/db_connection.php'; // Include the database connection file
require_once BASE_PATH . 'includes/functions.php';

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$company = getCompanyInfo($conn);
$signin_logo = !empty($company['logo_path']) ? BASE_URL . $company['logo_path'] : '';

// Initialize error message variable
$error_message = "";

// Check if form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $login = trim($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']); // Check if "Remember Me" is checked

    if (empty($login)) {
        $error_message = "Please enter your email or username.";
    } else {
        // Query to check if user exists with the given email OR username
        $sql = "SELECT * FROM users WHERE email = ? OR username = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();

        // Check if user exists
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            // Check if user is active
            if ($user['status'] != 'active') {
                $error_message = "Your account is inactive. Please contact support.";
            } else {
                // Verify the hashed password
                if (password_verify($password, $user['password']) || $password == $user['password']) { // Second condition for testing only, remove in production
                    // Password is correct, start session
                    $_SESSION['user'] = $user['email'];
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['position_id'] = $user['position_id'] ?? null;
                    $_SESSION['is_approver'] = false;
                    if (!empty($user['position_id'])) {
                        $posStmt = $conn->prepare("SELECT is_approver FROM positions WHERE id = ?");
                        $posStmt->bind_param("i", $user['position_id']);
                        $posStmt->execute();
                        $posResult = $posStmt->get_result();
                        if ($posRow = $posResult->fetch_assoc()) {
                            $_SESSION['is_approver'] = (bool)$posRow['is_approver'];
                        }
                    }
                    $_SESSION['user_access'] = [];
                    if (!empty($user['access'])) {
                        $decoded = json_decode($user['access'], true);
                        if (is_array($decoded)) {
                            $_SESSION['user_access'] = $decoded;
                        }
                    }
                    $_SESSION['logged_in'] = true;

                    // Handle "Remember Me" by setting cookies
                    if ($remember) {
                        setcookie("email", $user['email'], time() + (86400 * 30), "/"); // 30 days
                    } else {
                        // Clear cookie if "Remember Me" is unchecked
                        setcookie("email", "", time() - 3600, "/");
                    }

                    header("Location: " . BASE_URL . "index.php");
                    exit();
                } else {
                    $error_message = "Invalid password.";
                }
            }
        } else {
            $error_message = "No user found with that email or username.";
        }

        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In</title>
    <!-- FAVICON -->
    <?php if (!empty($company['favicon_path'])): ?>
    <link rel="icon" href="<?= BASE_URL . htmlspecialchars($company['favicon_path']) ?>" type="image/png">
    <?php endif; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
        }

        #flare-canvas {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        .split-layout {
            display: flex;
            width: 100%;
            min-height: 100vh;
        }

        /* LEFT PANEL */
        .split-left {
            flex: 1;
            background: #0b3354;
            color: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 60px 40px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .split-left::before {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.04);
        }

        .split-left::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.03);
        }

        .split-left .deco-dots {
            position: absolute;
            top: 30px;
            left: 30px;
            display: grid;
            grid-template-columns: repeat(4, 6px);
            gap: 10px;
        }

        .split-left .deco-dots span {
            width: 6px;
            height: 6px;
            background: rgba(255,255,255,0.15);
            border-radius: 50%;
        }

        .split-left .deco-line {
            position: absolute;
            bottom: 40px;
            right: 40px;
            width: 50px;
            height: 3px;
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
        }

        .hero-image {
            width: 260px;
            height: auto;
            margin-bottom: 28px;
            filter: drop-shadow(0 8px 20px rgba(0,0,0,0.3));
        }

        .split-left h1 {
            font-size: 32px;
            font-weight: 700;
            margin: 0 0 10px;
            letter-spacing: -0.5px;
        }

        .split-left p {
            font-size: 15px;
            color: rgba(255,255,255,0.7);
            font-weight: 300;
            line-height: 1.6;
            max-width: 360px;
        }

        .split-left .brand-footer {
            position: absolute;
            bottom: 30px;
            font-size: 12px;
            color: rgba(255,255,255,0.35);
            letter-spacing: 1px;
        }

        /* RIGHT PANEL */
        .split-right {
            flex: 1;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 60px 50px;
        }

        .form-container {
            max-width: 400px;
            width: 100%;
            margin: 0 auto;
        }

        .split-right h2 {
            font-size: 26px;
            margin: 0 0 6px 0;
            color: #1a1a2e;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .split-right .subtitle {
            font-size: 14px;
            color: #888;
            margin-bottom: 32px;
        }

        .input-group {
            margin-bottom: 18px;
        }

        .input-group label {
            font-size: 13px;
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
            display: block;
        }

        .input-icon-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-icon-wrapper i {
            position: absolute;
            left: 14px;
            color: #aaa;
            font-size: 14px;
            transition: color 0.3s;
            z-index: 1;
        }

        .input-icon-wrapper input {
            width: 100%;
            padding: 13px 14px 13px 40px;
            font-size: 15px;
            border: 2px solid #e8e8e8;
            border-radius: 10px;
            transition: all 0.3s ease;
            background: #fafafa;
            font-family: 'Inter', sans-serif;
        }

        .input-icon-wrapper input:focus {
            border-color: #0b3354;
            outline: none;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(11, 51, 84, 0.08);
        }

        .input-icon-wrapper input:focus ~ i,
        .input-icon-wrapper input:focus + i {
            color: #0b3354;
        }

        .password-container {
            position: relative;
            display: flex;
            align-items: center;
        }

        .password-container .input-icon-wrapper {
            flex: 1;
        }

        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #aaa;
            z-index: 2;
            background: none;
            border: none;
            padding: 4px;
            font-size: 15px;
            transition: color 0.3s;
        }

        .password-toggle:hover {
            color: #0b3354;
        }

        .password-container input {
            padding-right: 46px;
        }

        .input-group.remember-me {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
        }

        .input-group.remember-me label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #666;
            font-weight: 400;
            text-transform: none;
            letter-spacing: 0;
            cursor: pointer;
        }

        .input-group.remember-me input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #0b3354;
            cursor: pointer;
        }

        .split-right button {
            width: 100%;
            padding: 14px;
            font-size: 15px;
            font-weight: 600;
            background: linear-gradient(135deg, #0b3354, #1a5a8a);
            color: white;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
            letter-spacing: 0.3px;
            box-shadow: 0 4px 15px rgba(11, 51, 84, 0.3);
        }

        .split-right button:hover {
            background: linear-gradient(135deg, #08263f, #154b75);
            box-shadow: 0 6px 20px rgba(11, 51, 84, 0.4);
            transform: translateY(-1px);
        }

        .split-right button:active {
            transform: translateY(0);
        }

        .split-right .signup-link {
            text-align: center;
            margin-top: 22px;
            font-size: 14px;
            color: #888;
        }

        .split-right .signup-link a {
            color: #0b3354;
            text-decoration: none;
            font-weight: 600;
        }

        .error-message {
            color: #e03131;
            background-color: #fff5f5;
            border: 1px solid #ffc9c9;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 20px;
            text-align: left;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .error-message i {
            font-size: 16px;
            flex-shrink: 0;
        }

        @media (max-width: 768px) {
            .split-layout {
                flex-direction: column;
            }
            .split-left {
                padding: 50px 30px;
                min-height: 40vh;
            }
            .split-right {
                padding: 40px 30px;
                min-height: 60vh;
            }
            .hero-image {
                width: 180px;
            }
        }
    </style>
    <?php require_once BASE_PATH . 'includes/loader.php'; ?>
</head>

<body>
    <canvas id="flare-canvas"></canvas>
    <div class="split-layout">
        <div class="split-left">
            <div class="deco-dots">
                <span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span>
                <span></span><span></span><span></span><span></span>
            </div>
            <div class="deco-line"></div>
            <?php if (!empty($signin_logo)): ?>
            <img src="<?= htmlspecialchars($signin_logo) ?>" alt="Logo" class="hero-image">
            <?php endif; ?>
            <h1>Welcome Welcome BackKKK</h1>
            <p>Sign in to access your dashboard and manage your business.</p>
            <div class="brand-footer">© <?= date('Y') ?> BMS</div>
        </div>
        <div class="split-right">
            <div class="form-container">
                <h2>Sign In</h2>
                <p class="subtitle">Enter your credentials to continue</p>
                <?php if (!empty($error_message)): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                    <div class="input-group">
                        <label for="email">Email or Username</label>
                        <div class="input-icon-wrapper">
                            <i class="fas fa-envelope"></i>
                            <input type="text" id="email" name="email"
                                value="<?php echo isset($_COOKIE['email']) ? $_COOKIE['email'] : ''; ?>" placeholder="email@example.com or username" required>
                        </div>
                    </div>
                    <div class="input-group">
                        <label for="password">Password</label>
                        <div class="password-container">
                            <div class="input-icon-wrapper">
                                <i class="fas fa-lock"></i>
                                <input type="password" id="password" name="password" placeholder="Enter your password" required>
                            </div>
                            <span class="password-toggle" id="togglePassword">
                                <i class="fas fa-eye"></i>
                            </span>
                        </div>
                    </div>
                    <div class="input-group remember-me">
                        <label>
                            <input type="checkbox" name="remember" <?php echo isset($_COOKIE['email']) ? 'checked' : ''; ?>>
                            Remember me
                        </label>
                    </div>
                    <button type="submit">Sign In</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Light leak / lens flare animation
        document.addEventListener('DOMContentLoaded', function() {
            const canvas = document.getElementById('flare-canvas');
            const ctx = canvas.getContext('2d');
            let flares = [];
            let animationId;

            function resize() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
            }

            window.addEventListener('resize', resize);
            resize();

            const palette = [
                { r: 59, g: 130, b: 246 },  // blue
                { r: 59, g: 91, b: 219 },   // blue
                { r: 139, g: 92, b: 246 },  // purple
                { r: 236, g: 72, b: 153 },  // pink
            ];

            class Flare {
                constructor() {
                    this.reset();
                }

                reset() {
                    const c = palette[Math.floor(Math.random() * palette.length)];
                    this.r = c.r;
                    this.g = c.g;
                    this.b = c.b;
                    this.x = Math.random() * canvas.width;
                    this.y = Math.random() * canvas.height;
                    this.angle = Math.random() * Math.PI * 2;
                    this.length = Math.random() * 300 + 200;
                    this.width = Math.random() * 40 + 15;
                    this.opacity = Math.random() * 0.04 + 0.01;
                    this.speed = Math.random() * 0.15 + 0.05;
                    this.drift = (Math.random() - 0.5) * 0.02;
                }

                update() {
                    this.x += Math.cos(this.angle) * this.speed;
                    this.y += Math.sin(this.angle) * this.speed - 0.05;
                    this.angle += this.drift;

                    if (this.y + this.length < -100) {
                        this.y = canvas.height + 100;
                        this.x = Math.random() * canvas.width;
                        this.angle = (Math.random() - 0.5) * 0.5;
                    }
                    if (this.x < -200) this.x = canvas.width + 200;
                    if (this.x > canvas.width + 200) this.x = -200;
                }

                draw() {
                    const endX = this.x + Math.cos(this.angle) * this.length;
                    const endY = this.y + Math.sin(this.angle) * this.length;

                    const gradient = ctx.createLinearGradient(this.x, this.y, endX, endY);
                    gradient.addColorStop(0, `rgba(${this.r}, ${this.g}, ${this.b}, ${this.opacity})`);
                    gradient.addColorStop(0.3, `rgba(${this.r}, ${this.g}, ${this.b}, ${this.opacity * 0.6})`);
                    gradient.addColorStop(1, `rgba(${this.r}, ${this.g}, ${this.b}, 0)`);

                    ctx.save();
                    ctx.translate(this.x, this.y);
                    ctx.rotate(this.angle);
                    ctx.fillStyle = gradient;
                    ctx.fillRect(0, -this.width / 2, this.length, this.width);
                    ctx.restore();
                }
            }

            const count = Math.min(6, Math.max(3, Math.floor(canvas.width * canvas.height / 80000)));

            for (let i = 0; i < count; i++) {
                flares.push(new Flare());
            }

            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                for (const f of flares) {
                    f.update();
                    f.draw();
                }

                animationId = requestAnimationFrame(animate);
            }

            animate();
        });

        // Password toggle
        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.getElementById('togglePassword');
            const passwordInput = document.getElementById('password');

            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            });
        });
    </script>
</body>

</html>