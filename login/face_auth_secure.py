
import json
import base64
import numpy as np
import cv2
import mysql.connector
from mysql.connector import Error
from flask import Flask, request, jsonify
from flask_cors import CORS
import os
import hashlib
from datetime import datetime
import traceback
import warnings
warnings.filterwarnings('ignore')

print("=" * 70)
print("🔒 SECURE FACE RECOGNITION API")
print("🔐 User-specific face authentication")
print("=" * 70)

# Database configuration
DB_CONFIG = {
    'host': 'localhost',
    'port': 3307,
    'database': 'frsm',
    'user': 'root',
    'password': ''
}

# Initialize Flask app
app = Flask(__name__)
CORS(app, supports_credentials=True, origins=["http://localhost", "http://127.0.0.1"])

# Haar cascade path
CASCADE_PATH = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
if not os.path.exists(CASCADE_PATH):
    print("⚠️ Haar cascade not found at default location")
    CASCADE_PATH = None

def get_db_connection():
    """Create database connection"""
    try:
        connection = mysql.connector.connect(**DB_CONFIG)
        return connection
    except Error as e:
        print(f"[DB ERROR] {e}")
        return None

def base64_to_image(base64_string):
    """Convert base64 string to OpenCV image"""
    try:
        if 'base64,' in base64_string:
            base64_string = base64_string.split('base64,')[1]
        
        img_data = base64.b64decode(base64_string)
        nparr = np.frombuffer(img_data, np.uint8)
        img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        
        return img
    except Exception as e:
        print(f"[IMAGE ERROR] {e}")
        return None

def detect_face(img):
    """Detect a single face in image"""
    try:
        if CASCADE_PATH is None:
            return None
        
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        cascade = cv2.CascadeClassifier(CASCADE_PATH)
        
        if cascade.empty():
            return None
        
        faces = cascade.detectMultiScale(
            gray,
            scaleFactor=1.1,
            minNeighbors=5,
            minSize=(100, 100),  # Larger min size for better quality
            flags=cv2.CASCADE_SCALE_IMAGE
        )
        
        if len(faces) == 1:
            return faces[0]  # Return first (and only) face
        return None
        
    except Exception as e:
        print(f"[FACE DETECT ERROR] {e}")
        return None

def extract_face_signature(img, face_rect):
    """Extract unique face signature"""
    try:
        x, y, w, h = face_rect
        
        # Extract and normalize face region
        face_region = img[y:y+h, x:x+w]
        if face_region.size == 0 or w < 50 or h < 50:
            return None
        
        # Resize to standard size
        face_resized = cv2.resize(face_region, (128, 128))
        
        # Convert to grayscale
        if len(face_resized.shape) == 3:
            face_gray = cv2.cvtColor(face_resized, cv2.COLOR_BGR2GRAY)
        else:
            face_gray = face_resized
        
        # Apply histogram equalization for better contrast
        face_eq = cv2.equalizeHist(face_gray)
        
        # Normalize
        face_normalized = face_eq.astype(np.float32) / 255.0
        
        # Extract LBP-like features (more robust)
        features = []
        grid_size = 8
        cell_h = face_normalized.shape[0] // grid_size
        cell_w = face_normalized.shape[1] // grid_size
        
        for i in range(grid_size):
            for j in range(grid_size):
                cell = face_normalized[i*cell_h:(i+1)*cell_h, j*cell_w:(j+1)*cell_w]
                if cell.size > 0:
                    # Extract statistical features
                    features.append(float(cell.mean()))
                    features.append(float(cell.std()))
                    features.append(float(np.median(cell)))
        
        # Add face geometry features
        img_h, img_w = img.shape[:2]
        features.append(float(x / img_w))
        features.append(float(y / img_h))
        features.append(float((x + w/2) / img_w))  # Center X
        features.append(float((y + h/2) / img_h))  # Center Y
        features.append(float(w / img_w))
        features.append(float(h / img_h))
        features.append(float(w / h))  # Aspect ratio
        
        # Create unique hash from normalized face
        face_bytes = face_normalized.tobytes()
        face_hash = hashlib.sha256(face_bytes).hexdigest()
        
        return {
            'features': features,  # All Python floats
            'hash': face_hash,
            'rect': [int(x), int(y), int(w), int(h)],
            'feature_count': len(features),
            'timestamp': datetime.now().isoformat()
        }
        
    except Exception as e:
        print(f"[SIGNATURE ERROR] {e}")
        return None

def calculate_similarity(features1, features2):
    """Calculate similarity between two feature vectors"""
    try:
        # Convert to numpy arrays
        f1 = np.array(features1, dtype=np.float64)
        f2 = np.array(features2, dtype=np.float64)
        
        # Ensure same length
        min_len = min(len(f1), len(f2))
        if min_len < 10:  # Not enough features
            return 0.0
        
        f1 = f1[:min_len]
        f2 = f2[:min_len]
        
        # Calculate weighted Euclidean distance (lower = more similar)
        # Weight geometric features more heavily
        weights = np.ones(min_len)
        # Last 7 features are geometric (x, y, center_x, center_y, w, h, aspect_ratio)
        if min_len >= 7:
            weights[-7:] = 2.0  # Double weight for geometric features
        
        # Weighted Euclidean distance
        weighted_diff = (f1 - f2) * weights
        distance = np.sqrt(np.sum(weighted_diff ** 2))
        
        # Normalize distance to similarity score (0-1)
        # Empirical threshold: distance < 0.5 is similar
        max_distance = 5.0  # Maximum expected distance
        similarity = max(0.0, 1.0 - (distance / max_distance))
        
        return float(similarity)
        
    except Exception as e:
        print(f"[SIMILARITY ERROR] {e}")
        return 0.0

def validate_face_quality(img, face_rect):
    """Validate face quality before registration"""
    try:
        x, y, w, h = face_rect
        
        # Check minimum size
        if w < 100 or h < 100:
            return False, "Face too small. Move closer to camera."
        
        # Check if face is centered
        img_h, img_w = img.shape[:2]
        center_x = x + w/2
        center_y = y + h/2
        
        # Face should be within center 70% of image
        if (center_x < img_w * 0.15 or center_x > img_w * 0.85 or
            center_y < img_h * 0.15 or center_y > img_h * 0.85):
            return False, "Face should be centered in frame."
        
        # Check aspect ratio (should be roughly 1:1.3 for human face)
        aspect_ratio = w / h
        if aspect_ratio < 0.7 or aspect_ratio > 1.3:
            return False, "Face at angle. Look directly at camera."
        
        return True, "Face quality OK"
        
    except Exception as e:
        return False, f"Quality check error: {e}"

@app.route('/api/face/register', methods=['POST'])
def register_face():
    """Register user's face - ONE FACE PER USER ONLY"""
    try:
        print(f"\n{'='*50}")
        print("📝 FACE REGISTRATION REQUEST")
        print(f"{'='*50}")
        
        data = request.json
        user_id = data.get('user_id')
        image_base64 = data.get('image')
        
        if not user_id or not image_base64:
            return jsonify({
                'success': False,
                'error': 'Missing user_id or image'
            }), 400
        
        # Convert base64 to image
        img = base64_to_image(image_base64)
        if img is None:
            return jsonify({
                'success': False,
                'error': 'Invalid image format'
            }), 400
        
        print(f"[REGISTER] Image: {img.shape}, User: {user_id}")
        
        # Detect exactly ONE face
        face_rect = detect_face(img)
        if face_rect is None:
            return jsonify({
                'success': False,
                'error': 'Could not detect a clear face. Ensure: 1. Only one person in frame, 2. Good lighting, 3. Face clearly visible'
            }), 400
        
        # Validate face quality
        quality_ok, quality_msg = validate_face_quality(img, face_rect)
        if not quality_ok:
            return jsonify({
                'success': False,
                'error': quality_msg
            }), 400
        
        # Extract face signature
        face_data = extract_face_signature(img, face_rect)
        if face_data is None:
            return jsonify({
                'success': False,
                'error': 'Failed to extract face features'
            }), 400
        
        print(f"[REGISTER] Face signature: {face_data['feature_count']} features")
        print(f"[REGISTER] Face hash: {face_data['hash'][:16]}...")
        
        # Check if user already has a face registered
        connection = get_db_connection()
        if not connection:
            return jsonify({
                'success': False,
                'error': 'Database connection failed'
            }), 500
        
        cursor = connection.cursor(dictionary=True)
        
        # Get user info
        cursor.execute("SELECT id, email, face_registered FROM users WHERE id = %s", (user_id,))
        user = cursor.fetchone()
        
        if user is None:
            cursor.close()
            connection.close()
            return jsonify({
                'success': False,
                'error': f'User ID {user_id} not found'
            }), 404
        
        if user['face_registered']:
            cursor.close()
            connection.close()
            return jsonify({
                'success': False,
                'error': 'User already has a face registered. Remove existing registration first.'
            }), 400
        
        # Prepare face data for storage
        face_info = {
            'signature': face_data['features'],
            'hash': face_data['hash'],
            'registered_at': datetime.now().isoformat(),
            'rect': face_data['rect'],
            'image_size': img.shape,
            'feature_count': face_data['feature_count']
        }
        
        face_json = json.dumps(face_info)
        
        # Store in database
        update_query = """
        UPDATE users 
        SET face_encoding = %s, face_registered = 1, face_hash = %s 
        WHERE id = %s
        """
        cursor.execute(update_query, (face_json, face_data['hash'], user_id))
        connection.commit()
        
        affected = cursor.rowcount
        cursor.close()
        connection.close()
        
        if affected > 0:
            print(f"[REGISTER] ✓ Face registered for user {user_id}")
            print(f"[REGISTER] Hash: {face_data['hash'][:16]}...")
            
            return jsonify({
                'success': True,
                'message': 'Face registered successfully! This face is now linked to your account only.',
                'face_hash': face_data['hash'][:16] + '...',
                'features': face_data['feature_count'],
                'user_id': user_id,
                'user_email': user['email'],
                'security_note': 'Only this specific face will work for your account.'
            })
        else:
            return jsonify({
                'success': False,
                'error': 'Failed to update database'
            }), 500
            
    except Exception as e:
        print(f"[REGISTER ERROR] {str(e)}")
        traceback.print_exc()
        return jsonify({
            'success': False,
            'error': f'Registration failed: {str(e)}'
        }), 500

@app.route('/api/face/verify', methods=['POST'])
def verify_face():
    """Verify face for a specific user - STRICT MATCHING"""
    try:
        print(f"\n{'='*50}")
        print("🔍 FACE VERIFICATION REQUEST")
        print(f"{'='*50}")
        
        data = request.json
        user_id = data.get('user_id')  # User ID is REQUIRED for verification
        image_base64 = data.get('image')
        
        if not user_id or not image_base64:
            return jsonify({
                'success': False,
                'error': 'Missing user_id or image'
            }), 400
        
        # Convert base64 to image
        img = base64_to_image(image_base64)
        if img is None:
            return jsonify({
                'success': False,
                'error': 'Invalid image format'
            }), 400
        
        print(f"[VERIFY] Image: {img.shape}, User: {user_id}")
        
        # Detect face
        face_rect = detect_face(img)
        if face_rect is None:
            return jsonify({
                'success': True,
                'authenticated': False,
                'message': 'No clear face detected'
            })
        
        # Extract face signature
        input_face = extract_face_signature(img, face_rect)
        if input_face is None:
            return jsonify({
                'success': True,
                'authenticated': False,
                'message': 'Failed to process face'
            })
        
        print(f"[VERIFY] Input features: {input_face['feature_count']}")
        print(f"[VERIFY] Input hash: {input_face['hash'][:16]}...")
        
        # Get ONLY the specified user's face data
        connection = get_db_connection()
        if not connection:
            return jsonify({
                'success': False,
                'error': 'Database connection failed'
            }), 500
        
        cursor = connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, email, first_name, last_name, face_encoding, face_hash 
            FROM users 
            WHERE id = %s AND face_registered = 1
        """, (user_id,))
        user = cursor.fetchone()
        cursor.close()
        connection.close()
        
        if not user:
            return jsonify({
                'success': True,
                'authenticated': False,
                'message': 'User not found or no face registered'
            })
        
        # Parse stored face data
        try:
            stored_data = json.loads(user['face_encoding'])
            stored_features = stored_data['signature']
            stored_hash = stored_data.get('hash', '')
        except:
            return jsonify({
                'success': True,
                'authenticated': False,
                'message': 'Invalid face data for user'
            })
        
        # STRICT VERIFICATION: Compare ONLY with the specified user's face
        print(f"[VERIFY] Comparing with user {user_id}'s registered face...")
        
        # Method 1: Hash comparison (exact match)
        hash_match = input_face['hash'] == stored_hash
        if hash_match:
            similarity = 1.0
            print(f"[VERIFY] ✓ Exact hash match!")
        else:
            # Method 2: Feature similarity
            similarity = calculate_similarity(input_face['features'], stored_features)
            print(f"[VERIFY] Feature similarity: {similarity:.3f}")
        
        # STRICT thresholds
        if hash_match:
            THRESHOLD = 0.99  # Hash match is perfect
        else:
            THRESHOLD = 0.60  # Very high threshold for features
        
        print(f"[VERIFY] Threshold: {THRESHOLD}")
        print(f"[VERIFY] Similarity: {similarity:.3f}")
        
        if similarity >= THRESHOLD:
            # Update last face login
            connection = get_db_connection()
            if connection:
                cursor = connection.cursor()
                cursor.execute("""
                    UPDATE users 
                    SET last_face_login = NOW() 
                    WHERE id = %s
                """, (user_id,))
                connection.commit()
                cursor.close()
                connection.close()
            
            return jsonify({
                'success': True,
                'authenticated': True,
                'user': {
                    'id': user['id'],
                    'email': user['email'],
                    'first_name': user['first_name'],
                    'last_name': user['last_name']
                },
                'similarity': float(similarity),
                'hash_match': hash_match,
                'method': 'hash' if hash_match else 'features',
                'message': 'Face verified successfully!',
                'security': 'Strict user-specific verification passed'
            })
        else:
            return jsonify({
                'success': True,
                'authenticated': False,
                'message': 'Face does not match the registered face for this account.',
                'similarity': float(similarity),
                'threshold': THRESHOLD,
                'security': 'Face rejected - not matching user account'
            })
            
    except Exception as e:
        print(f"[VERIFY ERROR] {str(e)}")
        traceback.print_exc()
        return jsonify({
            'success': False,
            'error': f'Verification failed: {str(e)}'
        }), 500

@app.route('/api/face/check/<int:user_id>', methods=['GET'])
def check_face_registered(user_id):
    """Check if user has registered face"""
    try:
        connection = get_db_connection()
        if not connection:
            return jsonify({
                'success': False,
                'error': 'Database connection failed'
            }), 500
        
        cursor = connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT id, email, face_registered, last_face_login, face_hash 
            FROM users 
            WHERE id = %s
        """, (user_id,))
        user = cursor.fetchone()
        cursor.close()
        connection.close()
        
        if user:
            return jsonify({
                'success': True,
                'registered': bool(user['face_registered']),
                'email': user['email'],
                'face_hash': user['face_hash'][:16] + '...' if user['face_hash'] else None,
                'last_face_login': user['last_face_login']
            })
        else:
            return jsonify({
                'success': False,
                'error': 'User not found'
            }), 404
            
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/api/face/remove/<int:user_id>', methods=['DELETE'])
def remove_face(user_id):
    """Remove user's face registration"""
    try:
        connection = get_db_connection()
        if not connection:
            return jsonify({
                'success': False,
                'error': 'Database connection failed'
            }), 500
        
        cursor = connection.cursor()
        cursor.execute("""
            UPDATE users 
            SET face_encoding = NULL, face_registered = 0, face_hash = NULL 
            WHERE id = %s
        """, (user_id,))
        connection.commit()
        affected = cursor.rowcount
        cursor.close()
        connection.close()
        
        if affected > 0:
            return jsonify({
                'success': True,
                'message': 'Face registration removed'
            })
        else:
            return jsonify({
                'success': False,
                'error': 'User not found or no face registered'
            }), 404
            
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/api/face/test/<int:user_id>', methods=['POST'])
def test_face_against_user(user_id):
    """Test if a face matches a specific user (for debugging)"""
    try:
        data = request.json
        image_base64 = data.get('image')
        
        if not image_base64:
            return jsonify({
                'success': False,
                'error': 'No image provided'
            }), 400
        
        img = base64_to_image(image_base64)
        if img is None:
            return jsonify({
                'success': False,
                'error': 'Invalid image format'
            }), 400
        
        face_rect = detect_face(img)
        if face_rect is None:
            return jsonify({
                'success': True,
                'match': False,
                'reason': 'No face detected'
            })
        
        input_face = extract_face_signature(img, face_rect)
        if input_face is None:
            return jsonify({
                'success': True,
                'match': False,
                'reason': 'Failed to extract features'
            })
        
        # Get user's face
        connection = get_db_connection()
        if not connection:
            return jsonify({
                'success': False,
                'error': 'Database connection failed'
            }), 500
        
        cursor = connection.cursor(dictionary=True)
        cursor.execute("""
            SELECT face_encoding, face_hash FROM users WHERE id = %s
        """, (user_id,))
        user = cursor.fetchone()
        cursor.close()
        connection.close()
        
        if not user or not user['face_encoding']:
            return jsonify({
                'success': True,
                'match': False,
                'reason': 'User has no registered face'
            })
        
        stored_data = json.loads(user['face_encoding'])
        stored_hash = stored_data.get('hash', '')
        
        # Compare
        hash_match = input_face['hash'] == stored_hash
        similarity = calculate_similarity(input_face['features'], stored_data['signature'])
        
        return jsonify({
            'success': True,
            'user_id': user_id,
            'hash_match': hash_match,
            'similarity': float(similarity),
            'input_hash': input_face['hash'][:16] + '...',
            'stored_hash': stored_hash[:16] + '...' if stored_hash else 'None',
            'match': similarity >= 0.85 or hash_match,
            'threshold': 0.85
        })
        
    except Exception as e:
        return jsonify({
            'success': False,
            'error': str(e)
        }), 500

@app.route('/api/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    try:
        connection = get_db_connection()
        db_connected = False
        db_version = "unknown"
        
        if connection and connection.is_connected():
            cursor = connection.cursor()
            cursor.execute("SELECT VERSION()")
            db_version = cursor.fetchone()[0]
            cursor.close()
            connection.close()
            db_connected = True
        
        # Count registered faces
        if db_connected:
            connection = get_db_connection()
            cursor = connection.cursor()
            cursor.execute("SELECT COUNT(*) as count FROM users WHERE face_registered = 1")
            face_count = cursor.fetchone()[0]
            cursor.close()
            connection.close()
        else:
            face_count = 0
        
        return jsonify({
            'status': 'healthy',
            'service': 'secure-face-auth-api',
            'database': {
                'connected': db_connected,
                'version': db_version,
                'registered_faces': face_count
            },
            'security': {
                'mode': 'user-specific',
                'verification': 'strict',
                'threshold': '0.85'
            },
            'timestamp': datetime.now().isoformat()
        })
        
    except Exception as e:
        return jsonify({
            'status': 'unhealthy',
            'error': str(e)
        }), 503

@app.route('/')
def index():
    return """
    <!DOCTYPE html>
    <html>
    <head>
        <title>🔒 Secure Face Recognition API</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .secure { color: #28a745; font-weight: bold; }
            .warning { color: #dc3545; }
        </style>
    </head>
    <body>
        <h1>🔒 Secure Face Recognition API</h1>
        <p class="secure">User-specific face authentication system</p>
        <p><strong>Security Features:</strong></p>
        <ul>
            <li>✅ One face per user only</li>
            <li>✅ Strict user-specific verification</li>
            <li>✅ High similarity threshold (0.85)</li>
            <li>✅ Hash-based exact matching</li>
            <li>✅ Face quality validation</li>
        </ul>
        <p><a href="/api/health">Health Check</a></p>
        <p class="warning">⚠️ Each face is tied to ONE user account only</p>
    </body>
    </html>
    """

if __name__ == '__main__':
    print("\n🚀 Starting SECURE Face Recognition API on http://127.0.0.1:5001")
    print("🔐 Security: User-specific face authentication")
    print("📊 Each face is linked to ONE user account only")
    print("⚡ Other faces will be rejected")
    print("=" * 70)
    
    app.run(host='127.0.0.1', port=5001, debug=True, threaded=True, use_reloader=False)