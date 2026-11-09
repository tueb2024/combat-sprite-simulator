# bin/jumphp video: a film -> numbered pictures + its sound (JumPHP\Gui\Video plays pictures: SDL's binding decodes no video)
FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*
ENTRYPOINT ["ffmpeg"]
