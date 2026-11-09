dnl JumPHP's Android build of opengl (tools/android/build.sh): no link test (cross-compiled; SDL, SDL_image... are
dnl linked into the app by Gradle), the headers given by CPPFLAGS.
PHP_ARG_ENABLE([opengl], [opengl (JumPHP)], [AS_HELP_STRING([--enable-opengl], [opengl])], [no])
if test "$PHP_OPENGL" != "no"; then

  PHP_NEW_EXTENSION(opengl, php_convert.c php_opengl.c, $ext_shared)

fi
