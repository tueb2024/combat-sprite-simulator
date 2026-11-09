# The native Android app of a game (bin/jumphp android <game>, tools/android/build.sh): PHP 8.5 cross-built with
# the NDK (embed SAPI, JumPHP's extensions inside) + SDL2 and its add-ons + OpenAL Soft, in SDL's Android project
# (Gradle). No browser, no WebView. The Android SDK and its NDK are not in the image: the user's (~/.jumphp/android-sdk,
# its license accepted by the user) or the GitHub runner's, mounted at /opt/android-sdk.
# libgl-dev, libegl-dev: the Khronos headers only (PHP-OpenGL compiles against them; SDL gives the functions).
FROM debian:bookworm-slim
ARG CMAKE=3.31.8
RUN apt-get update && apt-get install -y --no-install-recommends \
        openjdk-17-jdk-headless git curl ca-certificates unzip xz-utils make ninja-build autoconf automake libtool \
        bison re2c pkg-config python3 file patch imagemagick php-cli libgl-dev libegl-dev \
    && rm -rf /var/lib/apt/lists/* \
    && curl -fsSL -A jumphp-android "https://github.com/Kitware/CMake/releases/download/v$CMAKE/cmake-$CMAKE-linux-x86_64.tar.gz" \
       | tar -xz -C /opt && ln -sf /opt/cmake-$CMAKE-linux-x86_64/bin/* /usr/local/bin/ \
    && ln -s /usr/bin/ninja /opt/cmake-$CMAKE-linux-x86_64/bin/ninja \
    && ln -s "$(dirname "$(dirname "$(readlink -f "$(which javac)")")")" /opt/jdk \
    && cmake --version | head -1 && java -version 2>&1 | head -1
ENV JAVA_HOME=/opt/jdk ANDROID_HOME=/opt/android-sdk ANDROID_SDK_ROOT=/opt/android-sdk
