dnl JumPHP's Android build of sdl_mixer (tools/android/build.sh): no link test (cross-compiled; SDL, SDL_image... are
dnl linked into the app by Gradle), the headers given by CPPFLAGS.
PHP_ARG_ENABLE([sdl_mixer], [sdl_mixer (JumPHP)], [AS_HELP_STRING([--enable-sdl_mixer], [sdl_mixer])], [no])
if test "$PHP_SDL_MIXER" != "no"; then
  AC_DEFINE(HAVE_SDL_MIXER, 1, [ ])
  AC_DEFINE(HAVE_SDL_MIXER_FEATURE, 1, [ ])
  PHP_NEW_EXTENSION(sdl_mixer, src/effect_position.c src/effect_stereoreverse.c src/Mix_Music.c src/Mix_Chunk.c src/music.c src/mixer.c php_sdl_mixer.c, $ext_shared)
  PHP_ADD_BUILD_DIR($ext_builddir/src)
  PHP_ADD_EXTENSION_DEP(sdl_mixer, sdl)
fi
