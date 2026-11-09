@echo off
setlocal
set "WORK=C:\Users\tchou\combat-sprite-simulator-review-fleet"
set "BLENDER_EXE=C:\Program Files\Blender Foundation\Blender 5.2\blender.exe"
if not exist "%WORK%\output" mkdir "%WORK%\output"
"%BLENDER_EXE%" -b --python-exit-code 1 --python "%WORK%\render_source_airframes.py" -- --spec-dir "%WORK%\specs" --out "%WORK%\output" > "%WORK%\output\render.log" 2>&1
if errorlevel 1 (
  type "%WORK%\output\render.log"
  exit /b 1
)
echo Source-traced fleet rendered on GTX.
exit /b 0
