dnl JumPHP's Android build of openal (tools/android/build.sh): no link test (cross-compiled; SDL, SDL_image... are
dnl linked into the app by Gradle), the headers given by CPPFLAGS.
PHP_ARG_ENABLE([openal], [openal (JumPHP)], [AS_HELP_STRING([--enable-openal], [openal])], [no])
if test "$PHP_OPENAL" != "no"; then
  AC_DEFINE(WITH_OPENAL, 1, [ ])
  AC_DEFINE(HAVE_AL_AL_H, 1, [ ])
  AC_DEFINE(HAVE_AL_ALC_H, 1, [ ])
  AC_DEFINE(HAVE_AL_ALEXT_H, 1, [ ])
  PHP_NEW_EXTENSION(openal, php_openal.c, $ext_shared)

fi
