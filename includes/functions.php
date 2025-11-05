<?php
require_once '../vendor/autoload.php'; // For PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function generate_verification_code() {
    return sprintf("%06d", mt_rand(1, 999999));
}

function send_verification_email($email, $name, $verification_code) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Set your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'Stephenviray12@gmail.com'; // Your email
        $mail->Password = 'bubr nckn tgqf lvus'; // Your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('41004.FRSM@gmail.com', 'Fire & Rescue Services Management');
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Email Verification - Fire & Rescue Services Management';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #fffaf5; border: 1px solid #ffe4d6; border-radius: 10px; overflow: hidden;'>
                <div style='background: linear-gradient(135deg, #dc2626, #b91c1c); padding: 30px; text-align: center; color: white;'>
                    <h1 style='margin: 0; font-size: 28px;'>🚒 Fire & Rescue Services</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Emergency Response & Safety Management</p>
                </div>
                
                <div style='padding: 30px;'>
                    <h2 style='color: #dc2626; margin-top: 0;'>Email Verification</h2>
                    <p>Hello <strong>$name</strong>,</p>
                    <p>Thank you for registering with <strong>Fire & Rescue Services Management</strong>. Please use the verification code below to complete your registration:</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <div style='display: inline-block; padding: 15px 30px; background: #fef2f2; border: 2px dashed #dc2626; border-radius: 8px; font-family: monospace;'>
                            <h3 style='color: #dc2626; font-size: 28px; margin: 0; letter-spacing: 3px;'>$verification_code</h3>
                        </div>
                    </div>
                    
                    <div style='background: #fef2f2; padding: 15px; border-radius: 5px; border-left: 4px solid #dc2626;'>
                        <p style='margin: 0; color: #991b1b; font-size: 14px;'>
                            <strong>⚠️ Important:</strong> This code will expire in 15 minutes.
                        </p>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; margin-top: 25px;'>
                        If you did not request this registration, please ignore this email.
                    </p>
                </div>
                
                <div style='background: #fef2f2; padding: 20px; text-align: center; border-top: 1px solid #fecaca;'>
                    <p style='margin: 0; color: #991b1b;'>
                        <strong>🚨 Safety First:</strong> Always have an emergency evacuation plan and working smoke detectors.
                    </p>
                    <p style='margin: 10px 0 0 0; color: #666; font-size: 12px;'>
                        Best regards,<br>
                        <strong>Fire & Rescue Services Management Team</strong><br>
                        Protecting lives and property through emergency response
                    </p>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Hello $name,\n\nThank you for registering with Fire & Rescue Services Management. Please use the verification code below to complete your registration:\n\nVerification Code: $verification_code\n\nThis code will expire in 15 minutes.\n\nIf you did not request this registration, please ignore this email.\n\n🚨 Safety First: Always have an emergency evacuation plan and working smoke detectors.\n\nBest regards,\nFire & Rescue Services Management Team";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to send verification email with link (for login scenario)
function send_verification_email_with_link($email, $name, $verification_code) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'Stephenviray12@gmail.com';
        $mail->Password = 'bubr nckn tgqf lvus';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('41004.FRSM@gmail.com', 'Fire & Rescue Services Management');
        $mail->addAddress($email, $name);
        
        // Create verification link
        $verification_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/verify_email_login.php?email=" . urlencode($email) . "&code=" . $verification_code;
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Complete Your Login - Email Verification Required';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #fffaf5; border: 1px solid #ffe4d6; border-radius: 10px; overflow: hidden;'>
                <div style='background: linear-gradient(135deg, #dc2626, #b91c1c); padding: 30px; text-align: center; color: white;'>
                    <h1 style='margin: 0; font-size: 28px;'>🚒 Fire & Rescue Services</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Emergency Response & Safety Management</p>
                </div>
                
                <div style='padding: 30px;'>
                    <h2 style='color: #dc2626; margin-top: 0;'>Complete Your Login</h2>
                    <p>Hello <strong>$name</strong>,</p>
                    <p>We noticed you tried to login but your email address is not yet verified. To complete your login and access our emergency response services, please verify your email address:</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='$verification_link' style='display: inline-block; padding: 15px 40px; background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; border: none; cursor: pointer;'>
                            🚨 Verify Email Address
                        </a>
                    </div>
                    
                    <div style='background: #fef2f2; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 0 0 15px 0; color: #991b1b; font-weight: bold;'>Alternative Verification Method:</p>
                        <p style='margin: 0;'>Or copy and paste this link in your browser:</p>
                        <p style='margin: 10px 0; background: #fecaca; padding: 12px; border-radius: 5px; word-break: break-all; font-size: 12px; color: #991b1b;'>
                            <a href='$verification_link' style='color: #991b1b;'>$verification_link</a>
                        </p>
                        
                        <p style='margin: 15px 0 0 0;'>Your verification code is:</p>
                        <div style='text-align: center; margin: 15px 0;'>
                            <div style='display: inline-block; padding: 12px 25px; background: #fef2f2; border: 2px dashed #dc2626; border-radius: 6px;'>
                                <strong style='font-size: 24px; color: #dc2626; letter-spacing: 3px;'>$verification_code</strong>
                            </div>
                        </div>
                    </div>
                    
                    <div style='background: #fffbeb; padding: 15px; border-radius: 5px; border-left: 4px solid #f59e0b;'>
                        <p style='margin: 0; color: #92400e; font-size: 14px;'>
                            <strong>⏰ Time Sensitive:</strong> This verification link will expire in 30 minutes.
                        </p>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; margin-top: 25px;'>
                        If you didn't try to login to your account, please ignore this email to help us maintain security and emergency response integrity.
                    </p>
                </div>
                
                <div style='background: #fef2f2; padding: 20px; text-align: center; border-top: 1px solid #fecaca;'>
                    <p style='margin: 0; color: #991b1b; font-size: 14px;'>
                        <strong>🔥 Emergency Preparedness:</strong> Know your emergency exits and keep fire extinguishers accessible.
                    </p>
                    <p style='margin: 10px 0 0 0; color: #666; font-size: 12px;'>
                        Best regards,<br>
                        <strong>Fire & Rescue Services Management Team</strong><br>
                        Committed to saving lives and protecting communities
                    </p>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Hello $name,\n\nWe noticed you tried to login but your email address is not yet verified. To complete your login and access our emergency response services, please verify your email address.\n\nVerification Code: $verification_code\n\nOr visit this link: $verification_link\n\nEnter this code on the verification page to complete your login.\n\nThis verification code will expire in 30 minutes.\n\nIf you didn't try to login to your account, please ignore this email to help us maintain security and emergency response integrity.\n\n🔥 Emergency Preparedness: Know your emergency exits and keep fire extinguishers accessible.\n\nBest regards,\nFire & Rescue Services Management Team";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to send password reset email
function send_password_reset_email($email, $name, $reset_link) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'Stephenviray12@gmail.com';
        $mail->Password = 'bubr nckn tgqf lvus';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('41004.FRSM@gmail.com', 'Fire & Rescue Services Management');
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - Fire & Rescue Services Management';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #fffaf5; border: 1px solid #ffe4d6; border-radius: 10px; overflow: hidden;'>
                <div style='background: linear-gradient(135deg, #dc2626, #b91c1c); padding: 30px; text-align: center; color: white;'>
                    <h1 style='margin: 0; font-size: 28px;'>🚒 Fire & Rescue Services</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Emergency Response & Safety Management</p>
                </div>
                
                <div style='padding: 30px;'>
                    <h2 style='color: #dc2626; margin-top: 0;'>Password Reset Request</h2>
                    <p>Hello <strong>$name</strong>,</p>
                    <p>We received a request to reset your password for your Fire & Rescue Services Management account. To ensure the security of your emergency response access, please use the link below to create a new password:</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='$reset_link' style='display: inline-block; padding: 15px 40px; background: linear-gradient(135deg, #dc2626, #b91c1c); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; border: none; cursor: pointer;'>
                            🔐 Reset Your Password
                        </a>
                    </div>
                    
                    <div style='background: #fef2f2; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 0 0 15px 0; color: #991b1b; font-weight: bold;'>Alternative Method:</p>
                        <p style='margin: 0;'>If the button doesn't work, copy and paste this link in your browser:</p>
                        <p style='margin: 10px 0; background: #fecaca; padding: 12px; border-radius: 5px; word-break: break-all; font-size: 12px; color: #991b1b;'>
                            <a href='$reset_link' style='color: #991b1b;'>$reset_link</a>
                        </p>
                    </div>
                    
                    <div style='background: #fffbeb; padding: 15px; border-radius: 5px; border-left: 4px solid #f59e0b;'>
                        <p style='margin: 0; color: #92400e; font-size: 14px;'>
                            <strong>⏰ Urgent Security Notice:</strong> This reset link will expire in 1 hour for your protection.
                        </p>
                    </div>
                    
                    <div style='background: #f0f9ff; padding: 15px; border-radius: 5px; border-left: 4px solid #0ea5e9; margin-top: 15px;'>
                        <p style='margin: 0; color: #0369a1; font-size: 14px;'>
                            <strong>🔒 Security Tip:</strong> If you didn't request this password reset, please ignore this email and ensure your account credentials remain secure.
                        </p>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; margin-top: 25px;'>
                        For security reasons, please do not share this email with anyone. Our team will never ask for your password.
                    </p>
                </div>
                
                <div style='background: #fef2f2; padding: 20px; text-align: center; border-top: 1px solid #fecaca;'>
                    <p style='margin: 0; color: #991b1b; font-size: 14px;'>
                        <strong>🚨 Emergency Preparedness:</strong> Keep your emergency access credentials secure and up-to-date.
                    </p>
                    <p style='margin: 10px 0 0 0; color: #666; font-size: 12px;'>
                        Best regards,<br>
                        <strong>Fire & Rescue Services Management Team</strong><br>
                        Protecting lives and property through secure emergency response
                    </p>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Hello $name,\n\nWe received a request to reset your password for your Fire & Rescue Services Management account.\n\nTo reset your password, please visit this link:\n$reset_link\n\nThis reset link will expire in 1 hour for your protection.\n\nIf you didn't request this password reset, please ignore this email and ensure your account credentials remain secure.\n\nFor security reasons, please do not share this email with anyone. Our team will never ask for your password.\n\n🚨 Emergency Preparedness: Keep your emergency access credentials secure and up-to-date.\n\nBest regards,\nFire & Rescue Services Management Team";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Password reset email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to get user role for dashboard redirection
function getUserRoleDashboard($role) {
    switch($role) {
        case 'ADMIN':
            return '../admin/admin_dashboard.php';
        case 'EMPLOYEE':
            return '../employee/employee_dashboard.php';
        case 'USER':
        default:
            return '../user/user_dashboard.php';
    }
}

// Additional fire safety themed functions for fire and rescue management
function calculate_response_time_score($incident_data) {
    $score = 100;
    
    // Deduct points based on response time
    if ($incident_data['response_time_minutes'] > 10) $score -= 40;
    elseif ($incident_data['response_time_minutes'] > 5) $score -= 20;
    elseif ($incident_data['response_time_minutes'] <= 3) $score += 10;
    
    // Add points for preparedness
    if ($incident_data['proper_equipment']) $score += 15;
    if ($incident_data['trained_personnel']) $score += 20;
    if ($incident_data['effective_coordination']) $score += 25;
    
    // Consider incident severity
    $severity_factor = 1.0;
    if ($incident_data['severity'] === 'HIGH') $severity_factor = 1.2;
    if ($incident_data['severity'] === 'CRITICAL') $severity_factor = 1.5;
    
    $score *= $severity_factor;
    
    return max(0, min(100, round($score)));
}

function get_fire_safety_tip() {
    $tips = [
        "🔥 Install smoke detectors on every level of your home and test them monthly!",
        "🚒 Know two ways out of every room and practice your family escape plan!",
        "🧯 Keep fire extinguishers accessible and learn how to use them properly!",
        "🚭 Never leave cooking unattended - it's the leading cause of home fires!",
        "⚡ Check electrical cords for damage and don't overload outlets!",
        "🕯️ Keep candles away from flammable materials and never leave them burning unattended!",
        "🧹 Clear clutter from hallways and exits for safe emergency evacuation!",
        "📞 Teach everyone in your household how to call emergency services!"
    ];
    
    return $tips[array_rand($tips)];
}

function assess_emergency_severity($incident_type, $affected_area, $potential_risk) {
    $severity_score = 0;
    
    // Base score from incident type
    $type_scores = [
        'structure_fire' => 90,
        'vehicle_fire' => 70,
        'medical_emergency' => 80,
        'hazardous_material' => 85,
        'rescue_operation' => 75,
        'false_alarm' => 10
    ];
    
    $severity_score += $type_scores[$incident_type] ?? 50;
    
    // Adjust based on affected area
    if ($affected_area === 'residential') $severity_score += 15;
    if ($affected_area === 'commercial') $severity_score += 20;
    if ($affected_area === 'industrial') $severity_score += 25;
    
    // Adjust based on potential risk
    if ($potential_risk === 'high_population') $severity_score += 30;
    if ($potential_risk === 'critical_infrastructure') $severity_score += 35;
    if ($potential_risk === 'environmental_hazard') $severity_score += 40;
    
    return max(10, min(100, $severity_score));
}

// Function to validate password strength
function validate_password_strength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    return $errors;
}

// Function to generate secure random token
function generate_secure_token($length = 32) {
    return bin2hex(random_bytes($length));
}

// Function to check if token is expired
function is_token_expired($expiry_time) {
    return strtotime($expiry_time) < time();
}

// Function to redirect with message
function redirect_with_message($url, $message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header("Location: $url");
    exit();
}

// Function to display flash message
function display_flash_message() {
    if (isset($_SESSION['flash_message'])) {
        $message = $_SESSION['flash_message'];
        $type = $_SESSION['flash_type'] ?? 'success';
        
        $alert_class = $type === 'success' ? 'alert-success' : 'alert-danger';
        
        echo "<div class='alert $alert_class alert-dismissible fade show' role='alert'>
                $message
                <button type='button' class='btn-close' data-bs-dismiss='alert' aria-label='Close'></button>
              </div>";
        
        // Clear the message after displaying
        unset($_SESSION['flash_message'], $_SESSION['flash_type']);
    }
}
?>