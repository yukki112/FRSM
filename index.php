<?php
require_once 'config/db_connection.php';

// Fetch approved feedbacks for testimonials section
try {
    $stmt = $pdo->query("
        SELECT 
            id,
            COALESCE(name, 'Anonymous') as name,
            rating,
            message,
            is_anonymous,
            created_at
        FROM feedbacks 
        WHERE is_approved = 1 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    
    $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no feedbacks in database, use fallback testimonials
    if (empty($feedbacks)) {
        $feedbacks = [
            [
                'name' => 'Maria Johnson',
                'rating' => 5,
                'message' => 'The quick response from Barangay Commonwealth Fire & Rescue saved our home during the recent fire incident. Their professionalism and dedication are truly commendable.',
                'is_anonymous' => 0
            ],
            [
                'name' => 'Carlos Reyes',
                'rating' => 5,
                'message' => 'Volunteering with the fire and rescue team has been one of the most rewarding experiences of my life. The training is excellent and the team feels like family.',
                'is_anonymous' => 0
            ],
            [
                'name' => 'Anna Santos',
                'rating' => 4,
                'message' => 'The fire safety seminar organized by the team was incredibly informative. I now feel much more prepared to handle emergency situations at home and work.',
                'is_anonymous' => 0
            ]
        ];
    }
} catch (PDOException $e) {
    // Fallback testimonials if database query fails
    $feedbacks = [
        [
            'name' => 'Maria Johnson',
            'rating' => 5,
            'message' => 'The quick response from Barangay Commonwealth Fire & Rescue saved our home during the recent fire incident. Their professionalism and dedication are truly commendable.',
            'is_anonymous' => 0
        ],
        [
            'name' => 'Carlos Reyes',
            'rating' => 5,
            'message' => 'Volunteering with the fire and rescue team has been one of the most rewarding experiences of my life. The training is excellent and the team feels like family.',
            'is_anonymous' => 0
        ],
        [
            'name' => 'Anna Santos',
            'rating' => 4,
            'message' => 'The fire safety seminar organized by the team was incredibly informative. I now feel much more prepared to handle emergency situations at home and work.',
            'is_anonymous' => 0
        ]
    ];
}

// Function to get initials from name
function getInitials($name) {
    $words = explode(' ', $name);
    $initials = '';
    foreach ($words as $word) {
        $initials .= strtoupper(substr($word, 0, 1));
    }
    return substr($initials, 0, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Commonwealth Fire & Rescue Services</title>
    <link rel="stylesheet" href="css/landingpages.css">
    <link rel="stylesheet" href="css/feedback.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&display=swap" rel="stylesheet">
    <link href="https://fonts.cdnfonts.com/css/cetrinn" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/starability@2.4.0/starability-css/starability-all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" 
        integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" 
        crossorigin=""/>

        
</head>

<body>
    <!-- New Loading Animation - Candle Theme -->
    <div class="candle-loader" id="candleLoader">
        <div class="candle-animation-wrapper">
            <!-- From Uiverse.io by _7754 - MODIFIED FOR CENTERING -->
            <div class="wrapper">
                <div class="candles">
                    <div class="light__wave"></div>
                    <div class="candle1">
                        <div class="candle1__body">
                            <div class="candle1__eyes">
                                <span class="candle1__eyes-one"></span>
                                <span class="candle1__eyes-two"></span>
                            </div>
                            <div class="candle1__mouth"></div>
                        </div>
                        <div class="candle1__stick"></div>
                    </div>
                    <div class="candle2">
                        <div class="candle2__body">
                            <div class="candle2__eyes">
                                <div class="candle2__eyes-one"></div>
                                <div class="candle2__eyes-two"></div>
                            </div>
                        </div>
                        <div class="candle2__stick"></div>
                    </div>
                    <div class="candle2__fire"></div>
                    <div class="sparkles-one"></div>
                    <div class="sparkles-two"></div>
                    <div class="candle__smoke-one"></div>
                    <div class="candle__smoke-two"></div>
                </div>
                <div class="floor"></div>
            </div>
        </div>
        
        <div class="loading-text">
            <h3>Fire & Rescue Services</h3>
            <p>Loading emergency response systems...</p>
        </div>
    </div>

    <!-- ENHANCED Chat Button and Modal -->
    <button class="chat-button" id="chatButton">
        <i class="fas fa-fire-extinguisher"></i>
    </button>

    <div class="chat-modal" id="chatModal">
        <div class="chat-header">
            <h3><i class="fas fa-shield-alt"></i> Fire & Rescue Assistant</h3>
            <button class="chat-close" id="chatClose">&times;</button>
        </div>
        
        <!-- FAQ Quick Buttons -->
        <div class="faq-buttons-container" id="faqButtons">
            <button class="faq-btn" data-question="emergency"><i class="fas fa-phone-emergency"></i> Emergency</button>
            <button class="faq-btn" data-question="volunteer"><i class="fas fa-users"></i> Volunteer</button>
            <button class="faq-btn" data-question="services"><i class="fas fa-concierge-bell"></i> Services</button>
            <button class="faq-btn" data-question="reporting"><i class="fas fa-clipboard-list"></i> Reporting</button>
            <button class="faq-btn" data-question="training"><i class="fas fa-graduation-cap"></i> Training</button>
        </div>
        
        <div class="chat-messages" id="chatMessages">
            <!-- Messages will be inserted here -->
        </div>
        <div class="chat-input-area">
            <input 
                type="text" 
                class="chat-input" 
                id="chatInput" 
                placeholder="Ask about fire safety, emergency procedures, or volunteer programs..."
                autocomplete="off"
            >
            <button class="chat-send" id="chatSend">
                <i class="fas fa-paper-plane"></i> Send
            </button>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div id="feedbackModal" class="feedback-modal">
        <div class="feedback-modal-content">
            <button class="feedback-close" onclick="closeFeedbackModal()">&times;</button>
            <h2><i class="fas fa-comment-alt"></i> Share Your Feedback</h2>
            <p>We value your opinion! Share your experience with Barangay Commonwealth Fire & Rescue Services.</p>
            
            <form id="feedbackForm">
                <div class="form-group">
                    <label for="feedbackName">Name (Optional)</label>
                    <input type="text" id="feedbackName" name="name" placeholder="Enter your name">
                </div>
                
                <div class="form-group">
                    <label for="feedbackEmail">Email (Optional)</label>
                    <input type="email" id="feedbackEmail" name="email" placeholder="Enter your email">
                </div>
                
                <div class="form-group">
                    <label>Rating</label>
                    <div class="star-rating">
                        <input type="radio" id="star5" name="rating" value="5" checked>
                        <label for="star5" title="Excellent">★</label>
                        <input type="radio" id="star4" name="rating" value="4">
                        <label for="star4" title="Very Good">★</label>
                        <input type="radio" id="star3" name="rating" value="3">
                        <label for="star3" title="Good">★</label>
                        <input type="radio" id="star2" name="rating" value="2">
                        <label for="star2" title="Fair">★</label>
                        <input type="radio" id="star1" name="rating" value="1">
                        <label for="star1" title="Poor">★</label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="feedbackMessage">Your Feedback*</label>
                    <textarea id="feedbackMessage" name="message" rows="4" placeholder="Share your experience, suggestions, or comments..." required></textarea>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" id="isAnonymous" name="is_anonymous">
                    <label for="isAnonymous">Submit anonymously</label>
                    <small>Your name and email will not be displayed</small>
                </div>
                
                <div class="form-submit">
                    <button type="submit" class="btn btn-emergency">
                        <i class="fas fa-paper-plane"></i> Submit Feedback
                    </button>
                </div>
            </form>
            
            <div id="feedbackResponse" class="feedback-response"></div>
        </div>
    </div>

    <!-- Header -->
    <header id="header">
        <div class="container">
            <div class="header-content">
                <div class="logo">
                    <!-- Logo now displays your custom image -->
                    <div class="logo-icon">
                        <img src="img/logo.png" alt="Barangay Commonwealth Logo">
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
        <div class="container">
            <h1>Your Safety Is Our Priority</h1>
            <p>Barangay Commonwealth Fire & Rescue Services is committed to providing prompt emergency response, community safety education, and proactive incident prevention.</p>
            <div class="hero-buttons">
                <a href="#" class="btn btn-emergency">
                    <i class="fas fa-phone-alt"></i>
                    Emergency Hotline: 911
                </a>
                <button onclick="openFeedbackModal()" class="btn btn-services">
                    <i class="fas fa-comment-alt"></i>
                    Share Your Feedback
                </button>
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
                    <button onclick="openFeedbackModal()" class="service-link">
                        Provide Feedback <i class="fas fa-arrow-right"></i>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- New Barangay Updates Section -->
    <section class="updates-section">
        <div class="container">
            <div class="section-title">
                <h2>Barangay Updates</h2>
                <p>Latest news, announcements, and important updates from Barangay Commonwealth</p>
            </div>
            <div class="updates-grid">
                <div class="update-card">
                    <span class="update-date">Today</span>
                    <h3>Fire Safety Awareness Campaign</h3>
                    <p>Join us for our monthly fire safety awareness campaign. Learn essential fire prevention techniques and how to respond effectively in case of emergency. Free training for all residents.</p>
                    <a href="#" class="update-link">
                        Learn More <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="update-card">
                    <span class="update-date">This Week</span>
                    <h3>New Equipment Received</h3>
                    <p>Barangay Commonwealth Fire & Rescue has received new state-of-the-art firefighting equipment. This upgrade will significantly improve our emergency response capabilities and resident safety.</p>
                    <a href="#" class="update-link">
                        Learn More <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="update-card">
                    <span class="update-date">Last Week</span>
                    <h3>Community Volunteer Recognition</h3>
                    <p>We recognize and celebrate our outstanding volunteers who have dedicated their time and effort to serving the community. Thank you for your commitment and bravery.</p>
                    <a href="#" class="update-link">
                        Learn More <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="stats-section">
        <div class="container">
            <div class="stats-container">
                <div class="stat-item">
                    <div class="stat-number">500+</div>
                    <div class="stat-label">Emergency Calls</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">150+</div>
                    <div class="stat-label">Active Volunteers</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">Response Time</div>
                </div>
                <div class="stat-item">
                    <div class="stat-number">98%</div>
                    <div class="stat-label">Community Satisfaction</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials Section with Real Feedback -->
    <section class="testimonials">
        <div class="container">
            <div class="section-title">
                <h2>What People Say</h2>
                <p>Real feedback from our community members and volunteers</p>
                <button onclick="openFeedbackModal()" class="btn btn-services" style="margin-top: 20px;">
                    <i class="fas fa-pen"></i> Add Your Feedback
                </button>
            </div>
            <div class="testimonials-grid">
                <?php foreach ($feedbacks as $index => $feedback): ?>
                <div class="testimonial-card">
                    <div class="testimonial-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <i class="fas fa-star <?php echo $i <= $feedback['rating'] ? 'active' : ''; ?>"></i>
                        <?php endfor; ?>
                        <span class="rating-number"><?php echo $feedback['rating']; ?>.0</span>
                    </div>
                    <div class="testimonial-content">
                        "<?php echo htmlspecialchars($feedback['message']); ?>"
                    </div>
                    <div class="testimonial-author">
                        <div class="author-avatar">
                            <?php echo getInitials($feedback['name']); ?>
                        </div>
                        <div class="author-info">
                            <h4><?php echo htmlspecialchars($feedback['name']); ?></h4>
                            <p><?php echo $feedback['is_anonymous'] ? 'Anonymous User' : 'Community Member'; ?></p>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
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
                    <div style="text-align: center; padding: 100px 70px; background: linear-gradient(135deg, rgba(26, 32, 44, 0.9), rgba(15, 20, 25, 0.9)); border-radius: 22px; box-shadow: 0 25px 70px rgba(0, 0, 0, 0.35); border: 2px solid var(--primary-red); backdrop-filter: blur(12px);">
                        <div style="font-size: 140px; background: linear-gradient(135deg, rgba(229, 62, 62, 0.35), rgba(139, 0, 0, 0.25)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 35px;">
                            <i class="fas fa-lock"></i>
                        </div>
                        <h3 style="color: #ffffff; margin-bottom: 24px; font-size: 2.8rem; font-weight: 900; font-family: 'Bebas Neue', cursive; text-transform: uppercase; letter-spacing: 1.2px;">Volunteer Registration Closed</h3>
                        <p style="color: #cbd5e0; font-size: 1.15rem; max-width: 650px; margin: 0 auto 45px; line-height: 1.9;">
                            We are not currently accepting new volunteer applications. Please check back later for updates on when registration will reopen.
                        </p>
                        <div style="background: linear-gradient(135deg, rgba(229, 62, 62, 0.25), rgba(229, 62, 62, 0.12)); padding: 30px; border-radius: 16px; display: inline-block; border-left: 6px solid var(--primary-red);">
                            <p style="margin: 0; color: #fb7185; font-weight: 800; font-size: 1.1rem;">
                                <i class="fas fa-info-circle" style="margin-right: 12px;"></i>
                                For inquiries, contact us
                            </p>
                        </div>
                    </div>
                <?php
                } else {
                ?>
                    <div style="text-align: center;">
                        <div style="background: linear-gradient(135deg, rgba(26, 32, 44, 0.9), rgba(15, 20, 25, 0.9)); border-radius: 22px; padding: 100px 70px; box-shadow: 0 25px 70px rgba(0, 0, 0, 0.35); border: 2px solid rgba(229, 62, 62, 0.5); backdrop-filter: blur(12px);">
                            <div style="font-size: 140px; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 35px;">
                                <i class="fas fa-heart"></i>
                            </div>
                            <h3 style="color: #ffffff; margin-bottom: 28px; font-size: 2.8rem; font-weight: 900; font-family: 'Bebas Neue', cursive; text-transform: uppercase; letter-spacing: 1.2px;">Join Our Volunteer Team</h3>
                            <p style="color: #cbd5e0; font-size: 1.15rem; max-width: 650px; margin: 0 auto 60px; line-height: 1.9;">
                                We're looking for dedicated individuals to join our fire and rescue volunteer program. 
                                Make a difference in your community and help save lives.
                            </p>
                            
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 35px; margin: 70px 0; padding: 0 20px;">
                                <div style="text-align: center; padding: 40px; background: linear-gradient(135deg, rgba(229, 62, 62, 0.15), rgba(229, 62, 62, 0.08)); border-radius: 16px; border-top: 7px solid var(--primary-red); border-left: 4px solid var(--primary-red); backdrop-filter: blur(12px); transition: all 0.3s ease;">
                                    <div style="font-size: 65px; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 16px;">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h4 style="color: #ffffff; margin-bottom: 10px; font-weight: 900; font-size: 1.25rem;">Community</h4>
                                    <p style="color: #cbd5e0; margin: 0;">Join a team of dedicated community volunteers</p>
                                </div>
                                
                                <div style="text-align: center; padding: 40px; background: linear-gradient(135deg, rgba(229, 62, 62, 0.15), rgba(229, 62, 62, 0.08)); border-radius: 16px; border-top: 7px solid var(--primary-red); border-left: 4px solid var(--primary-red); backdrop-filter: blur(12px); transition: all 0.3s ease;">
                                    <div style="font-size: 65px; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 16px;">
                                        <i class="fas fa-graduation-cap"></i>
                                    </div>
                                    <h4 style="color: #ffffff; margin-bottom: 10px; font-weight: 900; font-size: 1.25rem;">Training</h4>
                                    <p style="color: #cbd5e0; margin: 0;">Receive professional fire and rescue training</p>
                                </div>
                                
                                <div style="text-align: center; padding: 40px; background: linear-gradient(135deg, rgba(229, 62, 62, 0.15), rgba(229, 62, 62, 0.08)); border-radius: 16px; border-top: 7px solid var(--primary-red); border-left: 4px solid var(--primary-red); backdrop-filter: blur(12px); transition: all 0.3s ease;">
                                    <div style="font-size: 65px; background: var(--gradient-primary); -webkit-background-clip: text; -webkit-text-fill-color: transparent; margin-bottom: 16px;">
                                        <i class="fas fa-hand-holding-heart"></i>
                                    </div>
                                    <h4 style="color: #ffffff; margin-bottom: 10px; font-weight: 900; font-size: 1.25rem;">Impact</h4>
                                    <p style="color: #cbd5e0; margin: 0;">Make a real difference in people's lives</p>
                                </div>
                            </div>
                            
                            <!-- Fixed button container with center alignment -->
                            <div class="volunteer-button-container">
                                <button onclick="openVolunteerApplication()" class="btn btn-emergency" style="font-size: 1.15rem; padding: 22px 55px; text-transform: uppercase;">
                                    <i class="fas fa-edit"></i>
                                    Start Volunteer Application
                                </button>
                            </div>
                            
                            <p style="color: #cbd5e0; margin-top: 35px; font-size: 0.98rem; font-weight: 600;">
                                <i class="fas fa-clock" style="margin-right: 8px;"></i> Application takes approximately 15-20 minutes to complete
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
            <div style="background: linear-gradient(135deg, rgba(229, 62, 62, 0.15), rgba(229, 62, 62, 0.08)); padding: 22px; border-radius: 12px; margin-bottom: 28px; border-left: 6px solid var(--primary-red);">
                <p style="margin: 0; color: var(--primary-red); font-size: 0.98rem; font-weight: 800;">
                    <i class="fas fa-info-circle" style="margin-right: 10px;"></i>
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

    <!-- NEW FOOTER - Inspired by TERRAIN image -->
    <footer class="new-footer">
        <div class="container">
            <div class="footer-main">
                <div class="footer-logo-container">
                    <div class="footer-logo-image">
                        <!-- Replace with your own logo image -->
                        <img src="img/logo.png" alt="Barangay Commonwealth Logo">
                    </div>
                    <div class="footer-logo-text">
                        <h2 class="cetrinn-font">Barangay Commonwealth</h2>
                        <p>Fire & Rescue Services</p>
                    </div>
                </div>
                
                <div class="footer-location">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>BASED IN BARANGAY COMMONWEALTH, QUEZON CITY & WORLDWIDE ONLINE.</span>
                </div>
                
                <div class="footer-social">
                    <a href="#" class="social-icon">
                        <i class="fab fa-facebook-f"></i>
                    </a>
                    <a href="#" class="social-icon">
                        <i class="fab fa-twitter"></i>
                    </a>
                    <a href="#" class="social-icon">
                        <i class="fab fa-linkedin-in"></i>
                    </a>
                    <a href="#" class="social-icon">
                        <i class="fab fa-instagram"></i>
                    </a>
                </div>
                
                <div class="footer-updates">
                    <div class="updates-title">GET UPDATES</div>
                    <form class="updates-form">
                        <input type="email" class="updates-input" placeholder="Enter your email" required>
                        <button type="submit" class="updates-button">Subscribe</button>
                    </form>
                </div>
            </div>
            
            <div class="footer-bottom">
                <div class="footer-links-bottom">
                    <a href="#" onclick="openFeedbackModal()">FEEDBACK</a>
                    <a href="#">TERMS</a>
                    <a href="#">PRIVACY</a>
                    <a href="#">CONTACT</a>
                    <a href="#">CAREERS</a>
                </div>
                <div class="copyright">
                    <p>&copy; 2025 Barangay Commonwealth Fire & Rescue Services. All rights reserved.</p>
                    <p style="margin-top: 8px; font-size: 0.8rem;">BARANGAY COMMONWEALTH FIRE & RESCUE MANAGEMENT</p>
                </div>
            </div>
        </div>
    </footer>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>
    
    <script>
        // Candle Loading Animation - REDUCED TO 1 SECOND
        document.addEventListener('DOMContentLoaded', function() {
            const loader = document.getElementById('candleLoader');
            
            // Hide loader after 1 second
            setTimeout(() => {
                loader.classList.add('fade-out');
                setTimeout(() => {
                    loader.style.display = 'none';
                }, 300);
            }, 1000); // Changed from 3200ms to 1000ms
            
            initMap();
            
            window.addEventListener('scroll', function() {
                const header = document.querySelector('header');
                if (window.scrollY > 50) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }
            });
            
            // Animate stats on scroll
            const statsSection = document.querySelector('.stats-section');
            const statNumbers = document.querySelectorAll('.stat-number');
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        statNumbers.forEach(stat => {
                            const target = parseInt(stat.textContent);
                            let current = 0;
                            const increment = target / 50;
                            const timer = setInterval(() => {
                                current += increment;
                                if (current >= target) {
                                    stat.textContent = target + (stat.textContent.includes('%') ? '%' : '+');
                                    clearInterval(timer);
                                } else {
                                    stat.textContent = Math.floor(current) + (stat.textContent.includes('%') ? '%' : '+');
                                }
                            }, 30);
                        });
                        observer.unobserve(statsSection);
                    }
                });
            });
            
            if (statsSection) {
                observer.observe(statsSection);
            }
            
            // Form submission
            const updatesForm = document.querySelector('.updates-form');
            if (updatesForm) {
                updatesForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const email = this.querySelector('.updates-input').value;
                    if (email) {
                        alert('Thank you for subscribing to updates!');
                        this.querySelector('.updates-input').value = '';
                    }
                });
            }

            // Feedback Form Submission
            const feedbackForm = document.getElementById('feedbackForm');
            if (feedbackForm) {
                feedbackForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const submitButton = this.querySelector('button[type="submit"]');
                    const originalText = submitButton.innerHTML;
                    
                    // Disable button and show loading
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
                    
                    fetch('api/submit_feedback.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        const responseDiv = document.getElementById('feedbackResponse');
                        if (data.success) {
                            responseDiv.innerHTML = `
                                <div class="success-message">
                                    <i class="fas fa-check-circle"></i>
                                    <p>${data.message}</p>
                                    <button onclick="closeFeedbackModalAndRefresh()" class="btn btn-services">
                                        Close & Refresh
                                    </button>
                                </div>
                            `;
                            feedbackForm.reset();
                        } else {
                            responseDiv.innerHTML = `
                                <div class="error-message">
                                    <i class="fas fa-exclamation-circle"></i>
                                    <p>${data.message}</p>
                                </div>
                            `;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        document.getElementById('feedbackResponse').innerHTML = `
                            <div class="error-message">
                                <i class="fas fa-exclamation-circle"></i>
                                <p>An error occurred. Please try again.</p>
                            </div>
                        `;
                    })
                    .finally(() => {
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalText;
                    });
                });
            }

            // Initialize star rating
            const starLabels = document.querySelectorAll('.star-rating label');
            starLabels.forEach(label => {
                label.addEventListener('mouseover', function() {
                    const starId = this.getAttribute('for');
                    const starValue = starId.replace('star', '');
                    
                    // Remove all active classes
                    starLabels.forEach(l => l.classList.remove('active'));
                    
                    // Add active class to current and previous stars
                    for (let i = 1; i <= starValue; i++) {
                        const starLabel = document.querySelector(`label[for="star${i}"]`);
                        if (starLabel) {
                            starLabel.classList.add('active');
                        }
                    }
                });
                
                label.addEventListener('click', function() {
                    const starId = this.getAttribute('for');
                    const starValue = starId.replace('star', '');
                    
                    // Update all stars to show selected state
                    starLabels.forEach((l, index) => {
                        if (index < starValue) {
                            l.classList.add('selected');
                        } else {
                            l.classList.remove('selected');
                        }
                    });
                });
            });

            // Restore star selection on page load
            const selectedStar = document.querySelector('input[name="rating"]:checked');
            if (selectedStar) {
                const starValue = selectedStar.value;
                for (let i = 1; i <= starValue; i++) {
                    const starLabel = document.querySelector(`label[for="star${i}"]`);
                    if (starLabel) {
                        starLabel.classList.add('selected');
                    }
                }
            }
        });

        function initMap() {
            const barangayCommonwealth = [14.697802050250742, 121.08813188818199];
            const map = L.map('map').setView(barangayCommonwealth, 15);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
            }).addTo(map);
            
            const fireIcon = L.divIcon({
                className: 'custom-marker',
                html: '<i class="fas fa-fire-extinguisher" style="color: white; font-size: 18px; display: flex; align-items: center; justify-content: center; height: 100%; width: 100%;"></i>',
                iconSize: [40, 40],
                iconAnchor: [20, 20]
            });
            
            const marker = L.marker(barangayCommonwealth, {icon: fireIcon}).addTo(map);
            const popupContent = `
                <div style="padding: 14px; max-width: 300px;">
                    <h3 style="margin: 0 0 12px; color: var(--primary-red); font-size: 1.15rem; font-weight: 800;">Barangay Commonwealth Fire & Rescue Station</h3>
                    <p style="margin: 0; color: #333; font-weight: 600; font-size: 1rem;">Commonwealth Ave, Quezon City, Metro Manila</p>
                    <p style="margin: 12px 0 0; color: #666; font-size: 0.95rem;">Emergency Hotline: <strong style="color: #e53e3e;">911</strong></p>
                </div>
            `;
            marker.bindPopup(popupContent);
            marker.openPopup();
        }

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

        // Feedback Modal Functions
        function openFeedbackModal() {
            const modal = document.getElementById('feedbackModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            // Reset form
            document.getElementById('feedbackForm').reset();
            document.getElementById('feedbackResponse').innerHTML = '';
            
            // Reset star selection
            document.querySelectorAll('.star-rating label').forEach(label => {
                label.classList.remove('selected', 'active');
            });
            
            // Set default star (5 stars)
            for (let i = 1; i <= 5; i++) {
                const starLabel = document.querySelector(`label[for="star${i}"]`);
                if (starLabel) {
                    starLabel.classList.add('selected');
                }
            }
        }

        function closeFeedbackModal() {
            const modal = document.getElementById('feedbackModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function closeFeedbackModalAndRefresh() {
            closeFeedbackModal();
            // Refresh the page to show new feedback
            setTimeout(() => {
                location.reload();
            }, 500);
        }

        // Close modal when clicking outside
        document.getElementById('feedbackModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeFeedbackModal();
            }
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeFeedbackModal();
            }
        });

        // Star rating hover effect
        document.querySelectorAll('.star-rating label').forEach(label => {
            label.addEventListener('mouseover', function() {
                const starId = this.getAttribute('for');
                const starValue = parseInt(starId.replace('star', ''));
                
                // Highlight stars up to hovered star
                document.querySelectorAll('.star-rating label').forEach((l, index) => {
                    if (index < starValue) {
                        l.classList.add('active');
                    } else {
                        l.classList.remove('active');
                    }
                });
            });
            
            label.addEventListener('mouseout', function() {
                // Remove active class on mouseout (keep selected)
                document.querySelectorAll('.star-rating label').forEach(l => {
                    l.classList.remove('active');
                });
                
                // Restore selected stars
                const checkedInput = document.querySelector('input[name="rating"]:checked');
                if (checkedInput) {
                    const checkedValue = parseInt(checkedInput.value);
                    document.querySelectorAll('.star-rating label').forEach((l, index) => {
                        if (index < checkedValue) {
                            l.classList.add('selected');
                        }
                    });
                }
            });
            
            label.addEventListener('click', function() {
                const starId = this.getAttribute('for');
                const starValue = starId.replace('star', '');
                
                // Update selected state
                document.querySelectorAll('.star-rating label').forEach(l => {
                    l.classList.remove('selected');
                });
                
                for (let i = 1; i <= starValue; i++) {
                    const starLabel = document.querySelector(`label[for="star${i}"]`);
                    if (starLabel) {
                        starLabel.classList.add('selected');
                    }
                }
            });
        });

       // ENHANCED Chat Script with FAQ
       const chatButton = document.getElementById('chatButton');
       const chatModal = document.getElementById('chatModal');
       const chatClose = document.getElementById('chatClose');
       const chatMessages = document.getElementById('chatMessages');
       const chatInput = document.getElementById('chatInput');
       const chatSend = document.getElementById('chatSend');
       const faqButtons = document.getElementById('faqButtons');

       // Fire & Rescue FAQ Database
       const faqDatabase = {
           'emergency': {
               question: "What should I do in case of a fire emergency?",
               answer: "In case of a fire emergency: 1. Call 911 immediately 2. Alert everyone in the building 3. Use fire extinguishers if safe 4. Evacuate calmly 5. Never use elevators 6. Stay low to avoid smoke 7. Meet at designated assembly point",
               follow_up: "Do you need specific information about fire extinguishers or evacuation routes?"
           },
           'volunteer': {
               question: "How can I become a fire and rescue volunteer?",
               answer: "To become a volunteer: 1. Must be 18+ years old 2. Complete the online application 3. Pass medical examination 4. Attend basic training (4 weeks) 5. Commit to at least 20 hours/month 6. Pass background check. Benefits include training, equipment, and community service recognition.",
               follow_up: "Would you like to start the volunteer application process?"
           },
           'services': {
               question: "What services does Barangay Commonwealth Fire & Rescue provide?",
               answer: "Our services include: 1. Emergency fire response 2. Medical rescue operations 3. Fire safety inspections 4. Community fire drills 5. Fire prevention education 6. Hazardous materials response 7. Water rescue operations 8. Building evacuation planning",
               follow_up: "Which specific service would you like to know more about?"
           },
           'reporting': {
               question: "How do I report a fire incident or hazard?",
               answer: "You can report through: 1. Emergency hotline: 911 2. Online incident reporting form 3. Visit our station in person 4. Email: fire.report@commonwealth.gov.ph. Please provide: location, type of incident, number of people involved, and your contact information.",
               follow_up: "Do you need to report an incident right now?"
           },
           'training': {
               question: "What fire safety training is available for residents?",
               answer: "We offer: 1. Basic fire safety (monthly) 2. Fire extinguisher training 3. First aid/CPR certification 4. Emergency evacuation planning 5. Earthquake preparedness 6. Children's fire safety program. All training is FREE for Barangay Commonwealth residents.",
               follow_up: "Would you like to register for an upcoming training session?"
           },
           'fire_extinguisher': {
               question: "How do I use a fire extinguisher?",
               answer: "Remember PASS: 1. PULL the pin 2. AIM at the base of the fire 3. SQUEEZE the handle 4. SWEEP side to side. Use only for small, contained fires. Always have an escape route behind you.",
               follow_up: "Do you need information on purchasing or maintaining fire extinguishers?"
           },
           'fire_prevention': {
               question: "What are basic fire prevention tips for homes?",
               answer: "1. Install smoke alarms on every level 2. Test alarms monthly 3. Keep flammable items away from heat 4. Don't overload electrical outlets 5. Clean dryer lint filters 6. Store flammable liquids properly 7. Have a fire escape plan 8. Keep fire extinguishers accessible",
               follow_up: "Would you like a home fire safety checklist?"
           },
           'response_time': {
               question: "What is your average emergency response time?",
               answer: "Our average response time within Barangay Commonwealth is 5-7 minutes for emergencies. We operate 24/7 with trained personnel always on standby. Response may vary based on traffic and weather conditions.",
               follow_up: "Is there a specific area you're concerned about?"
           }
       };

       // Toggle chat modal
       chatButton.addEventListener('click', () => {
           chatModal.classList.toggle('active');
           if (chatModal.classList.contains('active')) {
               chatInput.focus();
               // Add initial welcome message if chat is empty
               if (chatMessages.children.length === 0) {
                   setTimeout(() => {
                       showWelcomeMessage();
                   }, 500);
               }
           }
       });

       chatClose.addEventListener('click', () => {
           chatModal.classList.remove('active');
       });

       // FAQ button click handlers
       faqButtons.addEventListener('click', (e) => {
           if (e.target.classList.contains('faq-btn')) {
               const questionType = e.target.dataset.question;
               if (faqDatabase[questionType]) {
                   addMessage(faqDatabase[questionType].question, 'user');
                   setTimeout(() => {
                       showFAQAnswer(questionType);
                   }, 500);
               }
           }
       });

       // Send message on Enter key or button click
       chatInput.addEventListener('keypress', (e) => {
           if (e.key === 'Enter' && !e.shiftKey) {
               e.preventDefault();
               sendMessage();
           }
       });

       chatSend.addEventListener('click', sendMessage);

       async function sendMessage() {
    const message = chatInput.value.trim();
    if (!message) return;

    // Add user message to chat
    addMessage(message, 'user');
    chatInput.value = '';
    chatSend.disabled = true;

    // Check if message matches FAQ keywords first
    const faqResponse = checkFAQKeywords(message);
    if (faqResponse) {
        const loadingId = showLoading();
        setTimeout(() => {
            removeLoading(loadingId);
            addMessage(faqResponse.answer, 'bot');
            if (faqResponse.follow_up) {
                setTimeout(() => {
                    addMessage(faqResponse.follow_up, 'bot');
                }, 1000);
            }
        }, 1000);
        chatSend.disabled = false;
        chatInput.focus();
        return;
    }

    // Show loading indicator
    const loadingId = showLoading();

    try {
        const response = await fetch('api/chat.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ message: message })
        });

        const data = await response.json();
        
        // Remove loading indicator
        removeLoading(loadingId);

        if (data.success) {
            addMessage(data.reply, 'bot');
        } else if (data.fallback) {
            // If API fails or has no quota, use FAQ system
            const fallbackAnswer = getFallbackResponse(message);
            addMessage(fallbackAnswer, 'bot');
            
            // Add note that we're using FAQ
            setTimeout(() => {
                addMessage("Note: I'm using our FAQ system since the AI service is temporarily unavailable. For immediate assistance, call 911.", 'bot');
            }, 500);
        } else {
            // Other error
            let errorMsg = 'I understand you\'re asking about fire and rescue services. ';
            errorMsg += 'For immediate assistance, please call 911. ';
            errorMsg += 'You can also use the FAQ buttons above for quick answers.';
            addMessage(errorMsg, 'bot');
        }
    } catch (error) {
        console.error('Fetch error:', error);
        removeLoading(loadingId);
        // Fallback to local FAQ system
        const fallbackResponse = getFallbackResponse(message);
        addMessage(fallbackResponse, 'bot');
    }

    chatSend.disabled = false;
    chatInput.focus();
}

       function checkFAQKeywords(message) {
           const lowerMessage = message.toLowerCase();
           
           // Emergency keywords
           if (lowerMessage.includes('emergency') || lowerMessage.includes('911') || 
               lowerMessage.includes('fire now') || lowerMessage.includes('burning')) {
               return {
                   answer: "🚨 **EMERGENCY ALERT** 🚨\n\nIf you have a fire emergency right now:\n1. **CALL 911 IMMEDIATELY**\n2. Evacuate the building\n3. Alert others\n4. Do not attempt to fight large fires\n\nOur team is on standby 24/7.",
                   follow_up: "Please confirm if you need emergency services dispatched to your location."
               };
           }
           
           // Volunteer keywords
           if (lowerMessage.includes('volunteer') || lowerMessage.includes('join') || 
               lowerMessage.includes('help') || lowerMessage.includes('team')) {
               return faqDatabase['volunteer'];
           }
           
           // Fire extinguisher keywords
           if (lowerMessage.includes('extinguisher') || lowerMessage.includes('put out fire') || 
               lowerMessage.includes('small fire')) {
               return faqDatabase['fire_extinguisher'];
           }
           
           // Training keywords
           if (lowerMessage.includes('training') || lowerMessage.includes('learn') || 
               lowerMessage.includes('course') || lowerMessage.includes('seminar')) {
               return faqDatabase['training'];
           }
           
           // Prevention keywords
           if (lowerMessage.includes('prevent') || lowerMessage.includes('safety') || 
               lowerMessage.includes('precaution') || lowerMessage.includes('avoid')) {
               return faqDatabase['fire_prevention'];
           }
           
           // Report keywords
           if (lowerMessage.includes('report') || lowerMessage.includes('hazard') || 
               lowerMessage.includes('incident') || lowerMessage.includes('complaint')) {
               return faqDatabase['reporting'];
           }
           
           // Services keywords
           if (lowerMessage.includes('service') || lowerMessage.includes('what do you do') || 
               lowerMessage.includes('offer') || lowerMessage.includes('provide')) {
               return faqDatabase['services'];
           }
           
           // Response time keywords
           if (lowerMessage.includes('how fast') || lowerMessage.includes('response time') || 
               lowerMessage.includes('how long') || lowerMessage.includes('wait')) {
               return faqDatabase['response_time'];
           }
           
           return null;
       }

       function isEmergencyMessage(message) {
           const emergencyKeywords = ['fire', 'emergency', '911', 'burning', 'help', 'urgent', 'now'];
           const lowerMessage = message.toLowerCase();
           return emergencyKeywords.some(keyword => lowerMessage.includes(keyword));
       }

       function showEmergencyResponse() {
           addMessage("🚨 **EMERGENCY RESPONSE** 🚨\n\n**IMMEDIATE ACTION REQUIRED:**\n1. **CALL 911** - Our emergency hotline\n2. Provide your exact location\n3. Describe the emergency\n4. Stay on the line for instructions\n\nOur team will be dispatched immediately. Please evacuate if safe to do so.", 'bot');
       }

       function getFallbackResponse(message) {
           const lowerMessage = message.toLowerCase();
           
           if (lowerMessage.includes('hello') || lowerMessage.includes('hi') || lowerMessage.includes('hey')) {
               return "Hello! I'm your Fire & Rescue Assistant. How can I help you with fire safety or emergency services today?";
           }
           
           if (lowerMessage.includes('thank') || lowerMessage.includes('thanks')) {
               return "You're welcome! Stay safe and remember: 'Prevention is better than cure.' Call 911 for emergencies!";
           }
           
           if (lowerMessage.includes('contact') || lowerMessage.includes('phone') || lowerMessage.includes('email')) {
               return "You can contact us at:\n📞 Emergency: 911\n📧 Email: fire.report@commonwealth.gov.ph\n📍 Location: Barangay Commonwealth Fire Station\n⏰ Hours: 24/7";
           }
           
           return "I specialize in fire and rescue services. You can ask me about:\n• Emergency procedures\n• Volunteer programs\n• Fire safety training\n• Incident reporting\n• Prevention tips\n\nOr use the FAQ buttons above for quick answers!";
       }

       function showFAQAnswer(faqType) {
           const faq = faqDatabase[faqType];
           if (faq) {
               addMessage(faq.answer, 'bot');
               if (faq.follow_up) {
                   setTimeout(() => {
                       addMessage(faq.follow_up, 'bot');
                   }, 1000);
               }
           }
       }

       function showWelcomeMessage() {
           const welcomeDiv = document.createElement('div');
           welcomeDiv.className = 'welcome-message';
           welcomeDiv.innerHTML = `
               <h4><i class="fas fa-shield-alt"></i> Welcome to Fire & Rescue Assistant</h4>
               <p>I'm here to help with fire safety, emergency procedures, and rescue services information.</p>
               <p><strong>Quick options:</strong> Use the FAQ buttons or type your question below.</p>
               <div style="margin-top: 15px;">
                   <button class="quick-action-btn" onclick="askQuestion('emergency')">🚨 Emergency Info</button>
                   <button class="quick-action-btn" onclick="askQuestion('volunteer')">👥 Volunteer Program</button>
                   <button class="quick-action-btn" onclick="askQuestion('training')">🎓 Safety Training</button>
               </div>
           `;
           chatMessages.appendChild(welcomeDiv);
           chatMessages.scrollTop = chatMessages.scrollHeight;
       }

       function askQuestion(type) {
           const questions = {
               'emergency': 'What are the emergency procedures?',
               'volunteer': 'Tell me about the volunteer program',
               'training': 'What training programs are available?'
           };
           
           if (questions[type]) {
               addMessage(questions[type], 'user');
               setTimeout(() => {
                   checkFAQKeywords(questions[type]);
               }, 500);
           }
       }

       function addMessage(text, sender) {
           const messageDiv = document.createElement('div');
           messageDiv.className = `message ${sender}`;
           
           const timestamp = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
           
           messageDiv.innerHTML = `
               <div class="message-content">
                   ${formatMessageText(text)}
                   <div class="message-timestamp">${timestamp}</div>
               </div>
           `;
           
           chatMessages.appendChild(messageDiv);
           chatMessages.scrollTop = chatMessages.scrollHeight;
       }

       function formatMessageText(text) {
           // Format bold text
           text = text.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
           
           // Format lists
           text = text.replace(/(\d+\.\s.*)/g, '<br>$1');
           
           // Add line breaks for better readability
           text = text.replace(/\n/g, '<br>');
           
           return text;
       }

       function showLoading() {
           const id = 'loading-' + Date.now();
           const messageDiv = document.createElement('div');
           messageDiv.id = id;
           messageDiv.className = 'message bot';
           messageDiv.innerHTML = `
               <div class="chat-loading">
                   <div class="loading-dot"></div>
                   <div class="loading-dot"></div>
                   <div class="loading-dot"></div>
               </div>
           `;
           chatMessages.appendChild(messageDiv);
           chatMessages.scrollTop = chatMessages.scrollHeight;
           return id;
       }

       function removeLoading(id) {
           const element = document.getElementById(id);
           if (element) {
               element.remove();
           }
       }

       // Close chat when clicking outside
       document.addEventListener('click', (e) => {
           if (chatModal.classList.contains('active') && 
               !chatModal.contains(e.target) && 
               e.target !== chatButton) {
               chatModal.classList.remove('active');
           }
       });

       // Add animation to chat button on page load
       setTimeout(() => {
           chatButton.style.animation = 'pulse 2s infinite';
       }, 1000); // Changed from 3500 to 1000
    </script>
</body>
</html>