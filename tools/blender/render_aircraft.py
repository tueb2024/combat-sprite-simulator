import bpy
import math
import os
from mathutils import Vector

OUT = os.environ.get('COMBAT_ASSET_DIR', 'C:/Users/tchou/combat-sprite-simulator-assets')
os.makedirs(OUT, exist_ok=True)

bpy.ops.object.select_all(action='SELECT')
bpy.ops.object.delete(use_global=False)
scene = bpy.context.scene
scene.render.engine = 'CYCLES'
scene.cycles.samples = 16
scene.render.film_transparent = True
scene.render.image_settings.file_format = 'PNG'
scene.render.image_settings.color_mode = 'RGBA'
scene.view_settings.view_transform = 'Standard'
scene.render.resolution_percentage = 100
world = bpy.data.worlds.new('Neutral light')
scene.world = world
world.use_nodes = True
world.node_tree.nodes.get('Background').inputs['Color'].default_value = (0.65, 0.72, 0.8, 1)
world.node_tree.nodes.get('Background').inputs['Strength'].default_value = 1.5

bpy.ops.object.camera_add(location=(0, 0, 15))
camera = bpy.context.object
camera.name = 'Orthographic sprite camera'
camera.data.type = 'ORTHO'
camera.data.ortho_scale = 8
scene.camera = camera
camera.rotation_euler = (0, 0, 0)
camera.rotation_euler = (Vector((0, 0, 0)) - camera.location).to_track_quat('-Z', 'Y').to_euler()

def material(name, rgb):
    m = bpy.data.materials.new(name)
    m.diffuse_color = (*rgb, 1)
    m.use_nodes = True
    m.node_tree.nodes.get('Principled BSDF').inputs['Base Color'].default_value = (*rgb, 1)
    m.node_tree.nodes.get('Principled BSDF').inputs['Roughness'].default_value = 0.85
    return m

olive = material('RAF dark earth', (0.30, 0.28, 0.17))
green = material('RAF dark green', (0.12, 0.22, 0.15))
cream = material('RAF roundel cream', (0.82, 0.80, 0.65))
blue = material('RAF blue', (0.07, 0.13, 0.28))
red = material('RAF red', (0.52, 0.08, 0.06))
glass = material('Cockpit blue', (0.12, 0.23, 0.30))
metal = material('Junkers silver', (0.52, 0.54, 0.50))
dark = material('Engine dark', (0.10, 0.11, 0.10))

def cube(name, loc, scale, mat, parent=None):
    bpy.ops.mesh.primitive_cube_add(size=1, location=loc)
    o = bpy.context.object
    o.name = name
    o.dimensions = scale
    bpy.ops.object.transform_apply(location=False, rotation=False, scale=True)
    o.data.materials.append(mat)
    if parent:
        o.parent = parent
        o.matrix_parent_inverse = parent.matrix_world.inverted()
    return o

def disc(name, loc, radius, mat, parent=None):
    bpy.ops.mesh.primitive_cylinder_add(vertices=32, radius=radius, depth=0.04, location=loc)
    o = bpy.context.object
    o.name = name
    o.data.materials.append(mat)
    if parent:
        o.parent = parent
        o.matrix_parent_inverse = parent.matrix_world.inverted()
    return o

bpy.ops.object.empty_add(location=(0, 0, 0))
root = bpy.context.object
root.name = 'Hawker Hurricane - original arcade model'
cube('fuselage', (0, 0, 0.18), (0.50, 4.2, 0.44), olive, root)
cube('main wings', (0, 0.20, 0.15), (5.5, 0.88, 0.20), green, root)
cube('tailplane', (0, -1.65, 0.24), (2.15, 0.42, 0.12), olive, root)
cube('fin', (0, -1.62, 0.42), (0.18, 0.58, 0.42), green, root)
cube('canopy', (0, 0.30, 0.52), (0.34, 0.80, 0.22), glass, root)
for side in [-1, 1]:
    disc('wing roundel blue', (side * 1.55, 0.18, 0.29), 0.31, blue, root)
    disc('wing roundel cream', (side * 1.55, 0.18, 0.32), 0.22, cream, root)
    disc('wing roundel red', (side * 1.55, 0.18, 0.35), 0.11, red, root)
prop = cube('propeller', (0, 2.2, 0.25), (0.95, 0.08, 0.06), dark, root)

scene.render.resolution_x = 256
scene.render.resolution_y = 256
frames = []
for row, bank in enumerate([-0.12, 0.0, 0.12]):
    root.rotation_euler[2] = bank
    for col, angle in enumerate([0.0, 0.50, 1.00]):
        prop.rotation_euler[2] = angle
        path = os.path.join(OUT, f'hurricane-{row}-{col}.png')
        scene.render.filepath = path
        bpy.ops.render.render(write_still=True)
        frames.append(path)
root.hide_render = True
for obj in list(root.children):
    obj.hide_render = True

bpy.ops.object.empty_add(location=(0, 0, 0))
boss = bpy.context.object
boss.name = 'Junkers G 38 - fictional arcade enemy'
cube('corrugated transport fuselage', (0, 0, 0.2), (0.85, 4.0, 0.55), metal, boss)
cube('broad wing', (0, 0.25, 0.26), (7.2, 1.12, 0.24), metal, boss)
cube('horizontal tail', (0, -1.55, 0.33), (2.65, 0.42, 0.13), metal, boss)
cube('vertical tail', (0, -1.52, 0.62), (0.25, 0.72, 0.53), metal, boss)
cube('cockpit glazing', (0, 1.62, 0.55), (0.65, 0.40, 0.13), glass, boss)
for x in [-2.55, -1.25, 1.25, 2.55]:
    cube('one of four engines', (x, 0.55, 0.10), (0.48, 0.86, 0.42), dark, boss)
    cube('propeller', (x, 1.05, 0.11), (0.68, 0.07, 0.06), dark, boss)
for y in [-1.2, -0.65, -0.1, 0.45, 1.0]:
    cube('wing corrugation', (0, y, 0.39), (7.0, 0.025, 0.01), cream, boss)
scene.render.resolution_x = 512
scene.render.resolution_y = 256
camera.data.ortho_scale = 8.6
for damage in [0, 1]:
    if damage:
        cube('fictional damage patch', (2.1, 0.5, 0.5), (0.8, 0.5, 0.05), dark, boss)
    scene.render.filepath = os.path.join(OUT, f'junkers-{damage}.png')
    bpy.ops.render.render(write_still=True)

bpy.ops.wm.save_as_mainfile(filepath=os.path.join(OUT, 'aircraft.blend'))
