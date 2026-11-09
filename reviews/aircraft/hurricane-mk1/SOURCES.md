# Hurricane Mk I review sources

## Silhouette source

- **MLWatts**, [*Hawker Hurricane 3-view.svg*](https://commons.wikimedia.org/wiki/File:Hawker_Hurricane_3-view.svg), uploaded 19 January 2012. The file page identifies the aircraft as a **Hawker Hurricane Mk.I**, credits MLWatts and marks the SVG **CC0 1.0**. The uploader describes their work as derived from an Aviastar drawing; the precise provenance and rights of that underlying drawing are not independently established here. The source SVG is preserved in this folder for reproducible contour extraction, with attribution despite CC0.
- The SVG top view's `path3089` (right wing) and `path3095` (right tailplane) were sampled into `outline.json`; the mirrored counterparts in the SVG confirm the centerline at x = 1040.834 px. The 20 fuselage section widths were manually calibrated to the top-view outer lines. The source plan is line art, so surface heights, paint and small details remain interpretations.
- Francis Crosby, *The Complete Guide to Fighters & Bombers of the World* (2008), private Aiphonse copy, PDF page 93 / printed page 91: Hurricane Mk I, span 12.19 m and length 9.57 m. No page image or extracted book text is distributed.
- [Royal Canadian Air Force Hurricane page](https://www.canada.ca/en/air-force/services/aircraft/hawker-hurricane.html): independent public check of Hurricane identity and dimensions.

## Production and limits

`tools/blender/trace_hurricane_plan.py` reads the preserved CC0 SVG and writes the source coordinates. The two local image/trace scripts use the packages listed in `tools/blender/review-requirements.txt`. `tools/blender/run_review_hurricane_on_gtx.sh` sends only `outline.json` and Blender scripts to the PC GTX. Blender 5.2.3 renders a 1024 px orthographic top view and nine 512 px game frames there using the GTX 1660 SUPER (CUDA), as recorded in `render-info.json`. `tools/blender/make_hurricane_review.py` creates the four JPG review boards, while `tools/blender/assemble_review_atlases.py` packs the game frames. The JPGs are visual proof, not transparent game sprites.

The silhouette follows a community drawing labelled Mk I rather than original manufacturer engineering blueprints. It is a game asset, not an engineering-grade reconstruction.
