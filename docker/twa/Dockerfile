# The Android app of a game (a Trusted Web Activity: the game's web page in Chrome, full screen, as a Play Store
# app), built by Google's Bubblewrap: bin/jumphp twa <game>, tools/twa.sh. Node + JDK 17 + Bubblewrap; the
# Android SDK is not in the image: its license is accepted by the user on the first build, then it is kept in
# ~/.jumphp/android-sdk (as Gradle's cache in ~/.jumphp/gradle).
FROM node:22.20.0-bookworm-slim
ARG BUBBLEWRAP=1.27.0
RUN apt-get update && apt-get install -y --no-install-recommends openjdk-17-jdk-headless unzip curl ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && npm install -g --no-fund --no-audit "@bubblewrap/cli@$BUBBLEWRAP" "@bubblewrap/core@$BUBBLEWRAP" \
    && ln -s "$(dirname "$(dirname "$(readlink -f "$(which javac)")")")" /opt/jdk \
    && java -version 2>&1 | head -1 && npm ls -g --depth=0 | grep bubblewrap
ENV JAVA_HOME=/opt/jdk ANDROID_HOME=/opt/android-sdk NODE_PATH=/usr/local/lib/node_modules
