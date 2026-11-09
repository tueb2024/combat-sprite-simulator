# shellcheck shell=bash
# bin/jumphp video:make: vertical videos (TikTok, Shorts) of a scene, rendered headless frame by frame at 60 FPS, the
# sound mixed offline from the notes it played (jumphp/shorts Recorder -> notes.json -> Mixer), then ffmpeg in Docker:
# games/<game>/dist/video/<id>-<template>-<seed>.mp4 (H.264 + AAC) + .png (the cover). docs/video-maker.md.

cmd_video_make() {
  local game="${1:-}"; local dir; dir="$(project_dir "$game")"; shift || true
  local seed="" count=1 seconds="" template="" title="" keep=0 captions=() captions_file="" lang=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --seed) seed="$2"; shift ;;
      --count) count="$2"; shift ;;
      --seconds) seconds="$2"; shift ;;
      --template) template="$2"; shift ;;
      --title) title="$2"; shift ;;
      --lang) lang="$2"; shift ;;
      --caption) captions+=("$2"); shift ;;
      --captions) captions_file="$2"; shift ;;
      --keep-frames) keep=1 ;;
      *) die "unknown option $1 (bin/jumphp video:make <game> [--seed N] [--count N] [--seconds S] [--template T] [--title T] [--lang fr|en] [--caption \"TEXT@FROM-TO[@place[@style]]\"]... [--captions file.json])" ;;
    esac
    shift
  done
  [[ "$count" =~ ^[0-9]+$ ]] && [ "$count" -ge 1 ] || die "--count: a number"
  [ -z "$seed" ] && seed=$(( (RANDOM % 9000) + 1 ))
  [[ "$seed" =~ ^[0-9]+$ ]] || die "--seed: a number"
  ensure_vendor "$dir"
  docker image inspect jumphp/ffmpeg:dev >/dev/null 2>&1 || docker build -q -t jumphp/ffmpeg:dev "$ROOT/docker/ffmpeg" >/dev/null
  mkdir -p "$ROOT/$dir/dist/video"
  local i
  for ((i = 0; i < count; i++)); do
    video_make_one "$dir" "$((seed + i))" "$seconds" "$template" "$title" "$keep" "$captions_file" "$lang" "${captions[@]+"${captions[@]}"}" || return 1
  done
}

# one video (view width x height x project.yaml video.scale: 270x480 x 4 = 1080x1920): $1 game folder, $2 seed, $3 seconds ("" = project.yaml), $4 template, $5 title, $6 keep the frames, $7 captions file (JSON list, "" = none), $8... --caption specs
video_make_one() {
  local dir="$1" seed="$2" seconds="$3" template="$4" title="$5" keep="$6" cfile="$7" lang="$8" plan; shift 8
  # project.yaml video: {scene, params, seconds, scale, title} + the options -> scene \t params \t seconds \t scale \t name
  plan="$(php_run "$dir" -- php -r '
    require "vendor/autoload.php";
    $c = JumPHP\Kernel\GameConfig::load(".");
    $v = (array)$c->get("video", []);
    $scene = (string)($v["scene"] ?? "") ?: (string)$c->get("start_scene");
    $params = (array)($v["params"] ?? []);
    $params["seed"] = (int)$argv[1];
    if ($argv[3] !== "") { $params["template"] = $argv[3]; }
    $title = $argv[4] !== "" ? $argv[4] : (string)($v["title"] ?? "");
    if ($title !== "") { $params["title"] = $title; }
    // captions: project.yaml video.captions, then the file, then --caption specs
    $captions = (array)($v["captions"] ?? []);
    if ($argv[5] !== "") { $file = json_decode((string)@file_get_contents($argv[5]), true); if (!is_array($file)) { fwrite(STDERR, "cannot read captions from $argv[5]\n"); exit(1); } array_push($captions, ...array_values($file)); }
    foreach (array_slice($argv, 7) as $spec) { $captions[] = JumPHP\Shorts\Captions::parseSpec($spec); }
    if ($captions) { $params["captions"] = $captions; }
    $seconds = $argv[2] !== "" ? (float)$argv[2] : (float)($v["seconds"] ?? 30);
    $params["seconds"] = $seconds;
    if ($argv[6] !== "") { $params["lang"] = $argv[6]; }
    $name = $c->get("id") . (isset($params["template"]) ? "-" . $params["template"] : "") . "-" . $params["seed"] . ($argv[6] !== "" ? "-" . $argv[6] : "");
    $size = (int)$c->get("width") * (int)($v["scale"] ?? 4) . "x" . (int)$c->get("height") * (int)($v["scale"] ?? 4);
    echo $scene, "\t", json_encode($params), "\t", $seconds, "\t", $size, "\t", preg_replace("/[^a-z0-9-]/", "-", strtolower($name)), "\t", (int)($v["scale"] ?? 4);
  ' "$seed" "$seconds" "$template" "$title" "$cfile" "$lang" "$@")" || die "cannot read the video settings of $dir (project.yaml video:)"
  local scene params secs size name vscale
  IFS=$'\t' read -r scene params secs size name vscale <<<"$plan"
  [ -n "$scene" ] || die "no scene to film: project.yaml video: {scene: App\\Scene\\...}"
  local frames run="var/video/$name"
  frames="$(awk -v s="$secs" 'BEGIN { printf "%d", s * 60 + 0.5 }')"
  rm -rf "${ROOT:?}/$dir/$run" && mkdir -p "$ROOT/$dir/$run/frames" "$ROOT/$dir/$run/tmp"
  say "$name: $frames frames of $scene ($secs s at 60 FPS)"
  php_run "$dir" --ulimit nofile=65536:65536 -e "TMPDIR=/workspace/$dir/$run/tmp" -e "JUMPHP_SCREENSHOT=$run/frames/f.png" -e JUMPHP_SHOT_EVERY=1 -e "JUMPHP_MAX_FRAMES=$frames" \
    -e "JUMPHP_HEADLESS_SCALE=${vscale:-4}" -e "JUMPHP_SCENE=$scene" -e "JUMPHP_SCENE_PARAMS=$params" -e "JUMPHP_VIDEO_OUT=$run" -e JUMPHP_GL=0 -- php app.php \
    || die "$name: the scene stopped (see above)"
  rm -f "$ROOT/$dir/$run/frames/f.png"
  # a frame that could not be written (disk full...) would silently shorten the film: count them
  local made; made="$(find "$ROOT/$dir/$run/frames" -name 'f-*.png' | wc -l)"
  [ "$made" -ge "$frames" ] || die "$name: only $made of $frames pictures were written (disk full?)"
  say "$name: the sound (the notes it played, mixed)"
  php_run "$dir" -- php -r 'require "vendor/autoload.php"; JumPHP\Shorts\Mixer::mixFile($argv[1], (float)$argv[2], $argv[3]);' \
    "$run/notes.json" "$secs" "$run/audio.wav" || die "$name: the sound could not be mixed (does the game require jumphp/shorts?)"
  say "$name: the MP4 (H.264 + AAC, $size)"
  local out="dist/video/$name"
  docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT/$dir:/g" -w /g jumphp/ffmpeg:dev -hide_banner -loglevel error -y \
    -framerate 60 -start_number 1 -i "$run/frames/f-%05d.png" -i "$run/audio.wav" \
    -vf "scale=${size/x/:}:flags=neighbor,format=yuv420p" -c:v libx264 -preset medium -crf 18 -r 60 \
    -c:a aac -b:a 160k -shortest -movflags +faststart "$out.mp4" || die "$name: ffmpeg failed"
  docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT/$dir:/g" -w /g jumphp/ffmpeg:dev -hide_banner -loglevel error -y \
    -ss 2 -i "$out.mp4" -frames:v 1 "$out.png" || warn "$name: no cover"
  [ "$keep" = 1 ] || rm -rf "${ROOT:?}/$dir/$run/frames"
  say "done: $dir/$out.mp4 (+ .png, the cover)"
}

# bin/jumphp video:replay <game> [--input <json | file>] [--scene CLASS] [--params JSON] [--seconds S] [--scale N] [--name N] [--keep-frames]
# A film of ANY game: it runs headless frame by frame at 60 FPS with the scripted input (JUMPHP_INPUT: a JSON list or a file, e.g. a
# session recorded with JUMPHP_INPUT_RECORD=<file> bin/jumphp run <game>), what it plays is logged (JUMPHP_AUDIO_LOG: sound effects, generated
# sounds, music) and mixed offline, then ffmpeg in Docker: games/<game>/dist/video/<game>-replay[-name].mp4 + a .png cover.
# The size is the game's view x --scale (default: the smallest side at least 720 pixels). docs/video-maker.md.
cmd_video_replay() {
  local game="${1:-}"; local dir; dir="$(project_dir "$game")"; shift || true
  local input="" scene="" params="" seconds="" scale="" name="" keep=0 captions=() captions_file=""
  while [ $# -gt 0 ]; do
    case "$1" in
      --caption) captions+=("$2"); shift ;;
      --captions) captions_file="$2"; shift ;;
      --input) input="$2"; shift ;;
      --scene) scene="$2"; shift ;;
      --params) params="$2"; shift ;;
      --seconds) seconds="$2"; shift ;;
      --scale) scale="$2"; shift ;;
      --name) name="$2"; shift ;;
      --keep-frames) keep=1 ;;
      *) die "unknown option $1 (bin/jumphp video:replay <game> [--input <json|file>] [--scene CLASS] [--params JSON] [--seconds S] [--scale N] [--name N] [--caption \"TEXT@FROM-TO[@place[@style]]\"]... [--captions file.json] [--keep-frames])" ;;
    esac
    shift
  done
  # the input: a JSON list as it is, or a file (as given, else in the game's folder)
  if [ -n "$input" ] && [ "${input:0:1}" != "[" ]; then
    if [ -f "$input" ]; then input="$(cat "$input")"
    elif [ -f "$ROOT/$dir/$input" ]; then input="$(cat "$ROOT/$dir/$input")"
    else die "--input: neither a JSON list nor a file ($input)"; fi
  fi
  [ -n "$input" ] || input="[]"
  [[ "$scale" =~ ^[0-9]*$ ]] || die "--scale: a number"
  ensure_vendor "$dir"
  docker image inspect jumphp/ffmpeg:dev >/dev/null 2>&1 || docker build -q -t jumphp/ffmpeg:dev "$ROOT/docker/ffmpeg" >/dev/null
  mkdir -p "$ROOT/$dir/dist/video"
  # seconds (default: the last input + 3 s, at least 5) and the size: seconds \t size \t name
  local plan secs size out_name
  plan="$(php_run "$dir" -- php -r '
    require "vendor/autoload.php";
    $c = JumPHP\Kernel\GameConfig::load(".");
    $in = json_decode($argv[1], true) ?: [];
    $last = $in ? max(array_map(fn ($s) => (int)$s[0], $in)) : 0;
    $secs = $argv[2] !== "" ? (float)$argv[2] : max(5.0, $last / 60 + 3);
    $w = (int)$c->get("width"); $h = (int)$c->get("height");
    $scale = $argv[3] !== "" ? max(1, (int)$argv[3]) : (int)ceil(720 / max(1, min($w, $h)));
    $name = $c->get("id") . "-replay" . ($argv[4] !== "" ? "-" . $argv[4] : "");
    echo $secs, "\t", $w * $scale, "x", $h * $scale, "\t", preg_replace("/[^a-z0-9-]/", "-", strtolower($name));
  ' "$input" "$seconds" "$scale" "$name")" || die "cannot read the settings of $dir"
  IFS=$'\t' read -r secs size out_name <<<"$plan"
  # captions over the whole game (JUMPHP_CAPTIONS, drawn by the engine): the file's, then the --caption specs
  local captions_json=""
  if [ -n "$captions_file" ] || [ ${#captions[@]} -gt 0 ]; then
    captions_json="$(php_run "$dir" -- php -r '
      require "vendor/autoload.php";
      $list = [];
      if ($argv[1] !== "") { $f = json_decode((string)@file_get_contents($argv[1]), true); if (!is_array($f)) { fwrite(STDERR, "cannot read captions from $argv[1]\n"); exit(1); } $list = array_values($f); }
      foreach (array_slice($argv, 2) as $spec) { $list[] = JumPHP\Renderer\Captions::parseSpec($spec); }
      echo json_encode($list);
    ' "$captions_file" ${captions[@]+"${captions[@]}"})" || die "the captions could not be read (needs JumPHP 0.5.9)"
  fi
  local frames run="var/video/$out_name"
  frames="$(awk -v s="$secs" 'BEGIN { printf "%d", s * 60 + 0.5 }')"
  rm -rf "${ROOT:?}/$dir/$run" && mkdir -p "$ROOT/$dir/$run/frames"
  say "$out_name: $frames frames ($secs s at 60 FPS)"
  local env_scene=() env_params=()
  [ -n "$scene" ] && env_scene=(-e "JUMPHP_SCENE=$scene")
  [ -n "$params" ] && env_params=(-e "JUMPHP_SCENE_PARAMS=$params")
  php_run "$dir" --ulimit nofile=65536:65536 -e "JUMPHP_SCREENSHOT=$run/frames/f.png" -e JUMPHP_SHOT_EVERY=1 -e "JUMPHP_MAX_FRAMES=$frames" \
    -e "JUMPHP_INPUT=$input" -e "JUMPHP_AUDIO_LOG=$run/audio" ${captions_json:+-e "JUMPHP_CAPTIONS=$captions_json"} ${env_scene[@]+"${env_scene[@]}"} ${env_params[@]+"${env_params[@]}"} -- php app.php \
    || die "$out_name: the game stopped (see above)"
  rm -f "$ROOT/$dir/$run/frames/f.png"
  say "$out_name: the sound (what the game played, mixed)"
  php_run "$dir" -- php /workspace/tools/video-audio.php "$run" "$secs" || die "$out_name: the sound could not be prepared"
  local audio_args=()
  mapfile -t audio_args < "$ROOT/$dir/$run/audio.args"
  say "$out_name: the MP4 (H.264 + AAC, $size)"
  local out="dist/video/$out_name"
  docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT:/w" -w "/w/$dir" jumphp/ffmpeg:dev -hide_banner -loglevel error -y \
    -framerate 60 -start_number 1 -i "$run/frames/f-%05d.png" "${audio_args[@]}" \
    -filter_complex_script "$run/audio.filter" \
    -vf "scale=${size/x/:}:flags=neighbor,scale=trunc(iw/2)*2:trunc(ih/2)*2,format=yuv420p" -map 0:v -map "[out]" \
    -c:v libx264 -preset medium -crf 18 -r 60 -c:a aac -b:a 160k -t "$secs" -movflags +faststart "$out.mp4" || die "$out_name: ffmpeg failed"
  docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT:/w" -w "/w/$dir" jumphp/ffmpeg:dev -hide_banner -loglevel error -y \
    -ss 2 -i "$out.mp4" -frames:v 1 "$out.png" || warn "$out_name: no cover"
  [ "$keep" = 1 ] || rm -rf "${ROOT:?}/$dir/$run/frames"
  say "done: $dir/$out.mp4 (+ .png, the cover)"
}
