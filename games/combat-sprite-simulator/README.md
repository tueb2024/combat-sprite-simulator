# Combat Sprite Simulator

Fly a Hurricane in a short vertical arcade mission. Four wing-gun streams fire at once. Collect the green **SPEED** or orange **RAPID** pickup for an eight-second boost. Survive the fast Bf 109s and slow Ju 87s, then defeat a fictional armed Junkers G 38. The real G 38 was a transport.

Arrows or WASD: move. Space: fire. Enter: start or restart. From the repository root, run `bin/jumphp test combat-sprite-simulator`, then `bin/jumphp build combat-sprite-simulator` and `bin/jumphp serve combat-sprite-simulator 8181`.

The four aircraft are parametric Blender meshes rendered on the GTX PC and packed into the active JumPHP sprite atlases. Source plans, attribution, comparison JPGs and Blender files live in `reviews/aircraft/`. Bf 109 and Ju 87 plan derivatives and their sprites retain the CC BY-SA terms noted there. Regenerate with `tools/blender/run_review_hurricane_on_gtx.sh`, `tools/blender/run_review_fleet_on_gtx.sh`, then `tools/blender/assemble_review_atlases.py`; the two review-board scripts create the JPGs. Blender is never run on Aiphonse.

Local JumPHP captures: [wave with both enemy types](../../reviews/gameplay/ww2-fleet.png), [four gun streams and speed boost](../../reviews/gameplay/ww2-speed.png), [G 38 boss](../../reviews/gameplay/ww2-boss.png).
