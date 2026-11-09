"""Deterministic, original low-poly aircraft sprites from aircraft-spec.json.

Blender 5.x: blender -b --python parametric_aircraft.py -- --spec FILE --out DIR
No book pages, reference images, or private text are copied to the GTX machine.
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
    parser.add_argument('--spec', required=True)
    parser.add_argument('--out', required=True)
    return parser.parse_args(sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else [])


def material(name, rgb, alpha=1.0):
    mat = bpy.data.materials.new(name)
    mat.diffuse_color = (*rgb, alpha)
    mat.use_nodes = True
    bsdf = mat.node_tree.nodes.get('Principled BSDF')
    bsdf.inputs['Base Color'].default_value = (*rgb, 1)
    bsdf.inputs['Roughness'].default_value = 0.82
    bsdf.inputs['Alpha'].default_value = alpha
    if alpha < 1:
        mat.surface_render_method = 'DITHERED'
    return mat


def mesh(name, vertices, faces, mat, parent):
    data = bpy.data.meshes.new(name)
    data.from_pydata(vertices, [], faces)
    data.update()
    obj = bpy.data.objects.new(name, data)
    bpy.context.collection.objects.link(obj)
    obj.data.materials.append(mat)
    obj.parent = parent
    return obj


def box(name, xyz, size, mat, parent):
    x, y, z = xyz
    sx, sy, sz = (v / 2 for v in size)
    vertices = [(x + dx, y + dy, z + dz) for dz in (-sz, sz)
                for dy in (-sy, sy) for dx in (-sx, sx)]
    return mesh(name, vertices, [(0, 1, 3, 2), (4, 6, 7, 5),
                                 (0, 4, 5, 1), (2, 3, 7, 6),
                                 (0, 2, 6, 4), (1, 5, 7, 3)], mat, parent)


def wing(name, span, y, root_chord, tip_chord, sweep, z, mat, parent):
    # Tapered half-wings give a recognisable planform; all lengths use span units.
    for sign in (-1, 1):
        inner = 0.07 * span
        outer = 0.5 * span
        a = y + root_chord / 2
        b = y - root_chord / 2
        c = y + sweep + tip_chord / 2
        d = y + sweep - tip_chord / 2
        top = [(sign * inner, a, z), (sign * outer, c, z + 0.05),
               (sign * outer, d, z + 0.05), (sign * inner, b, z)]
        bottom = [(x, yy, zz - 0.075) for x, yy, zz in top]
        mesh(f'{name} {sign}', top + bottom,
             [(0, 1, 2, 3), (7, 6, 5, 4), (0, 4, 5, 1),
              (1, 5, 6, 2), (2, 6, 7, 3), (3, 7, 4, 0)], mat, parent)


def fuselage(length, width, mat, parent):
    # Five stations produce a distinct nose and tapered tail, without a cube silhouette.
    stations = [(-0.50, 0.28), (-0.35, 0.65), (-0.10, 1.0),
                (0.30, 0.85), (0.50, 0.35)]
    verts = []
    for y_fraction, width_fraction in stations:
        y = y_fraction * length
        hw = width * width_fraction / 2
        verts += [(-hw, y, 0.0), (hw, y, 0.0),
                  (-hw, y, 0.28), (hw, y, 0.28)]
    faces = []
    for i in range(len(stations) - 1):
        k = i * 4
        faces += [(k, k + 1, k + 5, k + 4),
                  (k + 2, k + 6, k + 7, k + 3),
                  (k, k + 4, k + 6, k + 2),
                  (k + 1, k + 3, k + 7, k + 5)]
    faces += [(0, 2, 3, 1), (16, 17, 19, 18)]
    mesh('tapered fuselage', verts, faces, mat, parent)


def roundel(name, xyz, radius, mats, parent):
    for i, mat in enumerate(mats):
        bpy.ops.mesh.primitive_cylinder_add(vertices=24, radius=radius * (1 - i * 0.28),
                                            depth=0.009, location=(xyz[0], xyz[1], xyz[2] + i * 0.012))
        obj = bpy.context.object
        obj.name = f'{name} ring {i}'
        obj.data.materials.append(mat)
        obj.parent = parent


def build_aircraft(spec):
    span = 6.0
    length = span * spec['length_m'] / spec['wingspan_m']
    wing_cfg = spec['wing']
    tail_cfg = spec['tail']
    pal = spec['palette']
    body = material(spec['id'] + ' body', pal['body'])
    wing_mat = material(spec['id'] + ' wing', pal['wing'])
    dark = material(spec['id'] + ' dark', pal['accent'])
    glass = material(spec['id'] + ' cockpit', (0.09, 0.19, 0.23))
    pale = material(spec['id'] + ' pale', (0.77, 0.76, 0.67))
    red = material(spec['id'] + ' red', (0.54, 0.06, 0.05))
    bpy.ops.object.empty_add(location=(0, 0, 0))
    root = bpy.context.object
    root.name = spec['variant']
    fuselage(length, span * spec['fuselage_width'], body, root)
    wy = wing_cfg['leading_y'] * length
    chord = wing_cfg['root_chord'] * length
    wing('main wing', span, wy, chord, wing_cfg['tip_chord'] * length,
         wing_cfg['tip_sweep'] * length, 0.18, wing_mat, root)
    if spec['family'] == 'biplane':
        wing('upper wing', span, wy + 0.15 * length, chord,
             wing_cfg['tip_chord'] * length, wing_cfg['tip_sweep'] * length,
             0.76, wing_mat, root)
        for sign in (-1, 1):
            for x_fraction in (0.22, 0.37):
                box('interplane strut', (sign * span * x_fraction, wy + 0.07 * length, 0.46),
                    (0.055, 0.06, 0.58), dark, root)
    ty = tail_cfg['y'] * length
    wing('tailplane', span * tail_cfg['span'], ty, 0.17 * length,
         0.10 * length, -0.02 * length, 0.26, body, root)
    fin_xs = (-span * 0.10, span * 0.10) if tail_cfg['twin_fin'] else (0.0,)
    for x in fin_xs:
        box('vertical fin', (x, ty, 0.46), (0.12, 0.34 * length, 0.38), dark, root)
    box('cockpit glazing', (0, 0.15 * length, 0.33),
        (span * spec['fuselage_width'] * 0.65, 0.19 * length, 0.13), glass, root)
    if spec['id'] == 'hurricane':
        for side in (-1, 1):
            roundel('RAF wing', (side * 0.26 * span, wy, 0.30), 0.28,
                    [dark, pale, red], root)
    if spec['id'] == 'junkers':
        # Surface ribs hint at corrugation without copying any reference profile.
        for n in range(-2, 3):
            box('wing corrugation', (0, wy + n * 0.055 * length, 0.26),
                (span * 0.65, 0.012, 0.012), pale, root)
    propeller_sets = []
    for i, (xf, yf) in enumerate(spec['engine_positions']):
        x, y = xf * span, yf * length
        box(f'engine {i}', (x, y, 0.11),
            (0.29 if len(spec['engine_positions']) > 1 else 0.40, 0.48, 0.27), dark, root)
        states = []
        for phase in range(3):
            bpy.ops.object.empty_add(location=(x, y + 0.29, 0.16))
            hub = bpy.context.object
            hub.name = f'propeller {i} phase {phase}'
            hub.parent = root
            for blade in range(2 + phase):
                angle = blade * math.pi / (2 + phase)
                blade_obj = box('propeller blade', (0, 0, 0),
                                (0.035 + phase * 0.018, 0.80, 0.022), dark, hub)
                blade_obj.rotation_euler[2] = angle
            states.append(hub)
        propeller_sets.append(states)
    damage = None
    if spec['animation']['damage_states'] > 1:
        damage = box('fictional battle damage', (span * 0.25, wy, 0.34),
                     (0.72, 0.32, 0.02), dark, root)
    return root, propeller_sets, damage


def configure_scene():
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete(use_global=False)
    scene = bpy.context.scene
    scene.render.engine = 'CYCLES'
    scene.cycles.samples = 12
    scene.cycles.use_denoising = True
    scene.render.film_transparent = True
    scene.render.image_settings.file_format = 'PNG'
    scene.render.image_settings.color_mode = 'RGBA'
    scene.render.resolution_percentage = 100
    scene.view_settings.view_transform = 'Standard'
    scene.render.image_settings.color_depth = '8'
    scene.render.image_settings.compression = 15
    world = bpy.data.worlds.new('Neutral sky illumination')
    scene.world = world
    world.use_nodes = True
    world.node_tree.nodes['Background'].inputs['Color'].default_value = (0.7, 0.75, 0.8, 1)
    world.node_tree.nodes['Background'].inputs['Strength'].default_value = 1.2
    bpy.ops.object.camera_add(location=(0, 0, 15))
    camera = bpy.context.object
    camera.data.type = 'ORTHO'
    camera.data.ortho_scale = 7.5
    camera.rotation_euler = (Vector((0, 0, 0)) - camera.location).to_track_quat('-Z', 'Y').to_euler()
    scene.camera = camera
    bpy.ops.object.light_add(type='AREA', location=(-3, 4, 8))
    bpy.context.object.data.energy = 500
    bpy.context.object.data.shape = 'DISK'
    bpy.context.object.data.size = 8
    return scene


def main():
    args = arguments()
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    specs = json.loads(Path(args.spec).read_text(encoding='utf-8'))['aircraft']
    scene = configure_scene()
    manifest = {}
    for spec in specs:
        root, props, damage = build_aircraft(spec)
        width, height = spec['tile']
        scene.render.resolution_x = width
        scene.render.resolution_y = height
        frames = []
        for damage_index in range(spec['animation']['damage_states']):
            for bank_index, degrees in enumerate(spec['animation']['bank_degrees']):
                root.rotation_euler[1] = math.radians(degrees)
                for prop_index, phase_degrees in enumerate(spec['animation']['propeller_degrees']):
                    for states in props:
                        for phase, hub in enumerate(states):
                            hub.hide_render = phase != prop_index
                            for blade_obj in hub.children:
                                blade_obj.hide_render = phase != prop_index
                            hub.rotation_euler[2] = math.radians(phase_degrees)
                    if damage:
                        damage.hide_render = damage_index == 0
                    name = f"{spec['id']}-d{damage_index}-b{bank_index}-p{prop_index}.png"
                    scene.render.filepath = str(out / name)
                    bpy.ops.render.render(write_still=True)
                    frames.append(name)
        manifest[spec['id']] = {'tile': spec['tile'], 'frames': frames,
                                 'damage_states': spec['animation']['damage_states']}
        for obj in root.children_recursive:
            obj.hide_render = True
        root.hide_render = True
    (out / 'render-manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    bpy.ops.wm.save_as_mainfile(filepath=str(out / 'parametric-aircraft.blend'))


if __name__ == '__main__':
    main()
