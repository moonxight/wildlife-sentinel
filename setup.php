<?php
require_once 'includes/functions.php';

// Check if admin already exists
$pdo = getDB();
$stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'admin'");
$adminExists = $stmt->fetch()['count'] > 0;

if ($adminExists) {
    header('Location: login.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = sanitize($_POST['full_name']);
    $email = sanitize($_POST['email']);
    $phone = sanitize($_POST['phone']);
    $password = $_POST['password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (empty($fullName) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields';
    } elseif (!validateEmail($email)) {
        $error = 'Please enter a valid email address';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        // Check if email already exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email already exists. Please use a different email.';
        } else {
            // Create admin account
            $hash = hashPassword($password);
            
            // First create a default zone
            $pdo->exec("
                INSERT INTO zones (name, description, center_lat, center_lng, is_active) 
                VALUES ('Headquarters', 'Main Administrative Zone', -15.3875, 28.3228, 1)
            ");
            $zoneId = $pdo->query('SELECT lastval()')->fetchColumn();
            
            // Create admin user
            $stmt = $pdo->prepare("
                INSERT INTO users (email, phone, password_hash, full_name, role, zone_id, is_active, created_at)
                VALUES (?, ?, ?, ?, 'admin', ?, 1, NOW())
            ");
            
            if ($stmt->execute([$email, $phone, $hash, $fullName, $zoneId])) {
                $success = 'Admin account created successfully! You can now login.';
                logAudit($stmt->rowCount(), 'create_admin', ['email' => $email]);
            } else {
                $error = 'Failed to create admin account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Admin - Wildlife Sentinel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #0d3b22, #1a5c3a);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .setup-wrapper {
            width: 100%;
            max-width: 480px;
        }
        
        .setup-box {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 40px 35px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .setup-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .setup-header .logo-icon {
            font-size: 48px;
            display: block;
            margin-bottom: 10px;
        }
        
        .setup-header h1 {
            font-size: 24px;
            color: #0d3b22;
            font-weight: 700;
        }
        
        .setup-header p {
            color: #6c757d;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: #495057;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 14px;
            transition: all 0.3s;
            background: white;
        }
        
        .form-control:focus {
            border-color: #1a5c3a;
            outline: none;
            box-shadow: 0 0 0 4px rgba(26, 92, 58, 0.1);
        }
        
        .btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1a5c3a, #2d8a4e);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            letter-spacing: 0.5px;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(26, 92, 58, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
            box-shadow: 0 8px 25px rgba(108, 117, 125, 0.3);
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 10px;
            margin-bottom: 18px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        
        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        
        .setup-footer {
            text-align: center;
            margin-top: 20px;
            color: #6c757d;
            font-size: 13px;
        }
        
        .setup-footer a {
            color: #1a5c3a;
            text-decoration: none;
            font-weight: 600;
        }
        
        .setup-footer a:hover {
            text-decoration: underline;
        }
        
        .requirements {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #6c757d;
        }
        
        .requirements ul {
            margin-top: 5px;
            padding-left: 20px;
        }
        
        .requirements li {
            margin: 3px 0;
        }
        
        @media (max-width: 480px) {
            .setup-box {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="setup-wrapper">
        <div class="setup-box">
            <div class="setup-header">
                <span class="logo-icon">🔑</span>
                <h1>Create Admin Account</h1>
                <p>Set up your Wildlife Sentinel administrator account</p>
            </div>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    ✅ <?= $success ?>
                </div>
                <div style="text-align:center;margin-top:20px;">
                    <a href="login.php" class="btn">Go to Login →</a>
                </div>
            <?php else: ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger">❌ <?= $error ?></div>
                <?php endif; ?>
                
                <div class="requirements">
                    <strong>📋 Requirements:</strong>
                    <ul>
                        <li>✅ Email must be valid</li>
                        <li>✅ Password must be at least 8 characters</li>
                        <li>✅ Passwords must match</li>
                    </ul>
                </div>
                
                <form method="POST">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" class="form-control" 
                               placeholder="Enter your full name" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Email Address *</label>
                        <input type="email" name="email" class="form-control" 
                               placeholder="Enter your email" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" class="form-control" 
                               placeholder="Enter your phone number">
                    </div>
                    
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" class="form-control" 
                               placeholder="Enter password (min 8 characters)" required minlength="8">
                    </div>
                    
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" class="form-control" 
                               placeholder="Confirm your password" required minlength="8">
                    </div>
                    
                    <button type="submit" class="btn">🔐 Create Admin Account</button>
                </form>
                
                <div class="setup-footer">
                    <p>Already have an account? <a href="login.php">Login here</a></p>
                </div>
            <?php endif; ?>
            
            <div class="setup-footer" style="margin-top:15px;font-size:12px;color:#adb5bd;">
                🇿🇲 Wildlife Sentinel v2.0 - Zambia Wildlife Protection System
            </div>
        </div>
    </div>
</body>
</html>