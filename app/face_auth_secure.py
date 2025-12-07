# face_auth_secure.py - UPDATED FOR YOUR STRUCTURE
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
import sys
warnings.filterwarnings('ignore')

print("=" * 70)
print("🔒 SECURE FACE RECOGNITION API")
print("🔐 User-specific face authentication")
print("📁 Running from: app/ folder")
print("=" * 70)

# IMPORTANT: Update with YOUR database credentials
DB_CONFIG = {
    'host': '153.92.15.81',
    'user': 'u514031374_frsm',
    'password': 'P@55w0rdfrsm',
    'database': 'u514031374_frsm',
    'port': 3306
}

app = Flask(__name__)
# Allow all origins from your domain
CORS(app, supports_credentials=True, origins=[
    "http://localhost",
    "http://127.0.0.1",
    "https://frsm.jampzdev.com",
    "http://frsm.jampzdev.com"
])

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
            minSize=(100, 100),
            flags=cv2.CASCADE_SCALE_IMAGE
        )
        
        if len(faces) == 1:
            return faces[0]
        return None
        
    except Exception as e:
        print(f"[FACE DETECT ERROR] {e}")
        return None

def extract_face_signature(img, face_rect):
    """Extract unique face signature"""
    try:
        x, y, w, h = face_rect
        
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
        
        # Apply histogram equalization
        face_eq = cv2.equalizeHist(face_gray)
        
        # Normalize
        face_normalized = face_eq.astype(np.float32) / 255.0
        
        # Extract features
        features = []
        grid_size = 8
        cell_h = face_normalized.shape[0] // grid_size
        cell_w = face_normalized.shape[1] // grid_size
        
        for i in range(grid_size):
            for j in range(grid_size):
                cell = face_normalized[i*cell_h:(i+1)*cell_h, j*cell_w:(j+1)*cell_w]
                if cell.size > 0:
                    features.append(float(cell.mean()))
                    features.append(float(cell.std()))
                    features.append(float(np.median(cell)))
        
        # Add face geometry features
        img_h, img_w = img.shape[:2]
        features.append(float(x / img_w))
        features.append(float(y / img_h))
        features.append(float((x + w/2) / img_w))
        features.append(float((y + h/2) / img_h))
        features.append(float(w / img_w))
        features.append(float(h / img_h))
        features.append(float(w / h))
        
        # Create unique hash
        face_bytes = face_normalized.tobytes()
        face_hash = hashlib.sha256(face_bytes).hexdigest()
        
        return {
            'features': features,
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
        f1 = np.array(features1, dtype=np.float64)
        f2 = np.array(features2, dtype=np.float64)
        
        min_len = min(len(f1), len(f2))
        if min_len < 10:
            return 0.0
        
        f1 = f1[:min_len]
        f2 = f2[:min_len]
        
        # Calculate cosine similarity
        dot_product = np.dot(f1, f2)
        norm1 = np.linalg.norm(f1)
        norm2 = np.linalg.norm(f2)
        
        if norm1 == 0 or norm2 == 0:
            return 0.0
        
        similarity = dot_product / (norm1 * norm2)
        
        return float(max(0.0, similarity))
        
    except Exception as e:
        print(f"[SIMILARITY ERROR] {e}")
        return 0.0

def validate_face_quality(img, face_rect):
    """Validate face quality before registration"""
    try:
        x, y, w, h = face_rect
        
        if w < 100 or h < 100:
            return False, "Face too small. Move closer to camera."
        
        img_h, img_w = img.shape[:2]
        center_x = x + w/2
        center_y = y + h/2
        
        if (center_x < img_w * 0.15 or center_x > img_w * 0.85 or
            center_y < img_h * 0.15 or center_y > img_h * 0.85):
            return False, "Face should be centered in frame."
        
        aspect_ratio = w / h
        if aspect_ratio < 0.7 or aspect_ratio > 1.3:
            return False, "Face at angle. Look directly at camera."
        
        return True, "Face quality OK"
        
    except Exception as e:
        return False, f"Quality check error: {e}"

@app.route('/api/face/recognize', methods=['POST'])
def recognize_face():
    """Find which user's face matches the input image"""
    try:
        data = request.json
        image_base64 = data.get('image')
        
        if not image_base64:
            return jsonify({
                'success': False,
                'error': 'Missing image'
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
                'recognized': False,
                'message': 'No clear face detected'
            })
        
        input_face = extract_face_signature(img, face_rect)
        if input_face is None:
            return jsonify({
                'success': True,
                'recognized': False,
                'message': 'Failed to process face'
            })
        
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
            WHERE face_registered = 1 AND is_verified = 1
        """)
        all_users = cursor.fetchall()
        cursor.close()
        connection.close()
        
        if not all_users:
            return jsonify({
                'success': True,
                'recognized': False,
                'message': 'No registered faces in system'
            })
        
        # Find the best match
        best_match = None
        best_similarity = 0
        best_user = None
        
        for user in all_users:
            try:
                stored_data = json.loads(user['face_encoding'])
                stored_features = stored_data['signature']
                
                similarity = calculate_similarity(input_face['features'], stored_features)
                
                if similarity > best_similarity:
                    best_similarity = similarity
                    best_user = user
            except Exception as e:
                print(f"Error processing user {user['id']}: {e}")
                continue
        
        THRESHOLD = 0.75
        
        if best_similarity >= THRESHOLD and best_user:
            # Update last face login
            connection = get_db_connection()
            if connection:
                cursor = connection.cursor()
                cursor.execute("UPDATE users SET last_face_login = NOW() WHERE id = %s", (best_user['id'],))
                connection.commit()
                cursor.close()
                connection.close()
            
            return jsonify({
                'success': True,
                'recognized': True,
                'user': {
                    'id': best_user['id'],
                    'email': best_user['email'],
                    'first_name': best_user['first_name'],
                    'last_name': best_user['last_name']
                },
                'similarity': float(best_similarity),
                'threshold': THRESHOLD,
                'message': 'Face recognized successfully!'
            })
        else:
            return jsonify({
                'success': True,
                'recognized': False,
                'best_similarity': float(best_similarity),
                'threshold': THRESHOLD,
                'message': 'Face not recognized. No matching user found.'
            })
            
    except Exception as e:
        print(f"[RECOGNIZE ERROR] {str(e)}")
        traceback.print_exc()
        return jsonify({
            'success': False,
            'error': f'Recognition failed: {str(e)}'
        }), 500

@app.route('/api/face/register', methods=['POST'])
def register_face():
    """Register user's face"""
    try:
        data = request.json
        user_id = data.get('user_id')
        image_base64 = data.get('image')
        
        if not user_id or not image_base64:
            return jsonify({
                'success': False,
                'error': 'Missing user_id or image'
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
                'success': False,
                'error': 'Could not detect a clear face. Ensure: 1. Only one person in frame, 2. Good lighting, 3. Face clearly visible'
            }), 400
        
        quality_ok, quality_msg = validate_face_quality(img, face_rect)
        if not quality_ok:
            return jsonify({
                'success': False,
                'error': quality_msg
            }), 400
        
        face_data = extract_face_signature(img, face_rect)
        if face_data is None:
            return jsonify({
                'success': False,
                'error': 'Failed to extract face features'
            }), 400
        
        connection = get_db_connection()
        if not connection:
            return jsonify({
                'success': False,
                'error': 'Database connection failed'
            }), 500
        
        cursor = connection.cursor(dictionary=True)
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
        
        # Prepare face data
        face_info = {
            'signature': face_data['features'],
            'hash': face_data['hash'],
            'registered_at': datetime.now().isoformat(),
            'rect': face_data['rect'],
            'image_size': img.shape,
            'feature_count': face_data['feature_count']
        }
        
        face_json = json.dumps(face_info)
        
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
            return jsonify({
                'success': True,
                'message': 'Face registered successfully!',
                'face_hash': face_data['hash'][:16] + '...',
                'features': face_data['feature_count'],
                'user_id': user_id,
                'user_email': user['email']
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
    """Verify face for a specific user"""
    try:
        data = request.json
        user_id = data.get('user_id')
        image_base64 = data.get('image')
        
        if not user_id or not image_base64:
            return jsonify({
                'success': False,
                'error': 'Missing user_id or image'
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
                'authenticated': False,
                'message': 'No clear face detected'
            })
        
        input_face = extract_face_signature(img, face_rect)
        if input_face is None:
            return jsonify({
                'success': True,
                'authenticated': False,
                'message': 'Failed to process face'
            })
        
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
        
        hash_match = input_face['hash'] == stored_hash
        similarity = calculate_similarity(input_face['features'], stored_features)
        
        THRESHOLD = 0.75
        
        if similarity >= THRESHOLD or hash_match:
            connection = get_db_connection()
            if connection:
                cursor = connection.cursor()
                cursor.execute("UPDATE users SET last_face_login = NOW() WHERE id = %s", (user_id,))
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
                'hash_match': hash_match
            })
        else:
            return jsonify({
                'success': True,
                'authenticated': False,
                'message': 'Face does not match',
                'similarity': float(similarity),
                'threshold': THRESHOLD
            })
            
    except Exception as e:
        return jsonify({
            'success': False,
            'error': f'Verification failed: {str(e)}'
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
        <title>🔒 Face Recognition API</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
        </style>
    </head>
    <body>
        <h1>🔒 Face Recognition API</h1>
        <p>Running from <code>app/</code> folder</p>
        <p><strong>Domain:</strong> frsm.jampzdev.com</p>
        <p><a href="/api/health">Health Check</a></p>
    </body>
    </html>
    """

if __name__ == '__main__':
    print("\n🚀 Starting Face Recognition API")
    print(f"📁 Location: app/ folder")
    print(f"🌐 Domain: frsm.jampzdev.com")
    print(f"⚡ Server: http://127.0.0.1:5001")
    print("=" * 70)
    
    app.run(host='127.0.0.1', port=5001, debug=True, threaded=True, use_reloader=False)