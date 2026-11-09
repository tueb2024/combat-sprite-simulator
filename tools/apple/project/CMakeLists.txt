# The native part of a JumPHP game on macOS and iOS (tools/apple/build.sh): SDL2, SDL2_image, SDL2_mixer, SDL2_ttf
# and OpenAL Soft, static, with the options of the Android app (tools/android/project/app/jni/CMakeLists.txt), and the
# app's executable: main.c + PHP (JUMPHP_PHP/lib/libphp.a, JumPHP's extensions inside), as a bundle (<Game>.app).
# build.sh then puts the game, the icon and Info.plist into the bundle and signs it.
cmake_minimum_required(VERSION 3.22)
project(jumphp_game C CXX OBJC)

set(SRC ${JUMPHP_SRC})
set(PHP ${JUMPHP_PHP})

# SDL2, static (SDL2main: UIKit's main on iOS, which calls main.c's main as SDL_main)
set(SDL_SHARED OFF CACHE BOOL "" FORCE)
set(SDL_STATIC ON CACHE BOOL "" FORCE)
set(SDL_TEST OFF CACHE BOOL "" FORCE)
add_subdirectory(${SRC}/sdl2 sdl2)

set(BUILD_SHARED_LIBS OFF CACHE BOOL "" FORCE)
set(SDL2IMAGE_SAMPLES OFF CACHE BOOL "" FORCE)
set(SDL2IMAGE_INSTALL OFF CACHE BOOL "" FORCE)
set(SDL2IMAGE_VENDORED OFF CACHE BOOL "" FORCE)
set(SDL2IMAGE_BACKEND_STB ON CACHE BOOL "" FORCE)
set(SDL2IMAGE_BACKEND_IMAGEIO OFF CACHE BOOL "" FORCE)
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

# OpenAL Soft: CoreAudio (macOS and iOS)
set(LIBTYPE STATIC CACHE STRING "" FORCE)
foreach(off UTILS EXAMPLES TESTS INSTALL INSTALL_CONFIG INSTALL_HRTF_DATA INSTALL_AMBDEC_PRESETS INSTALL_EXAMPLES INSTALL_UTILS EAX
        OSX_FRAMEWORK BACKEND_OBOE BACKEND_OPENSL BACKEND_PIPEWIRE BACKEND_PULSEAUDIO BACKEND_ALSA BACKEND_OSS BACKEND_JACK
        BACKEND_SNDIO BACKEND_PORTAUDIO BACKEND_SDL2 BACKEND_SDL3 BACKEND_WAVE)
    set(ALSOFT_${off} OFF CACHE BOOL "" FORCE)
endforeach()
set(ALSOFT_BACKEND_COREAUDIO ON CACHE BOOL "" FORCE)
set(ALSOFT_REQUIRE_COREAUDIO ON CACHE BOOL "" FORCE)
add_subdirectory(${SRC}/openal openal)

# the app: main.c + PHP and its extensions (GD's libpng and OpenSSL beside libphp.a)
add_executable(game MACOSX_BUNDLE main.c)
set_target_properties(game PROPERTIES OUTPUT_NAME ${JUMPHP_GAME_ID})
target_compile_definitions(game PRIVATE JUMPHP_GAME_ID="${JUMPHP_GAME_ID}")
target_include_directories(game PRIVATE ${PHP}/include/php ${PHP}/include/php/main ${PHP}/include/php/Zend
    ${PHP}/include/php/TSRM ${PHP}/include/php/sapi/embed)
if(IOS)
    target_link_libraries(game PRIVATE SDL2::SDL2main)
endif()
target_link_libraries(game PRIVATE ${PHP}/lib/libphp.a ${PHP}/lib/libpng16.a ${PHP}/lib/libssl.a ${PHP}/lib/libcrypto.a
    SDL2_image::SDL2_image-static SDL2_mixer::SDL2_mixer-static SDL2_ttf::SDL2_ttf-static OpenAL::OpenAL SDL2::SDL2-static
    z resolv c++ "-framework Foundation" "-framework Security")
