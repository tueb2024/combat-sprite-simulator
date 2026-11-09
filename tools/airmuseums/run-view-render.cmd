@echo off
setlocal
set "WORK=C:\Users\tchou\combat-sprite-airmuseums"
set "BLENDER_EXE=C:\Program Files\Blender Foundation\Blender 5.2\blender.exe"
if "%~1"=="" exit /b 2
if not exist "%WORK%\output" mkdir "%WORK%\output"
"%BLENDER_EXE%" -b --python-exit-code 1 --python "%WORK%\render_glb_views.py" -- --glb "%WORK%\input\%~1.glb" --out "%WORK%\output" --slug "%~1" > "%WORK%\output\%~1.log" 2>&1
if errorlevel 1 (
  type "%WORK%\output\%~1.log"
  exit /b 1
)
echo Rendered %~1 on GTX.
exit /b 0
