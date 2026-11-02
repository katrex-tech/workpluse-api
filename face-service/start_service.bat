@echo off
title WorkPulse Face Recognition Microservice (Port 8000)
echo ========================================================
echo Starting WorkPulse ArcFace Deep Biometrics Service...
echo Host: 0.0.0.0:8000
echo ========================================================
cd /d "%~dp0"
python -m uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
pause
