# Video maker: vertical "satisfying" videos (TikTok, Shorts, Reels)

A scene of a JumPHP game, rendered headless frame by frame (never in real time) at 60 FPS, each collision playing the
next note of a melody, the sound mixed offline from the notes it played, then an MP4 ready to post:

```bash
bin/jumphp install shorts-demo
bin/jumphp video:make shorts-demo --template rings --seconds 30 --seed 7 --count 3
# games/shorts-demo/dist/video/shorts-demo-rings-7.mp4 (+ -8, -9) and a .png cover each
```

- 1080 x 1920, 60 FPS, H.264 + AAC (ffmpeg in Docker, the image of `bin/jumphp video`), the view (270 x 480) scaled
  x4 without smoothing: crisp pixels.
- `--seed N`: the same seed makes the same video again (fixed-step physics, seeded); `--count 3`: seeds N, N+1, N+2.
- `--seconds`, `--template` (the scene's `template` param), `--title` (the question at the top), `--keep-frames`.

## Captions

Timed text over the video, in any template that draws them (`games/shorts-demo` does): a question, a reaction, "wait for it", a call to
action. `bin/jumphp video:make shorts-demo --caption "Wait for it@2-5@middle@pop" --caption "Follow for more@20-@bottom@box"`,
or `--captions captions.json`, or project.yaml `video: {captions: [...]}`:

```json
[{"text": "Wait for it", "from": 2, "to": 5, "at": "middle", "style": "pop", "scale": 3, "color": "#ffd23f"}]
```

- `TEXT@FROM-TO[@place][@style]`: seconds (`10-` = to the end), place `top` / `middle` / `bottom` or a y in pixels.
- styles: `plain` (shadow), `box` (a dark band that fades in and out), `pop` (grows in with a small overshoot), `type` (typed letter by letter).
- long texts are cut into lines that fit. In your own scene: `Captions::fromList($params['captions'])` (`JumPHP\Renderer\Captions`; the extension's `JumPHP\Shorts\Captions` is the same class), then
  `$captions->render($r, $this->font(), $this->time, $this->width(), $this->height())` last in `render()`. The engine cannot fade text
  (no alpha on textures): only the box fades.

## A film of any game (video:replay)

`bin/jumphp video:replay <game> [--input <json|file>] [--scene CLASS] [--params JSON] [--seconds S] [--scale N] [--name N]`: the game runs
headless, frame by frame at 60 FPS, with scripted input (`JUMPHP_INPUT`: `[[frame, "action", "hold"|"release"], ...]`), what it plays is
logged (`JUMPHP_AUDIO_LOG`: sound effects, generated sounds, music, with the game's own clock) and mixed offline, then ffmpeg:
`games/<game>/dist/video/<game>-replay.mp4` (1280 x 720 for a 320 x 180 game: the smallest side is brought to 720 at least,
`--scale` to choose) and a `.png` cover. The seconds default to the last input + 3.

Captions over any game: `--caption "TEXT@FROM-TO[@place][@style]"` (repeatable) or `--captions file.json`, the same as for video:make; the
engine draws them (JUMPHP_CAPTIONS, `JumPHP\Renderer\Captions`, sized for the game's view) on the game's own clock.

Film a real session: play with `JUMPHP_INPUT_RECORD=session.json bin/jumphp run <game>` (the actions held and let go are written as you
play, a tap shorter than one frame as a press), then `bin/jumphp video:replay <game> --input session.json`. The same file works for
`JUMPHP_INPUT=$(cat session.json) bin/jumphp shot <game>` and for a game test. The game must be deterministic for the film to
look like the session (a seeded random, the fixed step).

## Preview in the IDE

Tools > **Video Preview…** (the JumPHP IDE): the games of the project that have a `video:` block (project.yaml: `scene`, `params`,
`seconds`, and `templates: [growing, rings, race]` to list the templates). Choose the template, the seed, the seconds, the title and
the captions (one a line, `TEXT@FROM-TO@place@style`), **Preview** plays the scene live at the video's speed (the engine rewrites
`var/live/frame.png`, `JUMPHP_LIVE`; the preview is silent) and **Make the MP4** runs `bin/jumphp video:make` with the same choices.

## How it works

1. project.yaml `video: {scene, params, seconds, scale, title}` says which scene; video:make starts it with its params
   + `seed`, `seconds`, `template`, `title`, `JUMPHP_SHOT_EVERY=1` (one PNG a frame) and `JUMPHP_VIDEO_OUT`.
2. The scene plays its notes through `JumPHP\Shorts\Recorder::note($time, $freq)`: heard when played normally, written
   to `notes.json` while filmed.
3. `JumPHP\Shorts\Mixer` mixes the notes (plucked tones, soft limit) into a WAV of the video's length.
4. ffmpeg joins frames and sound; a cover is taken at 2 s.

## The extensions

- `extensions/physics` (`jumphp/physics`, `JumPHP\Physics2d\World`): balls inside rings and arcs (`spin`, `gap`,
  `grow`, `growOnHit`, `openOnHit`), pegs, walls, balls on balls; gravity, restitution, `hits()` / `on()` events
  (`ring`, `peg`, `wall`, `ball`, `escape`), fixed step (`hz`), seeded, 32-bit safe.
- `extensions/shorts` (`jumphp/shorts`): `Recorder`, `Melody` (`fromString('C4 E4 G4')`, `fromMidi($file)`), `Mixer`,
  `Draw` (`ring()` with a gap, `arc()`, `trail()`).
- `extensions/particles`: the sparks of each bounce.

## The templates of games/shorts-demo

| template | the video |
|---|---|
| `growing` | "How big can it get?": a ball grows at each bounce inside a circle |
| `rings` | "Will it escape?": seven turning rings with gaps, each one breaks when the ball gets out |
| `race` | "Which colour wins?": four balls fall through pegs, the first one down wins, round after round |

Make your own: a scene that builds a `World`, calls `$recorder->note()` on its hits, draws a question at the top and a
timer; point project.yaml `video.scene` at it. The melody: a public-domain tune or your own notes (`melody` param).
