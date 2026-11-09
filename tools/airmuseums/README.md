# Aviation Gazetteer reference collector

The supplied URL `https://airmuseums.co.uk/models/aircraft/` is an asset path and returns 404 when opened as a directory. The current public catalogue is [`/aircraft`](https://airmuseums.co.uk/aircraft); each Hangar plate has `/hangar/<slug>`, a downloadable `/models/aircraft/<slug>.glb`, and a side profile `<slug>-profile.webp`.

The Python script reads `robots.txt`, uses one request per second by default, checks that every asset URL stays on the same host, verifies the GLB/WebP headers, stores SHA-256 hashes, and resumes completed downloads. It writes only under the ignored `artifacts/airmuseums/` folder unless `--out` changes it. No PHP, Node or host Blender is needed.

```bash
# Inventory the live Hangar catalogue; its size can change while a batch runs.
python3 tools/airmuseums/scrape_views.py list --era all --roles all --limit 0 --catalog-json

# A small WWII sample. The site's Stuka is G-2, whereas our game uses B-2.
python3 tools/airmuseums/scrape_views.py fetch --slugs hawker-hurricane,messerschmitt-bf-109,junkers-ju-87-stuka --limit 0
python3 tools/airmuseums/scrape_views.py render --slugs hawker-hurricane,messerschmitt-bf-109,junkers-ju-87-stuka --limit 0

# Process the full catalogue, with failures recorded for later retry.
python3 tools/airmuseums/scrape_views.py fetch --era all --roles all --limit 0 --continue-on-error
python3 tools/airmuseums/scrape_views.py render --era all --roles all --limit 0 --continue-on-error
python3 tools/airmuseums/validate_outputs.py --strict

# Optional private 3-frame atlases for future shmup integration (2D packing).
python3 -m venv /tmp/combat-airmuseums-venv
/tmp/combat-airmuseums-venv/bin/pip install -r tools/airmuseums/requirements.txt
/tmp/combat-airmuseums-venv/bin/python tools/airmuseums/pack_shmup_atlases.py
```

`list` classifies the site's `fighter` role as `player_candidate` and every other role as `enemy_candidate`. This is only a roster proposal. The manifest keeps the exact Hangar page and modelled variant so that a later game integration can exclude unsuitable variants or aircraft. `render` uploads the downloaded GLB to the owner's GTX PC over the existing SSH connection, runs Blender 5.2.3 there, and retrieves five transparent 512 px reference PNGs: straight top, 17° left and right bank, and left/right side. It skips completed renders unless `--force` is given. `validate_outputs.py --strict` checks all SHA-256 hashes, RGBA PNG dimensions and GPU metadata. Renders remain private under `artifacts/airmuseums/<slug>/`; `pack_shmup_atlases.py` creates three-frame transparent atlases and a private roster from them.

On 9 November 2026 the live catalogue had 387 entries (105 fighter candidates, 282 others). All 387 GLB/profile pairs, 1,935 GTX views, and 387 private atlases were produced; `validate_outputs.py --strict` reported no missing or invalid files. If the live catalogue grows, rerun `fetch` and `render` to pick up new entries. Existing views are reused.

The Hangar visibly offers GLB downloads, but the inspected pages do not state a general licence allowing redistribution or incorporation of its models or derived renders into a published game. The user reports permission for a test, and explicitly requested one Bf 109 contact sheet on GitHub: [`preview.jpg`](../../artifacts/airmuseums/messerschmitt-bf-109/preview.jpg). The remaining GLBs, renders and atlases stay out of Git. Confirm the scope of reuse rights before shipping site-derived sprites in a game. Our existing source-traced aircraft atlases remain the published game assets.
