"""Render documented WWII aircraft on the GTX workstation with Blender 5.x.

blender.exe -b --python build_ww2.py -- --spec-dir DIR --out DIR --aircraft all
"""
import argparse
import json
import math
from pathlib import Path
import sys
import time

import bpy
from mathutils import Vector

sys.path.insert(0, str(Path(__file__).resolve().parent))
from airframe_geometry import make_aircraft


def arguments():
    parser = argparse.ArgumentParser()
    parser.add_argument('--spec-dir', required=True)
    parser.add_argument('--out', required=True)
    parser.add_argument('--aircraft', default='all')
    return parser.parse_args(sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else [])


def gpu_device():
    try:
        preferences = bpy.context.preferences.addons['cycles'].preferences
        preferences.compute_device_type = 'CUDA'
        preferences.get_devices()
        devices = [device for device in preferences.devices
                   if device.type == 'CUDA' and 'NVIDIA' in device.name.upper()]
        for device in preferences.devices:
            device.use = device in devices
        if devices:
            return 'CUDA ' + ', '.join(device.name for device in devices)
    except Exception as error:
        print('CUDA detection failed; using CPU:', error)
    return 'CPU'


def configure_scene(device):
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete(use_global=False)
    scene = bpy.context.scene
    scene.render.engine = 'CYCLES'
    scene.cycles.samples = 20
    scene.cycles.seed = 17
    scene.cycles.use_denoising = True
    scene.cycles.device = 'GPU' if device.startswith('CUDA') else 'CPU'
    scene.render.film_transparent = True
    scene.render.image_settings.file_format = 'PNG'
    scene.render.image_settings.color_mode = 'RGBA'
    scene.render.image_settings.color_depth = '8'
    scene.render.image_settings.compression = 15
    scene.render.resolution_x = 512
    scene.render.resolution_y = 512
    scene.render.resolution_percentage = 100
    scene.view_settings.view_transform = 'Standard'
    world = bpy.data.worlds.new('soft daylight')
    scene.world = world
    world.use_nodes = True
    world.node_tree.nodes['Background'].inputs['Color'].default_value = (0.63, 0.70, 0.74, 1)
    world.node_tree.nodes['Background'].inputs['Strength'].default_value = 1.1
    bpy.ops.object.camera_add(location=(0, 0, 16))
    camera = bpy.context.object
    camera.name = 'common orthographic sprite camera'
    camera.data.type = 'ORTHO'
    camera.data.ortho_scale = 7.8
    camera.rotation_euler = (Vector((0, 0, 0)) - camera.location).to_track_quat('-Z', 'Y').to_euler()
    scene.camera = camera
    bpy.ops.object.light_add(type='AREA', location=(-4, 5, 9))
    light = bpy.context.object
    light.name = 'common soft key light'
    light.data.energy = 650
    light.data.shape = 'DISK'
    light.data.size = 7
    return scene


def render_aircraft(spec, out, device):
    scene = configure_scene(device)
    root, propeller_phases = make_aircraft(spec)
    bank_values = spec['gameplay']['bank_degrees']
    prop_values = spec['gameplay']['propeller_phases']
    orientation = math.pi if spec['role'] == 'enemy' else 0
    frames = []
    start = time.monotonic()
    for bank_index, bank in enumerate(bank_values):
        root.rotation_euler = (0, math.radians(bank), orientation)
        for prop_index, phase_angle in enumerate(prop_values):
            for phase, group in enumerate(propeller_phases):
                hidden = phase != prop_index
                group.hide_render = hidden
                group.rotation_euler[2] = math.radians(phase_angle)
                for blade in group.children:
                    blade.hide_render = hidden
            name = f"{spec['id']}-b{bank_index}-p{prop_index}.png"
            scene.render.filepath = str(out / name)
            try:
                bpy.ops.render.render(write_still=True)
            except RuntimeError as error:
                if scene.cycles.device != 'GPU':
                    raise
                print('GPU render failed; retrying on GTX CPU:', error)
                scene.cycles.device = 'CPU'
                bpy.ops.render.render(write_still=True)
                device = 'CPU fallback'
            frames.append(name)
    root.rotation_euler = (0, 0, orientation)
    for phase, group in enumerate(propeller_phases):
        group.hide_render = phase != 0
        for blade in group.children:
            blade.hide_render = phase != 0
    bpy.ops.wm.save_as_mainfile(filepath=str(out / (spec['id'] + '.blend')))
    return {'id': spec['id'], 'device': device, 'blender_version': bpy.app.version_string,
            'render_seconds': round(time.monotonic() - start, 2),
            'tile': [512, 512], 'frames': frames,
            'gameplay_size': [spec['gameplay']['sprite_width'], spec['gameplay']['sprite_height']]}


def main():
    args = arguments()
    specs_dir = Path(args.spec_dir)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    paths = sorted(specs_dir.glob('*.json'))
    specs = [json.loads(path.read_text(encoding='utf-8')) for path in paths]
    if args.aircraft != 'all':
        specs = [spec for spec in specs if spec['id'] == args.aircraft]
    if not specs:
        raise ValueError(f'No aircraft specification for {args.aircraft}')
    device = gpu_device()
    results = [render_aircraft(spec, out, device) for spec in specs]
    (out / 'ww2-render-log.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
    print('WW2 RENDER COMPLETE:', [(result['id'], result['device'], len(result['frames'])) for result in results])


if __name__ == '__main__':
    main()
