<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Commonwealth Fire & Rescue Services</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" 
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" 
          crossorigin=""/>
    
    <style>
        :root {
            --primary-color: #dc2626;
            --primary-dark: #b91c1c;
            --primary-light: #fef2f2;
            --secondary-color: #1e40af;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --background-color: #f8fafc;
            --card-bg: rgba(255, 255, 255, 0.25);
            --sidebar-bg: #1f2937;
            --sidebar-text: #f9fafb;
            --sidebar-hover: #374151;
            --glass-border: rgba(255, 255, 255, 0.18);
            --glass-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header Styles */
        header {
            padding: 20px 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            background-color: rgba(255, 255, 255, 0.75);
            border-bottom: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
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
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
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
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-size: 0.9rem;
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
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }

        .btn-login:hover {
            background: var(--primary-light);
        }

        .btn-register:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.4);
        }

        /* Hero Section */
        .hero {
            padding: 180px 0 100px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .hero-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            opacity: 0.1;
        }

        .hero-bg::before {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: var(--primary-color);
            top: 10%;
            left: 10%;
            filter: blur(80px);
        }

        .hero-bg::after {
            content: '';
            position: absolute;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: var(--secondary-color);
            bottom: 10%;
            right: 10%;
            filter: blur(80px);
        }

        .hero h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            line-height: 1.2;
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
        }

        .btn-emergency {
            background: var(--primary-color);
            color: white;
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
            transition: all 0.3s ease;
        }

        .btn-emergency:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(220, 38, 38, 0.5);
            background: var(--primary-dark);
        }

        .btn-services {
            background: transparent;
            color: var(--secondary-color);
            border: 2px solid var(--secondary-color);
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .btn-services:hover {
            background: var(--secondary-color);
            color: white;
            transform: translateY(-3px);
        }

        /* Services Section */
        .services {
            padding: 100px 0;
        }

        .section-title {
            text-align: center;
            margin-bottom: 60px;
        }

        .section-title h2 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 15px;
        }

        .section-title p {
            font-size: 1.1rem;
            color: var(--text-light);
            max-width: 600px;
            margin: 0 auto;
        }

        .services-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
        }

        .service-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 30px;
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .service-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 35px rgba(31, 38, 135, 0.2);
        }

        .service-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            color: var(--primary-color);
            font-size: 28px;
        }

        .service-card h3 {
            font-size: 1.5rem;
            font-weight: 600;
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
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }

        .service-link:hover {
            gap: 10px;
        }

        /* Map Section */
        .map-section {
            padding: 100px 0;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
        }

        .map-container {
            display: flex;
            gap: 40px;
            align-items: center;
        }

        .map-info {
            flex: 1;
        }

        .map-info h2 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 20px;
            color: var(--text-color);
        }

        .map-info p {
            color: var(--text-light);
            margin-bottom: 30px;
        }

        .contact-details {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            backdrop-filter: blur(16px) saturate(180%);
            -webkit-backdrop-filter: blur(16px) saturate(180%);
            border: 1px solid var(--glass-border);
            box-shadow: var(--glass-shadow);
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .contact-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-light);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }

        .contact-text h4 {
            font-size: 1rem;
            font-weight: 600;
        }

        .contact-text p {
            font-size: 0.9rem;
            color: var(--text-light);
            margin: 0;
        }

        .map-wrapper {
            flex: 1;
            height: 400px;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--glass-shadow);
            border: 1px solid var(--glass-border);
        }

        #map {
            width: 100%;
            height: 100%;
        }

        /* Custom Leaflet Styles */
        .leaflet-popup-content-wrapper {
            border-radius: 10px;
            box-shadow: var(--glass-shadow);
            backdrop-filter: blur(16px) saturate(180%);
        }
        
        .leaflet-popup-content {
            margin: 15px 20px;
            line-height: 1.5;
        }
        
        .leaflet-popup-content h3 {
            color: var(--primary-color);
            margin-bottom: 8px;
        }
        
        .leaflet-popup-content p {
            margin-bottom: 5px;
            color: var(--text-color);
        }
        
        .custom-marker {
            background-color: var(--primary-color);
            border: 3px solid white;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        /* Footer */
        footer {
            background: var(--sidebar-bg);
            color: var(--sidebar-text);
            padding: 60px 0 30px;
        }

        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }

        .footer-column h3 {
            font-size: 1.3rem;
            margin-bottom: 20px;
            position: relative;
            padding-bottom: 10px;
        }

        .footer-column h3::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: var(--primary-color);
        }

        .footer-links {
            list-style: none;
        }

        .footer-links li {
            margin-bottom: 10px;
        }

        .footer-links a {
            color: var(--sidebar-text);
            text-decoration: none;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .footer-links a:hover {
            color: var(--primary-color);
            transform: translateX(5px);
        }

        .footer-bottom {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }

        /* Responsive Design */
        @media (max-width: 992px) {
            .hero h1 {
                font-size: 2.8rem;
            }
            
            .map-container {
                flex-direction: column;
            }
            
            .map-info, .map-wrapper {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
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
        }

        @media (max-width: 576px) {
            .hero {
                padding: 150px 0 80px;
            }
            
            .services-grid {
                grid-template-columns: 1fr;
            }
            
            .service-card {
                padding: 20px;
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
                    <img src="img/frsm-logo.png" alt="Barangay Commonwealth Fire & Rescue Services Logo" class="logo-icon" style="width:50px;height:50px;object-fit:cover;display:block;">
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
            <p>Barangay Commonwealth Fire & Rescue Services is committed to providing prompt emergency response, community safety education, and proactive hazard prevention.</p>
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
                <!-- Service 1 -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-ambulance"></i>
                    </div>
                    <h3>Emergency Assistance</h3>
                    <p>Request immediate fire, medical, or rescue assistance through our streamlined emergency response system.</p>
                    <a href="#" class="service-link">
                        Request Assistance <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <!-- Service 2 -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-hands-helping"></i>
                    </div>
                    <h3>Volunteer Program</h3>
                    <p>Join our community of dedicated volunteers and make a difference in emergency response and preparedness.</p>
                    <a href="#" class="service-link">
                        Sign Up Now <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <!-- Service 3 -->
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
                
                <!-- Service 4 -->
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
                
                <!-- Service 5 -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h3>Hazard Reporting</h3>
                    <p>Report unsafe establishments, fire hazards, or potential dangers in your community.</p>
                    <a href="#" class="service-link">
                        Report Hazard <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <!-- Service 6 -->
                <div class="service-card">
                    <div class="service-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <h3>My Dashboard</h3>
                    <p>Track your requests, reports, and volunteer activities through your personal dashboard.</p>
                    <a href="#" class="service-link">
                        View Dashboard <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <!-- Service 7 -->
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
                                <p>TBA</p>
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
                        <li><a href="#"><i class="fas fa-chevron-right"></i> About Us</a></li>
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
                        <li><a href="#"><i class="fas fa-chevron-right"></i> Volunteer Program</a></li>
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
    
    <!-- JavaScript for Map -->
    <script>
        // Initialize and display the map
        function initMap() {
            // Barangay Commonwealth coordinates
            const barangayCommonwealth = [14.697802050250742, 121.08813188818199];
            
            // Create map
            const map = L.map('map').setView(barangayCommonwealth, 15);
            
            // Add tile layer (OpenStreetMap)
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            // Create custom icon
            const fireIcon = L.divIcon({
                className: 'custom-marker',
                html: '<i class="fas fa-fire-extinguisher" style="color: white; font-size: 14px; display: flex; align-items: center; justify-content: center; height: 100%;"></i>',
                iconSize: [30, 30],
                iconAnchor: [15, 15]
            });
            
            // Create marker
            const marker = L.marker(barangayCommonwealth, {icon: fireIcon}).addTo(map);
            
            // Create popup content
            const popupContent = `
                <div style="padding: 10px; max-width: 250px;">
                    <h3 style="margin: 0 0 10px; color: #dc2626;">Barangay Commonwealth Fire & Rescue Station</h3>
                    <p style="margin: 0; color: #333;">Commonwealth Ave, Quezon City, Metro Manila</p>
                    <p style="margin: 10px 0 0; color: #666;">Emergency Hotline: 911</p>
                </div>
            `;
            
            // Bind popup to marker
            marker.bindPopup(popupContent);
            
            // Open popup by default
            marker.openPopup();
        }
        
        // Initialize map when page loads
        document.addEventListener('DOMContentLoaded', function() {
            initMap();
        });
    </script>
</body>
</html>