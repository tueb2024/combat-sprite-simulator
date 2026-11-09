dnl JumPHP's Android build of sdl_ttf (tools/android/build.sh): no link test (cross-compiled; SDL, SDL_image... are
dnl linked into the app by Gradle), the headers given by CPPFLAGS.
PHP_ARG_ENABLE([sdl_ttf], [sdl_ttf (JumPHP)], [AS_HELP_STRING([--enable-sdl_ttf], [sdl_ttf])], [no])
if test "$PHP_SDL_TTF" != "no"; then
  AC_DEFINE(WITH_SDL_TTF, 1, [ ])
  AC_DEFINE(HAVE_SDL_TTF_H, 1, [ ])
  AC_DEFINE(HAVE_SDL_TTF, 1, [ ])
  PHP_NEW_EXTENSION(sdl_ttf, sdl_ttf.c, $ext_shared)
  PHP_ADD_EXTENSION_DEP(sdl_ttf, sdl)
fi
