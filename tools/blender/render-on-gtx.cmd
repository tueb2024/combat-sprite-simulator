@echo off
setlocal
set "ASSET_DIR=C:\Users\tchou\combat-sprite-simulator-assets"
set "BLENDER_EXE=C:\Program Files\Blender Foundation\Blender 5.2\blender.exe"
"%BLENDER_EXE%" -b --python "%ASSET_DIR%\render_aircraft.py" -- --spec "%ASSET_DIR%\aircraft-spec.json" --out "%ASSET_DIR%"
if errorlevel 1 exit /b %errorlevel%
"%BLENDER_EXE%" -b --python "%ASSET_DIR%\assemble_atlas.py" -- --out "%ASSET_DIR%"
exit /b %errorlevel%
