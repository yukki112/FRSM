<?php
require_once 'config/db_connection.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Commonwealth Fire & Rescue Services</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" 
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" 
          crossorigin=""/>
    <style>
        /* <CHANGE> Completely redesigned modern landing page with premium aesthetics */
        :root {
            --primary-color: #dc2626;
            --primary-dark: #991b1b;
            --primary-light: #fef2f2;
            --secondary-color: #1e40af;
            --accent-color: #f59e0b;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --background-color: #f8fafc;
            --card-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.25);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.2);
            --card-shadow: 0 20px 60px -5px rgba(0, 0, 0, 0.08);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
            background: linear-gradient(135deg, #f0f9ff 0%, #f5f3ff 50%, #fef2f2 100%);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Enhanced header with glassmorphism */
        header {
            padding: 20px 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            background-color: rgba(255, 255, 255, 0.85);
            border-bottom: 1px solid var(--glass-border);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            transition: all 0.3s ease;
        }

        header.scrolled {
            padding: 15px 0;
            background-color: rgba(255, 255, 255, 0.97);
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.1);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logo-icon {
            width: 60px;
            height: 60px;
            border-radius: 20%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.3);
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            transition: all 0.3s ease;
        }

        .logo-icon:hover {
            transform: scale(1.05);
        }

        .logo-text h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-color);
        }

        .logo-text p {
            font-size: 0.8rem;
            color: var(--text-light);
        }

        .nav-buttons {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 10px 25px;
            border-radius: 50px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-size: 0.9rem;
            border: none;
        }

        .btn-login {
            background: transparent;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }

        .btn-register {
            background: var(--primary-color);
            color: white;
            border: 2px solid var(--primary-color);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.3);
        }

        .btn-login:hover {
            background: var(--primary-light);
            transform: translateY(-2px);
        }

        .btn-register:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(220, 38, 38, 0.4);
        }

        /* Hero Section */
        .hero {
            padding: 180px 0 100px;
            text-align: center;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(240, 249, 255, 0.9) 0%, rgba(245, 243, 255, 0.8) 50%, rgba(254, 242, 242, 0.9) 100%);
        }

        .hero-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.15;
        }

        .hero-bg::before {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: var(--primary-color);
            top: 5%;
            left: 5%;
            filter: blur(100px);
        }

        .hero-bg::after {
            content: '';
            position: absolute;
            width: 400px;
            height: 400px;
            border-radius: 50%;
            background: var(--secondary-color);
            bottom: 5%;
            right: 5%;
            filter: blur(100px);
        }

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1.2;
            letter-spacing: -1px;
        }

        .hero p {
            font-size: 1.2rem;
            max-width: 700px;
            margin: 0 auto 40px;
            color: var(--text-light);
        }

        .hero-buttons {
            display: flex;
            gap: 20px;
            justify-content: center;
            margin-top: 30px;
            flex-wrap: wrap;
        }

        .btn-emergency {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            padding: 16px 35px;
            border-radius: 50px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 12px 35px rgba(220, 38, 38, 0.4);
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            text-decoration: none;
            font-size: 1rem;
        }

        .btn-emergency:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 45px rgba(220, 38, 38, 0.5);
            background: linear-gradient(135deg, var(--primary-dark), #7f1d1d);
        }

        .btn-services {
            background: transparent;
            color: var(--secondary-color);
            border: 2px solid var(--secondary-color);
            padding: 15px 35px;
            border-radius: 50px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            font-size: 1rem;
        }

        .btn-services:hover {
            background: var(--secondary-color);
            color: white;
            transform: translateY(-4px);
            box-shadow: 0 12px 35px rgba(30, 64, 175, 0.3);
        }

        /* Services Section */
        .services {
            padding: 120px 0;
        }

        .section-title {
            text-align: center;
            margin-bottom: 70px;
        }

        .section-title h2 {
            font-size: 2.8rem;
            font-weight: 800;
            color: var(--text-color);
            margin-bottom: 15px;
            position: relative;
            display: inline-block;
        }

        .section-title h2::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 50%;
            transform: translateX(-50%);
            width: 100px;
            height: 5px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border-radius: 3px;
        }

        .section-title p {
            font-size: 1.1rem;
            color: var(--text-light);
            max-width: 600px;
            margin: 0 auto;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 35px;
        }

        .service-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 35px;
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid var(--glass-border);
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
            position: relative;
            overflow: hidden;
        }

        .service-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
        }

        .service-card:hover {
            transform: translateY(-12px);
            box-shadow: 0 30px 60px rgba(31, 38, 135, 0.2);
            border-color: var(--primary-light);
        }

        .service-icon {
            width: 75px;
            height: 75px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-light), #ffe4e6);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 25px;
            color: var(--primary-color);
            font-size: 32px;
            box-shadow: 0 10px 25px rgba(220, 38, 38, 0.15);
            transition: all 0.3s ease;
        }

        .service-card:hover .service-icon {
            transform: scale(1.15);
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            box-shadow: 0 15px 35px rgba(220, 38, 38, 0.3);
        }

        .service-card h3 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--text-color);
        }

        .service-card p {
            color: var(--text-light);
            margin-bottom: 20px;
            flex-grow: 1;
        }

        .service-link {
            color: var(--primary-color);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .service-link:hover {
            gap: 15px;
        }

        /* Volunteer Section */
        .volunteer-section {
            padding: 120px 0;
            background: linear-gradient(135deg, rgba(248, 250, 252, 0.8) 0%, rgba(226, 232, 240, 0.8) 100%);
        }

        /* Map Section */
        .map-section {
            padding: 120px 0;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }

        .map-container {
            display: flex;
            gap: 50px;
            align-items: center;
        }

        .map-info {
            flex: 1;
        }

        .map-info h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 20px;
            color: var(--text-color);
            position: relative;
            display: inline-block;
        }

        .map-info h2::after {
            content: '';
            position: absolute;
            bottom: -15px;
            left: 0;
            width: 100px;
            height: 5px;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border-radius: 3px;
        }

        .map-info p {
            color: var(--text-light);
            margin-bottom: 35px;
        }

        .contact-details {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid var(--glass-border);
            box-shadow: var(--card-shadow);
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 18px;
            margin-bottom: 20px;
        }

        .contact-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-light), #ffe4e6);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            box-shadow: 0 8px 20px rgba(220, 38, 38, 0.15);
            font-size: 20px;
            flex-shrink: 0;
        }

        .contact-text h4 {
            font-size: 1.05rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .contact-text p {
            font-size: 0.95rem;
            color: var(--text-light);
            margin: 0;
        }

        .map-wrapper {
            flex: 1;
            height: 450px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--glass-border);
        }

        #map {
            width: 100%;
            height: 100%;
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, #1f2937, #111827);
            color: #f9fafb;
            padding: 80px 0 40px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 50px;
            margin-bottom: 50px;
        }

        .footer-column h3 {
            font-size: 1.4rem;
            margin-bottom: 25px;
            position: relative;
            padding-bottom: 12px;
            font-weight: 700;
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 60px;
            height: 4px;
            background: var(--primary-color);
            border-radius: 2px;
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 12px;
        }

        .footer-links a {
            color: rgba(249, 250, 251, 0.8);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-links a:hover {
            color: var(--primary-color);
            transform: translateX(6px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 35px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.95rem;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        @keyframes slideUp {
            from {
                transform: translateY(30px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            width: 90%;
            max-width: 550px;
            padding: 50px 40px;
            text-align: center;
            position: relative;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        .modal-content button {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            font-size: 28px;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-content button:hover {
            color: var(--primary-color);
            transform: scale(1.2);
        }

        .modal-content h3 {
            color: var(--text-color);
            margin-bottom: 15px;
            font-size: 2rem;
        }

        .modal-content p {
            color: var(--text-light);
            margin-bottom: 25px;
        }

        .loading-bar {
            width: 100%;
            height: 5px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 20px;
        }

        #loadingBar {
            width: 0%;
            height: 100%;
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            transition: width 1.5s ease-in-out;
        }

        .spinner {
            font-size: 70px;
            color: var(--primary-color);
            margin-bottom: 25px;
            animation: spin 2s linear infinite;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .hero h1 {
                font-size: 2.8rem;
            }
            
            .map-container {
                flex-direction: column;
                gap: 40px;
            }
            
            .map-info, .map-wrapper {
                width: 100%;
            }

            .services-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .hero {
                padding: 140px 0 70px;
            }

            .hero h1 {
                font-size: 2.2rem;
            }
            
            .hero p {
                font-size: 1rem;
            }
            
            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .section-title h2 {
                font-size: 2rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 20px;
            }
            
            .nav-buttons {
                width: 100%;
                justify-content: center;
            }

            .services-grid {
                grid-template-columns: 1fr;
            }

            .footer-content {
                gap: 30px;
            }
        }

        @media (max-width: 576px) {
            .hero {
                padding: 120px 0 60px;
            }

            .hero h1 {
                font-size: 1.8rem;
            }
            
            .services-grid {
                grid-template-columns: 1fr;
            }
            
            .service-card {
                padding: 25px;
            }

            .map-wrapper {
                height: 350px;
            }

            .modal-content {
                width: 95%;
                padding: 40px 25px;
            }

            .modal-content h3 {
                font-size: 1.6rem;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <div class="logo-icon">
                        <i class="fas fa-fire-extinguisher"></i>
                    </div>
                    <div class="logo-text">
                        <h1>Barangay Commonwealth</h1>
                        <p>Fire & Rescue Services</p>
                    </div>
                </div>
                <div class="nav-buttons">
                    <a href="login/login.php" class="btn btn-login">Login</a>
                    <a href="login/register.php" class="btn btn-register">Register</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-bg"></div>
        <div class="container">
            <h1>Your Safety Is Our Priority</h1>
            <p>Barangay Commonwealth Fire & Rescue Services is committed to providing prompt emergency response, community safety education, and proactive incident prevention.</p>
            <div class="hero-buttons">
                <a href="#" class="btn btn-emergency">
                    <i class="fas fa-phone-alt"></i>
                    Emergency Hotline: 911
                </a>
                <a href="#services" class="btn btn-services">
                    <i class="fas fa-concierge-bell"></i>
                    Our Services
                </a>
            </div>
        </div>
    </section>

    <!-- Services Section -->
    <section class="services" id="services">
        <div class="container">
            <div class="section-title">
                <h2>Our Services</h2>
                <p>Comprehensive fire safety and emergency response services for the Barangay Commonwealth community</p>
            </div>
            <div class="services-grid">
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <h3>Incident Reporting</h3>
                    <p>Report fire incidents, accidents, or emergencies through our streamlined incident reporting system for immediate response.</p>
                    <a href="#" class="service-link">
                        Report Incident <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <h3>Volunteer Program</h3>
                    <p>Join our community of dedicated firefighter volunteers and make a difference in emergency response and preparedness.</p>
                    <a href="#volunteer" class="service-link">
                        Sign Up Now <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-bullhorn"></i>
                    </div>
                    <h3>Announcements & Alerts</h3>
                    <p>Stay informed with the latest safety advisories, emergency alerts, and community announcements.</p>
                    <a href="#" class="service-link">
                        View Alerts <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-graduation-cap"></i>
                    </div>
                    <h3>Training & Seminars</h3>
                    <p>Participate in fire safety training, first aid workshops, and emergency preparedness seminars.</p>
                    <a href="#" class="service-link">
                        Register Now <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <h3>Community Feedback</h3>
                    <p>Share your suggestions, feedback, and ideas to help us improve our services.</p>
                    <a href="#" class="service-link">
                        Provide Feedback <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Volunteer Program Section -->
    <section class="volunteer-section" id="volunteer">
        <div class="container">
            <div class="section-title">
                <h2>Join Our Volunteer Program</h2>
                <p>Become a part of our dedicated team of emergency responders and make a difference in our community</p>
            </div>
            
            <div class="volunteer-content">
                <?php
                $status_query = "SELECT status FROM volunteer_registration_status ORDER BY updated_at DESC LIMIT 1";
                $status_result = $pdo->query($status_query);
                $registration_status = $status_result->fetch();
                
                if (!$registration_status || $registration_status['status'] === 'closed') {
                ?>
                    <div style="text-align: center; padding: 70px 50px; background: rgba(255, 255, 255, 0.97); border-radius: 20px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.25);">
                        <div style="font-size: 100px; color: #cbd5e1; margin-bottom: 25px;">
                            <i class="fas fa-lock"></i>
                        </div>
                        <h3 style="color: #475569; margin-bottom: 20px; font-size: 2rem; font-weight: 800;">Volunteer Registration Closed</h3>
                        <p style="color: #64748b; font-size: 1.1rem; max-width: 600px; margin: 0 auto 35px; line-height: 1.7;">
                            We are not currently accepting new volunteer applications. Please check back later for updates on when registration will reopen.
                        </p>
                        <div style="background: linear-gradient(135deg, #f0f9ff 0%, #eff6ff 100%); padding: 25px; border-radius: 15px; display: inline-block; border-left: 5px solid #3b82f6;">
                            <p style="margin: 0; color: #1e40af; font-weight: 600; font-size: 1.05rem;">
                                <i class="fas fa-info-circle" style="margin-right: 10px;"></i>
                                For inquiries, contact us
                            </p>
                        </div>
                    </div>
                <?php
                } else {
                ?>
                    <div style="text-align: center;">
                        <div style="background: rgba(255, 255, 255, 0.97); border-radius: 20px; padding: 70px 50px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.25);">
                            <div style="font-size: 100px; background: linear-gradient(135deg, #16a34a 0%, #059669 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 25px;">
                                <i class="fas fa-heart"></i>
                            </div>
                            <h3 style="color: #1f2937; margin-bottom: 20px; font-size: 2.2rem; font-weight: 800;">Join Our Volunteer Team</h3>
                            <p style="color: #64748b; font-size: 1.1rem; max-width: 600px; margin: 0 auto 40px; line-height: 1.7;">
                                We're looking for dedicated individuals to join our fire and rescue volunteer program. 
                                Make a difference in your community and help save lives.
                            </p>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 25px; margin: 50px 0; padding: 0 20px;">
                                <div style="text-align: center; padding: 25px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 15px; border-top: 5px solid var(--primary-color);">
                                    <div style="font-size: 48px; color: var(--primary-color); margin-bottom: 15px;">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h4 style="color: #1f2937; margin-bottom: 10px; font-weight: 700;">Community</h4>
                                    <p style="color: #64748b;">Join a team of dedicated community volunteers</p>
                                </div>
                                
                                <div style="text-align: center; padding: 25px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 15px; border-top: 5px solid var(--secondary-color);">
                                    <div style="font-size: 48px; color: var(--secondary-color); margin-bottom: 15px;">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <h4 style="color: #1f2937; margin-bottom: 10px; font-weight: 700;">Training</h4>
                                    <p style="color: #64748b;">Receive professional fire and rescue training</p>
                                </div>
                                
                                <div style="text-align: center; padding: 25px; background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius: 15px; border-top: 5px solid var(--accent-color);">
                                    <div style="font-size: 48px; color: var(--accent-color); margin-bottom: 15px;">
                                        <i class="fas fa-hand-holding-heart"></i>
                                    </div>
                                    <h4 style="color: #1f2937; margin-bottom: 10px; font-weight: 700;">Impact</h4>
                                    <p style="color: #64748b;">Make a real difference in people's lives</p>
                                </div>
                            </div>
                            
                            <button onclick="openVolunteerApplication()" class="btn btn-emergency" style="font-size: 1.1rem; padding: 16px 45px; margin: 30px 0;">
                                <i class="fas fa-edit"></i>
                                Start Volunteer Application
                            </button>
                            
                            <p style="color: #64748b; margin-top: 25px; font-size: 0.95rem;">
                                <i class="fas fa-clock"></i> Application takes approximately 15-20 minutes to complete
                            </p>
                        </div>
                    </div>
                <?php
                }
                ?>
            </div>
        </div>
    </section>

    <!-- Volunteer Application Modal -->
    <div id="volunteerModal" class="modal">
        <div class="modal-content">
            <button onclick="closeVolunteerModal()">&times;</button>
            <div class="spinner">
                <i class="fas fa-spinner"></i>
            </div>
            <h3>Preparing Application</h3>
            <p>Loading the volunteer application form...</p>
            <div style="background: linear-gradient(135deg, #f0f9ff 0%, #eff6ff 100%); padding: 20px; border-radius: 12px; margin-bottom: 25px; border-left: 5px solid #3b82f6;">
                <p style="margin: 0; color: #1e40af; font-size: 0.95rem; font-weight: 600;">
                    <i class="fas fa-info-circle" style="margin-right: 8px;"></i>
                    Please have your valid ID and contact information ready
                </p>
            </div>
            <div class="loading-bar">
                <div id="loadingBar"></div>
            </div>
        </div>
    </div>

    <!-- Map Section -->
    <section class="map-section">
        <div class="container">
            <div class="map-container">
                <div class="map-info">
                    <h2>Our Location</h2>
                    <p>Visit our Barangay Commonwealth Fire & Rescue Station for inquiries, assistance, or to meet our dedicated team of emergency responders.</p>
                    
                    <div class="contact-details">
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-map-marker-alt"></i>
                            </div>
                            <div class="contact-text">
                                <h4>Address</h4>
                                <p>Barangay Commonwealth Fire Station, Commonwealth Ave, Quezon City, Metro Manila</p>
                            </div>
                        </div>
                        
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-phone"></i>
                            </div>
                            <div class="contact-text">
                                <h4>Emergency Hotline</h4>
                                <p>911</p>
                            </div>
                        </div>
                        
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="contact-text">
                                <h4>Operating Hours</h4>
                                <p>24/7 Emergency Response</p>
                            </div>
                        </div>
                        
                        <div class="contact-item">
                            <div class="contact-icon">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <div class="contact-text">
                                <h4>Email</h4>
                                <p>Stephenviray12@gmail.com</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="map-wrapper">
                    <div id="map"></div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="footer-content">
                <div class="footer-column">
                    <h3>Quick Links</h3>
                    <ul class="footer-links">
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Home</a></li>
                        <li><a href="#services"><i class="fas fa-chevron-right"></i> Services</a></li>
                        <li><a href="#volunteer"><i class="fas fa-chevron-right"></i> Volunteer</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Contact</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Emergency Services</h3>
                    <ul class="footer-links">
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Fire Response</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Medical Assistance</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Rescue Operations</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Disaster Response</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Community</h3>
                    <ul class="footer-links">
                        <li><a href="#volunteer"><i class="fas fa-chevron-right"></i> Volunteer Program</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Training & Seminars</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Safety Tips</a></li>
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Community Events</a></li>
                    </ul>
                </div>
                
                <div class="footer-column">
                    <h3>Connect With Us</h3>
                    <ul class="footer-links">
                        <li><a href="#"><i class="fab fa-facebook-f"></i> Facebook</a></li>
                        <li><a href="#"><i class="fab fa-twitter"></i> Twitter</a></li>
                        <li><a href="#"><i class="fab fa-instagram"></i> Instagram</a></li>
                        <li><a href="#"><i class="fab fa-youtube"></i> YouTube</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-bottom">
                <p>&copy; 2025 Barangay Commonwealth Fire & Rescue Services. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>
    
    <script>
        function initMap() {
            const barangayCommonwealth = [14.697802050250742, 121.08813188818199];
            const map = L.map('map').setView(barangayCommonwealth, 15);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            const fireIcon = L.divIcon({
                className: 'custom-marker',
                html: '<i class="fas fa-fire-extinguisher" style="color: white; font-size: 16px; display: flex; align-items: center; justify-content: center; height: 100%; width: 100%;"></i>',
                iconSize: [35, 35],
                iconAnchor: [17.5, 17.5]
            });
            
            const marker = L.marker(barangayCommonwealth, {icon: fireIcon}).addTo(map);
            const popupContent = `
                <div style="padding: 12px; max-width: 280px;">
                    <h3 style="margin: 0 0 10px; color: #dc2626; font-size: 1.1rem;">Barangay Commonwealth Fire & Rescue Station</h3>
                    <p style="margin: 0; color: #333; font-weight: 500;">Commonwealth Ave, Quezon City, Metro Manila</p>
                    <p style="margin: 10px 0 0; color: #666;">Emergency Hotline: <strong>911</strong></p>
                </div>
            `;
            marker.bindPopup(popupContent);
            marker.openPopup();
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
            
            window.addEventListener('scroll', function() {
                const header = document.querySelector('header');
                if (window.scrollY > 50) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }
            });
        });

        function openVolunteerApplication() {
            const modal = document.getElementById('volunteerModal');
            const loadingBar = document.getElementById('loadingBar');
            
            modal.style.display = 'flex';
            setTimeout(() => {
                loadingBar.style.width = '100%';
            }, 100);
            
            setTimeout(() => {
                window.location.href = 'volunteer-application.php';
            }, 1500);
        }

        function closeVolunteerModal() {
            const modal = document.getElementById('volunteerModal');
            const loadingBar = document.getElementById('loadingBar');
            
            modal.style.display = 'none';
            loadingBar.style.width = '0%';
        }

        document.getElementById('volunteerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeVolunteerModal();
            }
        });
    </script>
</body>
</html>