# Refaire un jeu Amstrad CPC depuis sa disquette

Pour refaire en JumPHP un jeu CPC « avec les mêmes graphismes, la même musique », il y a
**[dsk2jumphp](https://git.jumphp.net/jumphp/dsk2jumphp)** : un petit émulateur CPC 6128 sans écran qui fait
tourner le jeu original et note tout ce qu'il fait, et les outils qui en sortent les éléments du jeu. Le
remake de *Rainbow Islands* a été fait ainsi : https://git.jumphp.net/jumphp/rainbow-island.

## Installation

À côté du projet (pas dedans : on n'y met ni disquette, ni ROM, ni image mémoire) :

```bash
git clone https://git.jumphp.net/jumphp/dsk2jumphp.git ~/dsk2jumphp
make -C ~/dsk2jumphp          # un compilateur C ; Python 3 avec Pillow et numpy ; ffmpeg pour les .ogg
make -C ~/dsk2jumphp test
```

Pour Claude Code, le skill fourni lui apprend la méthode et les outils :

```bash
mkdir -p .claude/skills && ln -s ~/dsk2jumphp/.claude/skills/dsk2jumphp .claude/skills/dsk2jumphp
```

Ensuite, il suffit de demander : « refais le jeu de ~/jeux/xyz.dsk ». Les consignes complètes pour les agents
sont dans `AGENTS.md` de dsk2jumphp (et la section 12 de celui de JumPHP).

## La méthode en bref

On ne lit pas le jeu, **on le fait tourner** et on observe (détails : `docs/method.md` de dsk2jumphp).

1. **La disquette** : `tools/dsk.py info` (format, secteurs), `files` (fichiers AMSDOS : jeu lancé par le
   BASIC → démarrage avec les ROM), `sectors disk.dsk 0 41` (le secteur de boot d'un jeu qui se charge seul).
2. **Le faire tourner** : `emulator/cpc jeu.dsk run 1500 -S 50` (une capture par seconde) ; avec les ROM de
   ta machine : `-rom cpc6128.rom -urom 7:amsdos.rom -type '100:RUN"DISC\n'`. Les touches se scriptent
   (`-k '300:joy_fire+,320:joy_fire-'`).
3. **Trouver les variables** (niveau, vies, caméra, musique demandée) : deux images mémoire (`-d`) et
   `tools/cpc.py diff --from 3 --to 2`, puis `-w ADRESSE` montre le code qui les modifie.
4. **Piloter le jeu** : choisir le niveau (`-p PC:ADRESSE=VALEUR` au moment où le jeu le fixe), le rendre
   immortel ou muet pour les captures (`-F trame:ADRESSE=VALEUR` une fois le code chargé).
5. **Extraire** : `tools/cpc.py palette / screen / tiles / gfx` (sprites en PNG, `--zigzag` si une ligne sur
   deux est à l'envers), `text` (retrouve un texte quel que soit le codage des lettres), les cartes rebâties
   depuis les écritures dans la mémoire du jeu, la musique depuis le journal du processeur sonore
   (`tools/psg.py render ... musique.ogg`).
6. **Dans JumPHP** : les éléments dans `games/<id>/assets/` (PNG, JSON, Ogg), les scripts d'extraction et
   les adresses trouvées dans `games/<id>/tools/extract/` (un script qui refait `assets/` depuis la
   disquette), puis le jeu en PHP lisible, à la taille d'écran d'origine (pixels du mode 0 doublés en largeur).

## À savoir

- Les ROM du CPC ne sont pas fournies : celles d'un émulateur déjà installé suffisent (`cpc6128.rom`,
  `amsdos.rom`). Beaucoup de jeux se chargent seuls et n'en ont pas besoin.
- Ne publie que les scripts et les notes : la disquette, les ROM et les images mémoire restent chez toi ;
  les éléments extraits appartiennent aux auteurs du jeu.
- L'émulateur vise les jeux, pas les démos : pas de chronométrage au cycle près, pas de protections de
  disquette exotiques, pas de cassette.
