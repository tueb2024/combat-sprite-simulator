@echo off
setlocal
set "WORK=C:\Users\tchou\combat-sprite-simulator-review"
set "BLENDER_EXE=C:\Program Files\Blender Foundation\Blender 5.2\blender.exe"
if not exist "%WORK%\output" mkdir "%WORK%\output"
"%BLENDER_EXE%" -b --python-exit-code 1 --python "%WORK%\review_hurricane.py" -- --outline "%WORK%\outline.json" --out "%WORK%\output" > "%WORK%\output\render.log" 2>&1
if errorlevel 1 (
  type "%WORK%\output\render.log"
  exit /b 1
)
echo Hurricane review render completed on GTX.
exit /b 0
