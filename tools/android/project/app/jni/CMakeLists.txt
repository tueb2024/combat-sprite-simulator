# The native part of a JumPHP game on Android: SDL2 (shared: SDLActivity loads libSDL2.so), SDL2_image, SDL2_mixer,
# SDL2_ttf and OpenAL Soft (static, with the options of the standalone registry: docker/standalone/registry), and
# libmain.so: main.c + PHP (JUMPHP_PHP/<abi>/lib/libphp.a, JumPHP's extensions inside: tools/android/build.sh).
cmake_minimum_required(VERSION 3.22)
project(jumphp_game C CXX)

set(SRC ${JUMPHP_SRC})
set(PHP ${JUMPHP_PHP}/${ANDROID_ABI})

# SDL2
set(SDL_SHARED ON CACHE BOOL "" FORCE)
set(SDL_STATIC OFF CACHE BOOL "" FORCE)
set(SDL_TEST OFF CACHE BOOL "" FORCE)
add_subdirectory(${SRC}/sdl2 sdl2)
# the add-ons, static, look for SDL2::SDL2-static: the shared SDL2 (SDLActivity loads libSDL2.so)
add_library(SDL2::SDL2-static ALIAS SDL2)

# the add-ons: static, into libmain.so
set(BUILD_SHARED_LIBS OFF CACHE BOOL "" FORCE)
set(SDL2IMAGE_SAMPLES OFF CACHE BOOL "" FORCE)
set(SDL2IMAGE_INSTALL OFF CACHE BOOL "" FORCE)
set(SDL2IMAGE_VENDORED OFF CACHE BOOL "" FORCE)
set(SDL2IMAGE_BACKEND_STB ON CACHE BOOL "" FORCE)
foreach(off AVIF JXL TIF WEBP SVG QOI XCF XPM XV LBM PCX PNM TGA SAVE_JPG SAVE_PNG)
    set(SDL2IMAGE_${off} OFF CACHE BOOL "" FORCE)
endforeach()
add_subdirectory(${SRC}/sdl2_image sdl2_image)

set(SDL2MIXER_SAMPLES OFF CACHE BOOL "" FORCE)
set(SDL2MIXER_INSTALL OFF CACHE BOOL "" FORCE)
set(SDL2MIXER_VENDORED OFF CACHE BOOL "" FORCE)
set(SDL2MIXER_WAVE ON CACHE BOOL "" FORCE)
set(SDL2MIXER_VORBIS STB CACHE STRING "" FORCE)
set(SDL2MIXER_MP3 ON CACHE BOOL "" FORCE)
set(SDL2MIXER_MP3_MINIMP3 ON CACHE BOOL "" FORCE)
set(SDL2MIXER_MP3_MPG123 OFF CACHE BOOL "" FORCE)
set(SDL2MIXER_FLAC ON CACHE BOOL "" FORCE)
set(SDL2MIXER_FLAC_DRFLAC ON CACHE BOOL "" FORCE)
set(SDL2MIXER_FLAC_LIBFLAC OFF CACHE BOOL "" FORCE)
foreach(off MOD MIDI OPUS WAVPACK GME CMD)
    set(SDL2MIXER_${off} OFF CACHE BOOL "" FORCE)
endforeach()
add_subdirectory(${SRC}/sdl2_mixer sdl2_mixer)

set(SDL2TTF_SAMPLES OFF CACHE BOOL "" FORCE)
set(SDL2TTF_INSTALL OFF CACHE BOOL "" FORCE)
set(SDL2TTF_VENDORED ON CACHE BOOL "" FORCE)
set(SDL2TTF_HARFBUZZ OFF CACHE BOOL "" FORCE)
add_subdirectory(${SRC}/sdl2_ttf sdl2_ttf)

# OpenAL Soft: OpenSL ES (every Android since 2.3)
set(LIBTYPE STATIC CACHE STRING "" FORCE)
foreach(off UTILS EXAMPLES TESTS INSTALL INSTALL_CONFIG INSTALL_HRTF_DATA INSTALL_AMBDEC_PRESETS INSTALL_EXAMPLES INSTALL_UTILS EAX
        BACKEND_OBOE BACKEND_PIPEWIRE BACKEND_PULSEAUDIO BACKEND_ALSA BACKEND_OSS BACKEND_JACK BACKEND_SNDIO BACKEND_PORTAUDIO
        BACKEND_SDL2 BACKEND_SDL3 BACKEND_WAVE)
    set(ALSOFT_${off} OFF CACHE BOOL "" FORCE)
endforeach()
set(ALSOFT_BACKEND_OPENSL ON CACHE BOOL "" FORCE)
set(ALSOFT_REQUIRE_OPENSL ON CACHE BOOL "" FORCE)
add_subdirectory(${SRC}/openal openal)

# libmain.so: SDL_main (main.c) + PHP and its extensions (GD's libpng and OpenSSL beside libphp.a)
add_library(main SHARED src/main.c)
target_include_directories(main PRIVATE ${PHP}/include/php ${PHP}/include/php/main ${PHP}/include/php/Zend
    ${PHP}/include/php/TSRM ${PHP}/include/php/sapi/embed)
target_link_libraries(main PRIVATE ${PHP}/lib/libphp.a ${PHP}/lib/libpng16.a ${PHP}/lib/libssl.a ${PHP}/lib/libcrypto.a SDL2_image::SDL2_image-static SDL2_mixer::SDL2_mixer-static
    SDL2_ttf::SDL2_ttf-static OpenAL::OpenAL SDL2::SDL2 log android z m dl OpenSLES)
