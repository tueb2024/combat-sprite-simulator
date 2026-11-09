@echo off
setlocal
set "WORK=C:\Users\tchou\combat-sprite-simulator-ww2"
set "BLENDER_EXE=C:\Program Files\Blender Foundation\Blender 5.2\blender.exe"
if not exist "%WORK%\output" mkdir "%WORK%\output"
"%BLENDER_EXE%" -b --python-exit-code 1 --python "%WORK%\build_ww2.py" -- --spec-dir "%WORK%\specs" --out "%WORK%\output" --aircraft all > "%WORK%\output\render.log" 2>&1
if errorlevel 1 (
  type "%WORK%\output\render.log"
  exit /b 1
)
"%BLENDER_EXE%" -b --python-exit-code 1 --python "%WORK%\assemble_atlas.py" -- --ww2 --spec-dir "%WORK%\specs" --out "%WORK%\output" > "%WORK%\output\atlas.log" 2>&1
if errorlevel 1 (
  type "%WORK%\output\atlas.log"
  exit /b 1
)
echo Blender 5.2 render and atlas complete on GTX.
exit /b 0
