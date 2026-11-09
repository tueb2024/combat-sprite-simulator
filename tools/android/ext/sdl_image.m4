dnl JumPHP's Android build of sdl_image (tools/android/build.sh): no link test (cross-compiled; SDL, SDL_image... are
dnl linked into the app by Gradle), the headers given by CPPFLAGS.
PHP_ARG_ENABLE([sdl_image], [sdl_image (JumPHP)], [AS_HELP_STRING([--enable-sdl_image], [sdl_image])], [no])
if test "$PHP_SDL_IMAGE" != "no"; then
  AC_DEFINE(HAVE_SDL_IMAGE, 1, [ ])
  AC_DEFINE(HAVE_SDL_IMAGE_FEATURE, 1, [ ])
  PHP_NEW_EXTENSION(sdl_image, php_sdl_image.c, $ext_shared)
  PHP_ADD_EXTENSION_DEP(sdl_image, sdl)
fi
