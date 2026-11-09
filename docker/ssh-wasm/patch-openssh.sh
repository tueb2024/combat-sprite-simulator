#!/bin/sh
# Run in the OpenSSH source folder. A browser has no tty: the passphrase / password the page was given (JUMPHP_SSH_SECRET in the
# module's environment) answers every prompt of read_passphrase().
set -e
perl -0pi -e 's/(read_passphrase\(const char \*prompt, int flags\)\n\{\n)/$1\t{\n\t\t\/* JUMPHP_SSH_SECRETS: one secret per line, handed out in the order ssh asks (several keys, each with its passphrase) *\/\n\t\tconst char *many = getenv("JUMPHP_SSH_SECRETS");\n\t\tstatic int asked = 0;\n\t\tif (many != NULL) {\n\t\t\tchar *copy = xstrdup(many), *line = copy, *next;\n\t\t\tint n = asked++;\n\t\t\twhile (n-- > 0 && (next = strchr(line, 10)) != NULL)\n\t\t\t\tline = next + 1;\n\t\t\tif ((next = strchr(line, 10)) != NULL)\n\t\t\t\t*next = 0;\n\t\t\tline = xstrdup(line);\n\t\t\tfree(copy);\n\t\t\treturn line;\n\t\t}\n\t\tconst char *jh = getenv("JUMPHP_SSH_SECRET");\n\t\tif (jh != NULL)\n\t\t\treturn xstrdup(jh);\n\t}\n/' readpass.c
grep -q JUMPHP_SSH_SECRETS readpass.c
grep -q JUMPHP_SSH_SECRET readpass.c
