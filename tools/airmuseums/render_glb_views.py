"""Blender GTX renderer for privately downloaded Aviation Gazetteer GLBs.

Run only on the owner's GTX PC, through run-view-render.cmd. The five PNGs are
reference views; they are not cleared for redistribution or game publication.
"""

import argparse
import json
import math
from pathlib import Path
import sys

import bpy
from mathutils import Vector


def arguments():
    parser = argparse.ArgumentParser()
    parser.add_argument('--glb', required=True)
    parser.add_argument('--out', required=True)
    parser.add_argument('--slug', required=True)
    return parser.parse_args(sys.argv[sys.argv.index('--') + 1:])


def bounds(objects):
    corners = [obj.matrix_world @ Vector(corner) for obj in objects if obj.type == 'MESH' for corner in obj.bound_box]
    if not corners:
        raise RuntimeError('GLB contains no renderable mesh')
    minimum = Vector(tuple(min(point[i] for point in corners) for i in range(3)))
    maximum = Vector(tuple(max(point[i] for point in corners) for i in range(3)))
    return minimum, maximum


def camera_at(scene, location, target, ortho, top_rotation=None):
    camera = scene.camera
    camera.location = location
    if top_rotation is None:
        camera.rotation_euler = (Vector(target) - camera.location).to_track_quat('-Z', 'Y').to_euler()
    else:
        camera.rotation_euler = (0, 0, top_rotation)
    camera.data.ortho_scale = ortho


def main():
    args = arguments()
    output = Path(args.out)
    output.mkdir(parents=True, exist_ok=True)
    bpy.ops.wm.read_factory_settings(use_empty=True)
    bpy.ops.import_scene.gltf(filepath=args.glb)
    objects = list(bpy.context.scene.objects)
    low, high = bounds(objects)
    center = (low + high) / 2
    size = high - low

    # Parent only imported top-level objects; their mesh geometry and materials stay intact.
    root = bpy.data.objects.new('bank-control', None)
    bpy.context.scene.collection.objects.link(root)
    for obj in objects:
        if obj.parent is None:
            world = obj.matrix_world.copy()
            obj.parent = root
            obj.matrix_world = world

    scene = bpy.context.scene
    scene.render.engine = 'CYCLES'
    scene.cycles.samples = 20
    scene.render.resolution_x = 512
    scene.render.resolution_y = 512
    scene.render.resolution_percentage = 100
    scene.render.film_transparent = True
    scene.render.image_settings.file_format = 'PNG'
    scene.render.image_settings.color_mode = 'RGBA'
    scene.view_settings.view_transform = 'AgX'
    scene.world = bpy.data.worlds.new('neutral-world')
    scene.world.use_nodes = True
    background = scene.world.node_tree.nodes.get('Background')
    background.inputs['Color'].default_value = (0.8, 0.83, 0.88, 1)
    background.inputs['Strength'].default_value = 0.8
    device_name = 'CPU on GTX PC'
    try:
        prefs = bpy.context.preferences.addons['cycles'].preferences
        prefs.get_devices()
        prefs.compute_device_type = 'CUDA'
        for device in prefs.devices:
            device.use = device.type == 'CUDA'
        if any(device.use and device.type == 'CUDA' for device in prefs.devices):
            scene.cycles.device = 'GPU'
            device_name = 'CUDA ' + ', '.join(device.name for device in prefs.devices if device.use)
    except Exception as error:
        print('CUDA unavailable:', error)

    camera_data = bpy.data.cameras.new('orthographic')
    camera_data.type = 'ORTHO'
    camera = bpy.data.objects.new('orthographic', camera_data)
    scene.collection.objects.link(camera)
    scene.camera = camera
    for name, location, strength, size_light in (
        ('key', (center.x - size.x, center.y - size.y, high.z + size.x), 1400, max(size.x, 4)),
        ('fill', (center.x + size.x, center.y, high.z + size.x), 700, max(size.x, 4)),
    ):
        light_data = bpy.data.lights.new(name, 'AREA')
        light_data.energy = strength
        light_data.shape = 'DISK'
        light_data.size = size_light
        light = bpy.data.objects.new(name, light_data)
        scene.collection.objects.link(light)
        light.location = location
        light.rotation_euler = (center - light.location).to_track_quat('-Z', 'Y').to_euler()

    spinner = next((obj for obj in objects if 'spinner' in obj.name.lower()), None)
    nose_y = spinner.matrix_world.translation.y if spinner else low.y
    # The Hangar GLBs put the nose on their longitudinal Y axis after glTF import.
    top_angle = math.pi if nose_y < center.y else 0
    height = max(size.x, size.y, 1) * 1.25
    side_height = max(size.y, size.z, 1) * 1.25
    distance = max(size.x, size.y, size.z, 1) * 2
    views = (
        ('top-bank-left', -math.radians(17), (center.x, center.y, high.z + distance), height, top_angle),
        ('top', 0, (center.x, center.y, high.z + distance), height, top_angle),
        ('top-bank-right', math.radians(17), (center.x, center.y, high.z + distance), height, top_angle),
        ('left', 0, (low.x - distance, center.y, center.z), side_height, None),
        ('right', 0, (high.x + distance, center.y, center.z), side_height, None),
    )
    for name, bank, location, scale, rotation in views:
        root.rotation_euler.y = bank
        bpy.context.view_layer.update()
        camera_at(scene, location, center, scale, rotation)
        scene.render.filepath = str(output / f'{args.slug}-{name}.png')
        bpy.ops.render.render(write_still=True)
        print('RENDERED', scene.render.filepath)
    (output / f'{args.slug}-render.json').write_text(json.dumps({
        'slug': args.slug,
        'blender': bpy.app.version_string,
        'device': device_name,
        'input': Path(args.glb).name,
        'bounds': {'min': list(low), 'max': list(high)},
        'views': [name for name, *_ in views],
    }, indent=2), encoding='utf-8')


if __name__ == '__main__':
    main()
