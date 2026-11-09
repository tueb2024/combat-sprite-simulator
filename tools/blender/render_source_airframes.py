"""Render source-calibrated Bf 109 E, Ju 87 B-2 and G 38 on the GTX PC.

All perimeter points come from the preserved, licensed three-view drawings.
The source image is never used as a texture. The meshes have airfoil volume,
shaped fuselage sections and independently modeled details.
"""

import argparse
import json
import math
from pathlib import Path
import sys

import bpy
from mathutils import Vector
from mathutils.geometry import tessellate_polygon


def args():
    parser = argparse.ArgumentParser()
    parser.add_argument('--spec-dir', required=True)
    parser.add_argument('--out', required=True)
    return parser.parse_args(sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else [])


def mat(name, rgb, camouflage=None):
    material = bpy.data.materials.new(name)
    material.diffuse_color = (*rgb, 1)
    material.use_nodes = True
    shader = material.node_tree.nodes.get('Principled BSDF')
    shader.inputs['Base Color'].default_value = (*rgb, 1)
    shader.inputs['Roughness'].default_value = 0.78
    if camouflage:
        noise = material.node_tree.nodes.new('ShaderNodeTexNoise')
        noise.inputs['Scale'].default_value = 3.0
        noise.inputs['Detail'].default_value = 1.0
        ramp = material.node_tree.nodes.new('ShaderNodeValToRGB')
        ramp.color_ramp.interpolation = 'CONSTANT'
        ramp.color_ramp.elements[0].position = 0.48
        ramp.color_ramp.elements[0].color = (*rgb, 1)
        ramp.color_ramp.elements[1].position = 0.49
        ramp.color_ramp.elements[1].color = (*camouflage, 1)
        material.node_tree.links.new(noise.outputs['Fac'], ramp.inputs['Fac'])
        material.node_tree.links.new(ramp.outputs['Color'], shader.inputs['Base Color'])
    return material


def mesh(name, vertices, faces, materials, ids=None):
    data = bpy.data.meshes.new(name)
    data.from_pydata(vertices, [], faces)
    data.update()
    obj = bpy.data.objects.new(name, data)
    bpy.context.collection.objects.link(obj)
    for material in materials:
        data.materials.append(material)
    if ids:
        for polygon, index in zip(data.polygons, ids):
            polygon.material_index = index
    return obj


def converter(spec):
    center = spec['centerline_px']
    nose, tail = spec['nose_px'], spec['tail_px']
    span = spec['dimensions_m']['span']
    length = spec['dimensions_m']['length']
    scale_x = span / (spec['span_bounds_px'][1] - spec['span_bounds_px'][0])
    scale_y = length / abs(tail - nose)
    if spec['source_orientation'] == 'nose_left':
        def point(pos, z=0):
            x, y = pos
            return ((center - y) * scale_x, ((nose + tail) / 2 - x) * scale_y, z)
        def mirrored(pos):
            return [pos[0], 2 * center - pos[1]]
    else:
        def point(pos, z=0):
            x, y = pos
            return ((x - center) * scale_x, (y - (nose + tail) / 2) * scale_y, z)
        def mirrored(pos):
            return [2 * center - pos[0], pos[1]]
    return point, mirrored, scale_x


def polygon_airfoil(name, source_points, point, mirrored, palette, variant, mirror=False, tail=False):
    plan = [mirrored(p) if mirror else p for p in source_points]
    top, bottom = [], []
    span_half = variant['dimensions_m']['span'] / 2
    for p in plan:
        x, y, _ = point(p)
        fraction = min(1.0, abs(x) / span_half)
        if tail:
            z = 0.32
            thickness = 0.07
        elif variant['id'] == 'ju87-b2':
            z = 0.22 - 0.16 * min(fraction / 0.3, 1) + 0.20 * max(0, fraction - 0.3) / 0.7
            thickness = 0.14 * (1 - 0.4 * fraction)
        else:
            z = 0.22 + 0.04 * fraction
            thickness = (0.24 if variant['id'] == 'junkers-g38' else 0.13) * (1 - 0.45 * fraction)
        top.append((x, y, z + thickness / 2))
        bottom.append((x, y, z - thickness / 2))
    count = len(top)
    triangles = tessellate_polygon([[Vector((x, y, 0)) for x, y, _ in top]])
    faces, ids = [], []
    for tri in triangles:
        triangle = tuple(tri)
        faces.append(triangle)
        ids.append(0)
        faces.append(tuple(i + count for i in reversed(triangle)))
        ids.append(1)
    for i in range(count):
        j = (i + 1) % count
        faces.append((i, j, j + count, i + count))
        ids.append(0)
    return mesh(name, top + bottom, faces, palette, ids)


def body(spec, point, width_scale, palette):
    sections = spec['body_sections_px']
    count = 16
    verts = []
    for longitudinal, half_width in sections:
        pos = [longitudinal, spec['centerline_px']] if spec['source_orientation'] == 'nose_left' else [spec['centerline_px'], longitudinal]
        _, y, _ = point(pos)
        width = max(0.012, half_width * width_scale)
        fraction = abs(y) / (spec['dimensions_m']['length'] / 2)
        z_height = (0.37 if spec['id'] == 'junkers-g38' else 0.22) * (1 - 0.3 * fraction)
        for i in range(count):
            a = i * 2 * math.pi / count
            verts.append((width * math.cos(a), y, 0.34 + z_height * math.sin(a)))
    faces, ids = [], []
    for i in range(len(sections) - 1):
        for k in range(count):
            l = (k + 1) % count
            faces.append((i * count + k, i * count + l, (i + 1) * count + l, (i + 1) * count + k))
            ids.append(1 if k >= 9 else 0)
    mesh('source-width fuselage', verts, faces, palette, ids)


def disc(name, center, radius, z, material):
    cx, cy = center
    vertices = [(cx, cy, z)]
    vertices += [(cx + radius * math.cos(i * 2 * math.pi / 40),
                  cy + radius * math.sin(i * 2 * math.pi / 40), z) for i in range(40)]
    faces = [(0, i + 1, (i + 1) % 40 + 1) for i in range(40)]
    return mesh(name, vertices, faces, [material])


def cross(center, size, z, white, black):
    cx, cy = center
    for factor, material, level in [(1.0, white, z), (0.72, black, z + 0.008)]:
        arm = size * factor
        bar = arm * 0.27
        vertices = [(cx - bar, cy - arm, level), (cx + bar, cy - arm, level),
                    (cx + bar, cy - bar, level), (cx + arm, cy - bar, level),
                    (cx + arm, cy + bar, level), (cx + bar, cy + bar, level),
                    (cx + bar, cy + arm, level), (cx - bar, cy + arm, level),
                    (cx - bar, cy + bar, level), (cx - arm, cy + bar, level),
                    (cx - arm, cy - bar, level), (cx - bar, cy - bar, level)]
        mesh('Balkenkreuz without extremist symbols', vertices, [tuple(range(12))], [material])


def ellipsoid(name, center, scale, material):
    bpy.ops.mesh.primitive_uv_sphere_add(segments=16, ring_count=8, location=center)
    obj = bpy.context.object
    obj.name = name
    obj.scale = scale
    obj.data.materials.append(material)
    return obj


def propeller(name, center, radius, material):
    group = bpy.data.objects.new(name, None)
    bpy.context.collection.objects.link(group)
    group.location = center
    for phase in (0, 120, 240):
        angle = math.radians(phase)
        end = Vector((center[0] + radius * math.cos(angle),
                      center[1] + radius * math.sin(angle), center[2]))
        middle = (Vector(center) + end) / 2
        direction = end - Vector(center)
        bpy.ops.mesh.primitive_cylinder_add(vertices=8, radius=radius * 0.055,
                                            depth=direction.length, location=middle)
        blade = bpy.context.object
        blade.name = 'propeller blade'
        blade.rotation_euler = direction.to_track_quat('Z', 'Y').to_euler()
        blade.data.materials.append(material)
        blade.parent = group
        blade.matrix_parent_inverse = group.matrix_world.inverted()
    return group


def build(spec):
    point, mirrored, width_scale = converter(spec)
    if spec['palette'] == 'junkers_silver':
        upper = mat('Junkers duralumin upper', (0.27, 0.34, 0.34))
        lower = mat('Junkers underside', (0.22, 0.28, 0.29))
    else:
        upper = mat('RLM 70 and 71 camouflage', (0.095, 0.15, 0.125), (0.16, 0.19, 0.16))
        lower = mat('RLM 65 underside', (0.34, 0.47, 0.48))
    glass = mat('canopy glazing', (0.045, 0.10, 0.12))
    dark = mat('engine and propeller', (0.11, 0.13, 0.13))
    white = mat('cross white', (0.81, 0.81, 0.75))
    black = mat('cross black', (0.025, 0.035, 0.035))
    scorch = mat('damage dark metal', (0.08, 0.08, 0.07))
    palette = [upper, lower]
    for side in (False, True):
        polygon_airfoil('measured wing', spec['right_wing_px'], point, mirrored,
                        palette, spec, mirror=side)
        polygon_airfoil('measured tailplane', spec['right_tail_px'], point, mirrored,
                        palette, spec, mirror=side, tail=True)
    body(spec, point, width_scale, palette)
    canopy_start, canopy_end = spec['canopy_px']
    if spec['source_orientation'] == 'nose_left':
        pos = [(canopy_start + canopy_end) / 2, spec['centerline_px']]
    else:
        pos = [spec['centerline_px'], (canopy_start + canopy_end) / 2]
    cx, cy, _ = point(pos)
    canopy_length = abs(canopy_end - canopy_start) * spec['dimensions_m']['length'] / abs(spec['tail_px'] - spec['nose_px'])
    ellipsoid('narrow historic canopy', (cx, cy, 0.63),
              (0.38 if spec['id'] == 'junkers-g38' else 0.27, canopy_length / 2, 0.15), glass)

    if spec['id'] != 'junkers-g38':
        mark = point(spec['roundel_px'])
        for x in (-abs(mark[0]), abs(mark[0])):
            cross((x, mark[1]), 0.30 if spec['id'] == 'bf109-e4' else 0.39, 0.37, white, black)
        if spec['id'] == 'ju87-b2':
            for source in spec['fixed_gear_px']:
                x, y, _ = point(source)
                ellipsoid('Ju 87 fixed wheel fairing', (x, y, 0.40), (0.18, 0.55, 0.16), upper)
            # A pair of narrow dive brakes along the trailing wing surfaces.
            for x in (-2.7, 2.7):
                ellipsoid('Ju 87 dive brake', (x, 0.18, 0.31), (0.48, 0.04, 0.04), dark)
        else:
            for x in (-1.7, 1.7):
                ellipsoid('Bf 109 wing radiator', (x, 1.12, 0.32), (0.30, 0.25, 0.05), lower)
    engines = []
    if spec['id'] == 'junkers-g38':
        for source in spec['engine_centers_px']:
            x, y, _ = point(source)
            ellipsoid('four-engine nacelle', (x, y, 0.40), (0.42, 0.95, 0.28), lower)
            engines.append(propeller('G 38 propeller', (x, y + 0.96, 0.40), 0.58, dark))
    else:
        nose_position = [spec['nose_px'], spec['centerline_px']]
        nx, ny, _ = point(nose_position)
        ellipsoid('inline-engine spinner', (nx, ny + 0.13, 0.33), (0.16, 0.27, 0.16), dark)
        engines.append(propeller('three-blade propeller', (nx, ny + 0.28, 0.33), 0.48, dark))
    return engines, scorch


def configure(spec, device):
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete(use_global=False)
    scene = bpy.context.scene
    scene.render.engine = 'CYCLES'
    scene.cycles.device = 'GPU' if device.startswith('CUDA') else 'CPU'
    scene.cycles.samples = 20
    scene.cycles.use_denoising = True
    scene.render.film_transparent = True
    scene.render.image_settings.file_format = 'PNG'
    scene.render.image_settings.color_mode = 'RGBA'
    scene.view_settings.view_transform = 'Standard'
    world = bpy.data.worlds.new('soft daylight')
    scene.world = world
    world.use_nodes = True
    world.node_tree.nodes['Background'].inputs['Color'].default_value = (0.70, 0.77, 0.78, 1)
    world.node_tree.nodes['Background'].inputs['Strength'].default_value = 1.1
    bpy.ops.object.camera_add(location=(0, 0, 25))
    camera = bpy.context.object
    camera.data.type = 'ORTHO'
    camera.data.ortho_scale = spec['dimensions_m']['span'] * 1.12
    camera.rotation_euler = (Vector((0, 0, 0)) - camera.location).to_track_quat('-Z', 'Y').to_euler()
    scene.camera = camera
    bpy.ops.object.light_add(type='AREA', location=(-4, 5, 12))
    light = bpy.context.object
    light.data.energy = 1050
    light.data.shape = 'DISK'
    light.data.size = 9
    return scene


def gpu():
    try:
        prefs = bpy.context.preferences.addons['cycles'].preferences
        prefs.compute_device_type = 'CUDA'
        prefs.get_devices()
        devices = [d for d in prefs.devices if d.type == 'CUDA' and 'NVIDIA' in d.name.upper()]
        for d in prefs.devices:
            d.use = d in devices
        if devices:
            return 'CUDA ' + ', '.join(d.name for d in devices)
    except Exception as error:
        print('CUDA unavailable:', error)
    return 'CPU'


def render(spec, out, device):
    scene = configure(spec, device)
    props, scorch = build(spec)
    root = bpy.data.objects.new('bank and orientation root', None)
    bpy.context.collection.objects.link(root)
    for obj in list(bpy.context.scene.objects):
        if obj is root or obj.type in ('CAMERA', 'LIGHT') or obj.parent is not None:
            continue
        obj.parent = root
        obj.matrix_parent_inverse = root.matrix_world.inverted()
    scene.render.resolution_x = scene.render.resolution_y = 1024
    scene.render.filepath = str(out / (spec['id'] + '-review.png'))
    bpy.ops.render.render(write_still=True)
    if spec['id'] == 'junkers-g38':
        # Two Blender-rendered states for the existing 512x256 boss atlas.
        root.rotation_euler[2] = math.pi
        scene.render.filepath = str(out / (spec['id'] + '-boss.png'))
        bpy.ops.render.render(write_still=True)
        damaged = disc('damaged engine panel', (spec['dimensions_m']['span'] * 0.13, 0.75), 0.80, 0.76, scorch)
        damaged.parent = root
        damaged.matrix_parent_inverse = root.matrix_world.inverted()
        scene.render.filepath = str(out / (spec['id'] + '-damage.png'))
        bpy.ops.render.render(write_still=True)
        root.rotation_euler[2] = 0
    else:
        scene.render.resolution_x = scene.render.resolution_y = 512
        for bank_index, bank in enumerate((-24, 0, 24)):
            root.rotation_euler = (0, math.radians(bank), math.pi)
            for prop_index, phase in enumerate((0, 45, 90)):
                for group in props:
                    group.rotation_euler[2] = math.radians(phase)
                scene.render.filepath = str(out / f"{spec['id']}-b{bank_index}-p{prop_index}.png")
                bpy.ops.render.render(write_still=True)
        root.rotation_euler = (0, 0, 0)
    bpy.ops.wm.save_as_mainfile(filepath=str(out / (spec['id'] + '.blend')))
    return {'id': spec['id'], 'device': device, 'blender': bpy.app.version_string,
            'source': spec['source_file'], 'review_pixels': [1024, 1024]}


def main():
    options = args()
    out = Path(options.out)
    out.mkdir(parents=True, exist_ok=True)
    device = gpu()
    records = []
    for source in sorted(Path(options.spec_dir).glob('*.json')):
        spec = json.loads(source.read_text(encoding='utf-8'))
        records.append(render(spec, out, device))
    (out / 'render-info.json').write_text(json.dumps(records, indent=2), encoding='utf-8')
    print('SOURCE-TRACED RENDERS COMPLETE:', records)


if __name__ == '__main__':
    main()
