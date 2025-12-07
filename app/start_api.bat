@echo off
echo ============================================
echo 🔥 FRSM Face Recognition API
echo 📁 Running from: app/ folder
echo 🌐 Domain: frsm.jampzdev.com
echo ============================================
echo.

REM Navigate to app folder
cd /d "C:\xampp\htdocs\Capstone\app"
echo Current directory: %CD%
echo.

echo Starting Python Virtual Environment...
if exist "face_env\Scripts\activate.bat" (
    call face_env\Scripts\activate.bat
    echo ✓ Virtual environment activated.
) else (
    echo ✗ ERROR: Virtual environment not found!
    echo.
    echo To create virtual environment:
    echo 1. Make sure Python is installed
    echo 2. Run: python -m venv face_env
    echo 3. Then run this batch file again
    echo.
    pause
    exit /b 1
)

echo.
echo Checking Python version...
python --version

echo.
echo Checking requirements...
pip list | findstr Flask

echo.
echo ============================================
echo Starting Face Recognition API...
echo.
echo 📍 API URL: http://127.0.0.1:5001
echo 📍 Health Check: http://127.0.0.1:5001/api/health
echo 📍 Test Face Login: http://localhost/Capstone/login/login.php
echo.
echo ⚠️  Keep this window open while using face login
echo ============================================
echo.

python face_auth_secure.py

pause