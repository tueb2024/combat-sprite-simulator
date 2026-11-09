dnl JumPHP's Android build of sdl (tools/android/build.sh): no link test (cross-compiled; SDL, SDL_image... are
dnl linked into the app by Gradle), the headers given by CPPFLAGS.
PHP_ARG_ENABLE([sdl], [sdl (JumPHP)], [AS_HELP_STRING([--enable-sdl], [sdl])], [no])
if test "$PHP_SDL" != "no"; then
  AC_DEFINE(HAVE_SDL2, 1, [ ])
  AC_DEFINE(HAVE_SDL_GETREVISIONNUMBER, 1, [ ])
  PHP_NEW_EXTENSION(sdl, src/blendmode.c src/cpuinfo.c src/error.c src/event.c src/filesystem.c src/glcontext.c src/joystick.c src/keyboard.c src/messagebox.c src/mouse.c src/mutex.c src/php_sdl.c src/pixels.c src/platform.c src/power.c src/rect.c src/render.c src/rwops.c src/sdl.c src/shape.c src/surface.c src/timer.c src/version.c src/video.c src/window.c, $ext_shared)
  PHP_ADD_BUILD_DIR($ext_builddir/src)
fi
