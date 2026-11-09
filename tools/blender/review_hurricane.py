"""Source-traced Hurricane Mk I review render; execute only with Blender on GTX.

The wing and tail perimeter are sampled from the licensed Mk I three-view SVG.
The fuselage station widths are hand calibrated to the same top-view drawing.
This is a review prototype, not a replacement for the game's active atlas.
"""

import argparse
import json
import math
from pathlib import Path
import sys

import bpy
from mathutils import Vector
from mathutils.geometry import tessellate_polygon


def arguments():
    parser = argparse.ArgumentParser()
    parser.add_argument('--outline', required=True)
    parser.add_argument('--out', required=True)
    return parser.parse_args(sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else [])


def material(name, color, metallic=0):
    mat = bpy.data.materials.new(name)
    mat.diffuse_color = (*color, 1)
    mat.use_nodes = True
    shader = mat.node_tree.nodes.get('Principled BSDF')
    shader.inputs['Base Color'].default_value = (*color, 1)
    shader.inputs['Roughness'].default_value = 0.77
    shader.inputs['Metallic'].default_value = metallic
    return mat


def mesh(name, vertices, faces, palette, face_ids=None):
    data = bpy.data.meshes.new(name)
    data.from_pydata(vertices, [], faces)
    data.update()
    obj = bpy.data.objects.new(name, data)
    bpy.context.collection.objects.link(obj)
    for mat in palette:
        data.materials.append(mat)
    if face_ids:
        for polygon, index in zip(data.polygons, face_ids):
            polygon.material_index = index
    return obj


def build(data):
    center = data['center_x_px']
    sx = data['dimensions_m']['span'] / data['span_px']
    sy = data['dimensions_m']['length'] / (data['tail_y_px'] - data['nose_y_px'])
    mid_y = (data['tail_y_px'] + data['nose_y_px']) / 2

    def xyz(point, z):
        return ((point[0] - center) * sx, (mid_y - point[1]) * sy, z)

    earth = material('RAF Dark Earth and Green', (0.19, 0.14, 0.085))
    green = material('RAF Dark Green', (0.055, 0.12, 0.075))
    sky = material('RAF Sky undersides', (0.55, 0.65, 0.57))
    glass = material('glazed canopy', (0.045, 0.105, 0.125), 0.18)
    frame = material('canopy frame', (0.19, 0.23, 0.20))
    spinner = material('spinner and blades', (0.17, 0.19, 0.18), 0.15)
    blue = material('RAF roundel blue', (0.05, 0.13, 0.38))
    white = material('RAF roundel white', (0.82, 0.83, 0.77))
    red = material('RAF roundel red', (0.52, 0.10, 0.08))
    palette = [earth, green, sky, glass, frame, spinner, blue, white, red]

    # Original procedural camouflage, clipped by the source-traced meshes.
    nodes = earth.node_tree.nodes
    noise = nodes.new('ShaderNodeTexNoise')
    noise.inputs['Scale'].default_value = 3.4
    noise.inputs['Detail'].default_value = 1.4
    ramp = nodes.new('ShaderNodeValToRGB')
    ramp.color_ramp.interpolation = 'CONSTANT'
    ramp.color_ramp.elements[0].position = 0.47
    ramp.color_ramp.elements[0].color = (0.19, 0.14, 0.085, 1)
    ramp.color_ramp.elements[1].position = 0.48
    ramp.color_ramp.elements[1].color = (0.055, 0.12, 0.075, 1)
    earth.node_tree.links.new(noise.outputs['Fac'], ramp.inputs['Fac'])
    earth.node_tree.links.new(ramp.outputs['Color'], nodes.get('Principled BSDF').inputs['Base Color'])

    def flat_airfoil(name, outline, mirror=False, tail=False):
        points = [(2 * center - x, y) if mirror else (x, y) for x, y in outline]
        # The SVG path ends at the root. Closing it follows the center fairing.
        if math.dist(points[0], points[-1]) < 2:
            points = points[:-1]
        count = len(points)
        upper = []
        lower = []
        for point in points:
            fraction = min(1, abs(point[0] - center) / (data['span_px'] / 2))
            base = (0.30 if tail else 0.18) + (0.10 if tail else 0.04) * (1 - fraction)
            thickness = 0.07 if tail else 0.14 * (1 - 0.52 * fraction)
            upper.append(xyz(point, base + thickness / 2))
            lower.append(xyz(point, base - thickness / 2))
        flat = [Vector((v[0], v[1], 0)) for v in upper]
        triangles = tessellate_polygon([flat])
        faces = []
        ids = []
        for tri in triangles:
            vertices = tuple(tri)
            faces.append(vertices)
            cx = sum(upper[i][0] for i in vertices) / 3
            cy = sum(upper[i][1] for i in vertices) / 3
            ids.append(1 if (math.sin(cx * 1.2 + cy * 1.7) + math.cos(cy * 2.1)) > 0.2 else 0)
            faces.append(tuple(i + count for i in reversed(vertices)))
            ids.append(2)
        for i in range(count):
            j = (i + 1) % count
            faces.append((i, j, j + count, i + count))
            ids.append(1 if tail else 0)
        mesh(name, upper + lower, faces, palette, ids)

    flat_airfoil('right source-traced wing', data['right_wing'])
    flat_airfoil('left source-traced wing', data['right_wing'], mirror=True)
    flat_airfoil('right source-traced tailplane', data['right_tailplane'], tail=True)
    flat_airfoil('left source-traced tailplane', data['right_tailplane'], mirror=True, tail=True)

    # Twenty measured sections replace the generic eight-station airframe.
    sections = data['fuselage_sections_px']
    ring_size = 16
    verts = []
    for y_px, half_width_px in sections:
        y = (mid_y - y_px) * sy
        half_width = max(0.015, half_width_px * sx)
        fraction = (y_px - data['nose_y_px']) / (data['tail_y_px'] - data['nose_y_px'])
        half_height = 0.20 + 0.24 * math.sin(min(1, fraction) * math.pi)
        center_z = 0.35 - 0.06 * fraction
        for i in range(ring_size):
            angle = 2 * math.pi * i / ring_size
            verts.append((half_width * math.cos(angle), y,
                          center_z + half_height * math.sin(angle)))
    faces, ids = [], []
    for station in range(len(sections) - 1):
        for side in range(ring_size):
            next_side = (side + 1) % ring_size
            faces.append((station * ring_size + side, station * ring_size + next_side,
                          (station + 1) * ring_size + next_side, (station + 1) * ring_size + side))
            ids.append(2 if side >= 9 and side <= 15 else (1 if (station // 3 + side // 3) % 3 == 0 else 0))
    mesh('twenty-source-section-fuselage', verts, faces, palette, ids)

    # Long, framed Mk I canopy following the annotated plan rectangle.
    canopy_sections = [(570, 13, 0.70), (584, 27, 0.88), (609, 30, 0.97),
                       (633, 31, 1.01), (661, 30, 0.98), (687, 25, 0.88), (705, 13, 0.69)]
    canopy_verts = []
    for y_px, half_width_px, top in canopy_sections:
        y = (mid_y - y_px) * sy
        w = half_width_px * sx
        canopy_verts.extend([(-w, y, 0.61), (-w * 0.56, y, top),
                             (w * 0.56, y, top), (w, y, 0.61)])
    canopy_faces = []
    for station in range(len(canopy_sections) - 1):
        for side in range(3):
            a = station * 4 + side
            canopy_faces.append((a, a + 1, a + 5, a + 4))
    mesh('framed Mk I canopy glass', canopy_verts, canopy_faces, [glass])

    def rod(name, a, b, radius, mat):
        direction = Vector(b) - Vector(a)
        center_point = (Vector(a) + Vector(b)) / 2
        bpy.ops.mesh.primitive_cylinder_add(vertices=8, radius=radius,
                                            depth=direction.length, location=center_point)
        obj = bpy.context.object
        obj.name = name
        obj.rotation_euler = direction.to_track_quat('Z', 'Y').to_euler()
        obj.data.materials.append(mat)

    for station in (1, 2, 3, 4, 5):
        i = station * 4
        rod('transverse canopy frame', canopy_verts[i + 1], canopy_verts[i + 2], 0.014, frame)
    for side in (0, 1, 2, 3):
        for station in range(len(canopy_sections) - 1):
            rod('longitudinal canopy frame', canopy_verts[station * 4 + side],
                canopy_verts[(station + 1) * 4 + side], 0.012, frame)

    # Roundels are separate original mesh discs; no scanned textures.
    for px, py in data['roundel_centers_px']:
        cx, cy, _ = xyz((px, py), 0)
        for radius, z, mat in [(0.36, 0.34, blue), (0.25, 0.345, white), (0.14, 0.35, red)]:
            verts = [(cx, cy, z)]
            verts.extend((cx + radius * math.cos(i * 2 * math.pi / 40),
                          cy + radius * math.sin(i * 2 * math.pi / 40), z) for i in range(40))
            faces = [(0, i + 1, (i + 1) % 40 + 1) for i in range(40)]
            mesh('RAF upper-wing roundel', verts, faces, [mat])

    nose_y = (mid_y - data['nose_y_px']) * sy
    bpy.ops.mesh.primitive_uv_sphere_add(segments=16, ring_count=8,
                                         location=(0, nose_y + 0.08, 0.35))
    bpy.context.object.name = 'Merlin spinner'
    bpy.context.object.scale = (0.18, 0.34, 0.18)
    bpy.context.object.data.materials.append(spinner)
    for angle in (0, 120, 240):
        theta = math.radians(angle)
        rod('three-blade propeller', (0, nose_y + 0.24, 0.36),
            (0.54 * math.cos(theta), nose_y + 0.24 + 0.54 * math.sin(theta), 0.36),
            0.035, spinner)

    # Small top-visible vertical fin. The side profile remains secondary.
    verts = [(-0.04, -3.04, 0.38), (0.04, -3.04, 0.38),
             (-0.04, -4.72, 0.35), (0.04, -4.72, 0.35),
             (-0.02, -3.55, 0.63), (0.02, -3.55, 0.63)]
    mesh('vertical fin', verts, [(0, 2, 4), (1, 5, 3), (4, 2, 3, 5)], [green])


def main():
    args = arguments()
    data = json.loads(Path(args.outline).read_text(encoding='utf-8'))
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    bpy.ops.object.select_all(action='SELECT')
    bpy.ops.object.delete(use_global=False)
    device = 'CPU'
    try:
        prefs = bpy.context.preferences.addons['cycles'].preferences
        prefs.compute_device_type = 'CUDA'
        prefs.get_devices()
        gpu = [d for d in prefs.devices if d.type == 'CUDA' and 'NVIDIA' in d.name.upper()]
        for d in prefs.devices:
            d.use = d in gpu
        if gpu:
            device = 'CUDA ' + ', '.join(d.name for d in gpu)
    except Exception as error:
        print('CUDA unavailable:', error)

    scene = bpy.context.scene
    scene.render.engine = 'CYCLES'
    scene.cycles.device = 'GPU' if device.startswith('CUDA') else 'CPU'
    scene.cycles.samples = 32
    scene.cycles.use_denoising = True
    scene.render.film_transparent = True
    scene.render.resolution_x = 1024
    scene.render.resolution_y = 1024
    scene.render.resolution_percentage = 100
    scene.render.image_settings.file_format = 'PNG'
    scene.render.image_settings.color_mode = 'RGBA'
    scene.view_settings.view_transform = 'Standard'
    world = bpy.data.worlds.new('neutral review light')
    scene.world = world
    world.use_nodes = True
    world.node_tree.nodes['Background'].inputs['Color'].default_value = (0.78, 0.81, 0.83, 1)
    world.node_tree.nodes['Background'].inputs['Strength'].default_value = 1.1
    build(data)
    bpy.ops.object.camera_add(location=(0, 0, 18))
    camera = bpy.context.object
    camera.data.type = 'ORTHO'
    camera.data.ortho_scale = 13.5
    camera.rotation_euler = (Vector((0, 0, 0)) - camera.location).to_track_quat('-Z', 'Y').to_euler()
    scene.camera = camera
    bpy.ops.object.light_add(type='AREA', location=(-4, 5, 10))
    bpy.context.object.data.energy = 1100
    bpy.context.object.data.shape = 'DISK'
    bpy.context.object.data.size = 8
    scene.render.filepath = str(out / 'hurricane-prototype.png')
    bpy.ops.render.render(write_still=True)
    bpy.ops.wm.save_as_mainfile(filepath=str(out / 'hurricane-prototype.blend'))
    (out / 'render-info.json').write_text(json.dumps({
        'blender': bpy.app.version_string, 'device': device,
        'pixels': [1024, 1024], 'source_paths': data['source_paths']}, indent=2), encoding='utf-8')
    print('REVIEW HURRICANE COMPLETE', device)


if __name__ == '__main__':
    main()
