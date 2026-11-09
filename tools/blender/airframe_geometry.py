"""Reusable low-poly 1940 airframe geometry for Blender 5.x.

All airframe surfaces are meshes made from stations and rings. The source PDFs
are never needed on the renderer: only original numeric JSON specifications.
"""
import math

import bpy


def material(name, rgb, alpha=1.0):
    mat = bpy.data.materials.new(name)
    mat.diffuse_color = (*rgb, alpha)
    mat.use_nodes = True
    node = mat.node_tree.nodes.get('Principled BSDF')
    node.inputs['Base Color'].default_value = (*rgb, 1)
    node.inputs['Roughness'].default_value = 0.76
    node.inputs['Alpha'].default_value = alpha
    if alpha < 1:
        mat.surface_render_method = 'DITHERED'
    return mat


def mesh(name, vertices, faces, materials, parent, face_materials=None):
    data = bpy.data.meshes.new(name)
    data.from_pydata(vertices, [], faces)
    data.update()
    obj = bpy.data.objects.new(name, data)
    bpy.context.collection.objects.link(obj)
    for mat in materials:
        data.materials.append(mat)
    if face_materials:
        for polygon, index in zip(data.polygons, face_materials):
            polygon.material_index = index
    obj.parent = parent
    return obj


def ellipse_ring(y, width, height, z, count=8):
    return [(math.cos(i * 2 * math.pi / count) * width / 2,
             y, z + math.sin(i * 2 * math.pi / count) * height / 2)
            for i in range(count)]


def loft(name, rings, mats, parent, face_ids=None):
    count = len(rings[0])
    vertices = [vertex for ring in rings for vertex in ring]
    faces = []
    indices = []
    for station in range(len(rings) - 1):
        for side in range(count):
            faces.append((station * count + side,
                          station * count + (side + 1) % count,
                          (station + 1) * count + (side + 1) % count,
                          (station + 1) * count + side))
            indices.append(face_ids(station, side) if face_ids else 0)
    faces += [tuple(reversed(range(count))),
              tuple((len(rings) - 1) * count + i for i in range(count))]
    indices += [0, 0]
    return mesh(name, vertices, faces, mats, parent, indices)


def body(spec, root, palette, span, length):
    width = span * spec['shape']['fuselage_width']
    stations = [(-0.50, 0.20, 0.12), (-0.39, 0.55, 0.52),
                (-0.22, 0.84, 0.78), (0.00, 1.0, 1.0),
                (0.24, 0.87, 0.78), (0.39, 0.68, 0.58),
                (0.49, 0.33, 0.38), (0.53, 0.13, 0.20)]
    if spec['id'] == 'bf109-e4':
        stations[-2] = (0.49, 0.26, 0.30)
        stations[-1] = (0.56, 0.07, 0.12)
    rings = [ellipse_ring(frac * length, width * w, 0.46 * h, 0.12) for frac, w, h in stations]
    loft('sectioned fuselage', rings, [palette['body'], palette['shadow'], palette['belly']],
         root, lambda station, side: 2 if side in (5, 6, 7) else (1 if side in (2, 3) and station % 2 else 0))


def wing(spec, root, palette, span, length):
    stations = spec['shape']['wing_sections']
    for sign in (-1, 1):
        verts = []
        for xf, leading, trailing, z in stations:
            x = sign * span * xf
            yy0, yy1 = leading * length, trailing * length
            thickness = 0.10 if spec['id'] != 'ju87-b2' else 0.13
            verts.extend([(x, yy0, z), (x, yy1, z - 0.03),
                          (x, yy0, z - thickness), (x, yy1, z - thickness)])
        faces, materials = [], []
        for i in range(len(stations) - 1):
            k = i * 4
            faces += [(k, k + 4, k + 5, k + 1),
                      (k + 2, k + 3, k + 7, k + 6),
                      (k, k + 2, k + 6, k + 4),
                      (k + 1, k + 5, k + 7, k + 3)]
            materials += [i % 2 if spec['shape']['camouflage'] else 0, 2, 0, 1]
        faces += [(0, 1, 3, 2), tuple(range(len(verts) - 4, len(verts)))]
        materials += [0, 0]
        mesh('profiled wing ' + str(sign), verts, faces,
             [palette['wing'], palette['body'], palette['belly']], root, materials)
    # Broad horizontal stabiliser with tapered tips.
    ty = -0.39 * length
    for sign in (-1, 1):
        x0, x1 = sign * span * 0.045, sign * span * spec['shape']['tail_span'] / 2
        verts = [(x0, ty + 0.13 * length, 0.20), (x1, ty + 0.07 * length, 0.23),
                 (x1, ty - 0.07 * length, 0.23), (x0, ty - 0.12 * length, 0.20),
                 (x0, ty + 0.13 * length, 0.13), (x1, ty + 0.07 * length, 0.16),
                 (x1, ty - 0.07 * length, 0.16), (x0, ty - 0.12 * length, 0.13)]
        mesh('tailplane ' + str(sign), verts,
             [(0, 1, 2, 3), (4, 7, 6, 5), (0, 4, 5, 1),
              (1, 5, 6, 2), (2, 6, 7, 3)],
             [palette['body'], palette['belly']], root, [0, 1, 0, 0, 0])
    fin = [(0, ty - 0.12 * length, 0.12), (0, ty + 0.12 * length, 0.12),
           (0, ty - 0.13 * length, 0.62), (0, ty + 0.04 * length, 0.48)]
    mesh('tapered fin', fin, [(0, 1, 3, 2)], [palette['shadow']], root)


def canopy(spec, root, palette, span, length):
    centre = spec['shape']['canopy_center_y'] * length
    extent = spec['shape']['canopy_length'] * length / 2
    width = span * spec['shape']['fuselage_width'] * 0.68
    stations = [(-1.0, 0.20, 0.36), (-0.55, 0.78, 0.48),
                (0.0, 1.0, 0.55), (0.55, 0.78, 0.48), (1.0, 0.20, 0.36)]
    rings = [ellipse_ring(centre + t * extent, width * w, 0.22, z, 8)
             for t, w, z in stations]
    loft('faceted canopy glass', rings, [palette['glass']], root)
    # Frame ribs are narrow quadrilateral meshes, not stretched cubes.
    for t, w, z in stations[1:4]:
        y = centre + t * extent
        hw = width * w / 2
        verts = [(-hw, y - 0.018, z), (hw, y - 0.018, z),
                 (hw, y + 0.018, z + 0.015), (-hw, y + 0.018, z + 0.015)]
        mesh('canopy frame', verts, [(0, 1, 2, 3)], [palette['shadow']], root)


def tapered_pod(name, x, y, z, width, length, mat, parent):
    rings = [ellipse_ring(y - length * 0.55, width * 0.20, 0.12, z),
             ellipse_ring(y - length * 0.20, width, 0.30, z),
             ellipse_ring(y + length * 0.25, width * 0.86, 0.27, z),
             ellipse_ring(y + length * 0.55, width * 0.10, 0.10, z)]
    rings = [[(vx + x, vy, vz) for vx, vy, vz in ring] for ring in rings]
    loft(name, rings, [mat], parent)


def gear_and_radiators(spec, root, palette, span, length):
    if spec['shape']['fixed_gear']:
        for sign in (-1, 1):
            tapered_pod('fixed spatted landing gear', sign * span * 0.22,
                        -0.09 * length, -0.08, 0.36, 0.98, palette['body'], root)
            # Dive brake: slim ruled surface behind each outer wing.
            x0, x1 = sign * span * 0.28, sign * span * 0.44
            y = -0.16 * length
            mesh('dive brake', [(x0, y, 0.02), (x1, y + 0.03, 0.17),
                                (x1, y - 0.05, 0.17), (x0, y - 0.07, 0.02)],
                 [(0, 1, 2, 3)], [palette['shadow']], root)
    if spec['shape']['radiator']:
        xs = (-span * 0.16, span * 0.16) if spec['id'] == 'bf109-e4' else (0,)
        for x in xs:
            tapered_pod('radiator housing', x, -0.13 * length, -0.06,
                        0.29, 0.62, palette['shadow'], root)


def disc(name, x, y, z, radius, mat, parent):
    vertices = [(x + radius * math.cos(i * math.pi / 12),
                 y + radius * math.sin(i * math.pi / 12), z) for i in range(24)]
    return mesh(name, vertices, [tuple(range(24))], [mat], parent)


def markings(spec, root, palette, span, length):
    for sign in (-1, 1):
        x = sign * span * 0.32
        y = 0.025 * length
        z = 0.39 if spec['id'] != 'ju87-b2' else 0.13
        if spec['id'] == 'hurricane-mk1':
            for radius, mat in ((0.34, palette['blue']), (0.25, palette['white']),
                                (0.16, palette['red'])):
                disc('RAF roundel', x, y, z, radius, mat, root)
                z += 0.005
        else:
            # Black Balkenkreuz with a light edge; no political emblem.
            w, h = 0.25, 0.08
            for mat, scale in ((palette['white'], 1.25), (palette['shadow'], 1.0)):
                s = scale
                mesh('wing cross', [(x - w*s, y - h*s, z), (x + w*s, y - h*s, z),
                                    (x + w*s, y + h*s, z), (x - w*s, y + h*s, z),
                                    (x - h*s, y - w*s, z), (x + h*s, y - w*s, z),
                                    (x + h*s, y + w*s, z), (x - h*s, y + w*s, z)],
                     [(0, 1, 2, 3), (4, 5, 6, 7)], [mat], root)
                z += 0.009


def propeller(spec, root, palette, span, length):
    y = length * (0.56 if spec['id'] == 'bf109-e4' else 0.52)
    spinner = [ellipse_ring(y - 0.09, 0.26, 0.22, 0.11),
               ellipse_ring(y + 0.12, 0.18, 0.17, 0.11),
               ellipse_ring(y + 0.28, 0.025, 0.035, 0.11)]
    loft('streamlined propeller spinner', spinner, [palette['spinner']], root)
    phases = []
    for phase, count in enumerate((3, 6, 9)):
        bpy.ops.object.empty_add(location=(0, y, 0))
        group = bpy.context.object
        group.name = 'propeller phase ' + str(phase)
        group.parent = root
        radius = 0.43 if spec['id'] != 'ju87-b2' else 0.49
        for blade in range(count):
            theta = blade * 2 * math.pi / count
            radial = (math.cos(theta), math.sin(theta))
            tangent = (-radial[1], radial[0])
            vertices = []
            for rr, width in ((0.09, 0.06), (radius * 0.72, 0.13), (radius, 0.015)):
                cx, cy = radial[0] * rr, radial[1] * rr
                vertices.extend([(cx + tangent[0] * width, cy + tangent[1] * width, 0.15),
                                 (cx - tangent[0] * width, cy - tangent[1] * width, 0.15)])
            mesh('curved propeller blade', vertices,
                 [(0, 1, 3, 2), (2, 3, 5, 4)],
                 [palette['prop'] if phase == 0 else palette['prop_blur']], group)
        phases.append(group)
    return phases


def make_aircraft(spec):
    span = 6.0
    length = span * spec['dimensions_m']['length'] / spec['dimensions_m']['span']
    if spec['id'] == 'hurricane-mk1':
        colors = {'body': (0.34, 0.29, 0.17), 'wing': (0.12, 0.25, 0.17),
                  'shadow': (0.11, 0.14, 0.10), 'belly': (0.60, 0.66, 0.56)}
    elif spec['id'] == 'bf109-e4':
        colors = {'body': (0.29, 0.34, 0.31), 'wing': (0.20, 0.25, 0.22),
                  'shadow': (0.07, 0.09, 0.08), 'belly': (0.49, 0.61, 0.67)}
    else:
        colors = {'body': (0.18, 0.25, 0.18), 'wing': (0.10, 0.18, 0.14),
                  'shadow': (0.07, 0.10, 0.08), 'belly': (0.50, 0.59, 0.60)}
    p = {name: material(spec['id'] + '-' + name, rgb) for name, rgb in colors.items()}
    p.update({'glass': material(spec['id'] + '-glass', (0.11, 0.28, 0.35)),
              'spinner': material(spec['id'] + '-spinner', (0.50, 0.50, 0.43)),
              'prop': material(spec['id'] + '-prop', (0.07, 0.08, 0.08)),
              'prop_blur': material(spec['id'] + '-prop-blur', (0.13, 0.14, 0.14), 0.48),
              'blue': material(spec['id'] + '-blue', (0.07, 0.14, 0.40)),
              'white': material(spec['id'] + '-white', (0.85, 0.83, 0.76)),
              'red': material(spec['id'] + '-red', (0.52, 0.06, 0.04))})
    bpy.ops.object.empty_add(location=(0, 0, 0))
    root = bpy.context.object
    root.name = spec['designation']
    body(spec, root, p, span, length)
    wing(spec, root, p, span, length)
    canopy(spec, root, p, span, length)
    gear_and_radiators(spec, root, p, span, length)
    markings(spec, root, p, span, length)
    phases = propeller(spec, root, p, span, length)
    return root, phases
