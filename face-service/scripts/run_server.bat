@echo off
echo Starting WorkPulse Face Recognition Backend Service...
cd /d "%~dp0\.."
"C:\Users\ASUS\AppData\Local\Programs\Python\Python311\python.exe" -m uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
pause
