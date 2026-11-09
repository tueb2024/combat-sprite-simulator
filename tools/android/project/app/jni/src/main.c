/*
 * A JumPHP game on Android: SDL's activity calls SDL_main (this), which runs PHP (the embed SAPI, JumPHP's
 * extensions inside) on the game. No browser, no WebView.
 *
 * The game is in the APK as assets/game.tar (+ assets/game.id: its build). The first launch of a build unpacks it
 * into the app's private storage, in a folder of its own (the builds before it removed), then PHP boots it as the
 * desktop's app.php does. Saves and options: <storage>/var (JUMPHP_VAR). PHP's output and errors: logcat, tag
 * JumPHP (adb logcat -s JumPHP).
 */
#define _GNU_SOURCE /* as PHP was built: its headers use mempcpy() */
#include <SDL.h>
#include <android/log.h>
#include <dirent.h>
#include <errno.h>
#include <ftw.h>
#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

#include <sapi/embed/php_embed.h>

#define TAG "JumPHP"

/* stdout and stderr (PHP's output, its errors) to logcat, line by line */
static int log_pipe[2];

static void *log_thread(void *unused)
{
    char buf[1024];
    FILE *in = fdopen(log_pipe[0], "r");
    (void)unused;
    while (in && fgets(buf, sizeof buf, in)) {
        size_t n = strlen(buf);
        if (n > 0 && buf[n - 1] == '\n') {
            buf[n - 1] = '\0';
        }
        __android_log_write(ANDROID_LOG_INFO, TAG, buf);
    }
    return NULL;
}

static void log_to_logcat(void)
{
    pthread_t t;
    setvbuf(stdout, NULL, _IOLBF, 0);
    setvbuf(stderr, NULL, _IONBF, 0);
    if (pipe(log_pipe) == 0) {
        dup2(log_pipe[1], STDOUT_FILENO);
        dup2(log_pipe[1], STDERR_FILENO);
        pthread_create(&t, NULL, log_thread, NULL);
        pthread_detach(t);
    }
}

/* rm -rf */
static int rm_entry(const char *path, const struct stat *st, int flag, struct FTW *ftw)
{
    (void)st;
    (void)flag;
    (void)ftw;
    remove(path);
    return 0;
}

static void rm_rf(const char *path)
{
    nftw(path, rm_entry, 16, FTW_DEPTH | FTW_PHYS);
}

/* mkdir -p of the folders above a file */
static void mkdir_parents(char *path)
{
    char *p;
    for (p = strchr(path + 1, '/'); p; p = strchr(p + 1, '/')) {
        *p = '\0';
        mkdir(path, 0700);
        *p = '/';
    }
}

static unsigned long long tar_number(const char *field, size_t len)
{
    unsigned long long n = 0;
    size_t i;
    for (i = 0; i < len && field[i] == ' '; i++) {
    }
    for (; i < len && field[i] >= '0' && field[i] <= '7'; i++) {
        n = n * 8 + (unsigned long long)(field[i] - '0');
    }
    return n;
}

#define TAR_PADDED(size) (((size) + 511) & ~511ULL)

/*
 * An asset tar (ustar, GNU long names: tar --format=gnu) unpacked into a folder: files and folders, nothing else
 * (no links: stage-game.sh packs with tar -h).
 */
static int untar_asset(const char *name, const char *to)
{
    static char buf[1 << 16];
    char block[512], path[4096], longname[4096] = "", shortname[260];
    SDL_RWops *in = SDL_RWFromFile(name, "rb");
    int ok = 0;
    if (!in) {
        return 0;
    }
    mkdir(to, 0700);
    while (SDL_RWread(in, block, 1, 512) == 512) {
        unsigned long long size, left;
        char type = block[156];
        const char *entry;
        if (block[0] == '\0') {
            ok = 1; /* the end */
            break;
        }
        size = tar_number(block + 124, 12);
        if (type == 'L') { /* the next entry's long name */
            size_t n = size < sizeof longname - 1 ? (size_t)size : sizeof longname - 1;
            if (SDL_RWread(in, longname, 1, n) != n) {
                break;
            }
            longname[n] = '\0';
            SDL_RWseek(in, (Sint64)(TAR_PADDED(size) - n), RW_SEEK_CUR);
            continue;
        }
        if (longname[0]) {
            entry = longname;
        } else if (memcmp(block + 257, "ustar", 5) == 0 && block[345]) {
            snprintf(shortname, sizeof shortname, "%.155s/%.100s", block + 345, block);
            entry = shortname;
        } else {
            snprintf(shortname, sizeof shortname, "%.100s", block);
            entry = shortname;
        }
        while (entry[0] == '.' && entry[1] == '/') {
            entry += 2;
        }
        if (strstr(entry, "../")) { /* never outside the folder */
            break;
        }
        snprintf(path, sizeof path, "%s/%s", to, entry);
        longname[0] = '\0';
        if (type == '5') {
            mkdir_parents(path);
            mkdir(path, 0700);
        } else if ((type == '0' || type == '\0') && entry[0]) {
            FILE *out;
            mkdir_parents(path);
            if (!(out = fopen(path, "wb"))) {
                __android_log_print(ANDROID_LOG_ERROR, TAG, "cannot write %s: %s", path, strerror(errno));
                break;
            }
            for (left = size; left > 0;) {
                size_t n = left < sizeof buf ? (size_t)left : sizeof buf;
                if (SDL_RWread(in, buf, 1, n) != n || fwrite(buf, 1, n, out) != n) {
                    break;
                }
                left -= n;
            }
            fclose(out);
            if (left > 0) {
                break;
            }
            SDL_RWseek(in, (Sint64)(TAR_PADDED(size) - size), RW_SEEK_CUR);
            continue;
        }
        SDL_RWseek(in, (Sint64)TAR_PADDED(size), RW_SEEK_CUR); /* a folder, a pax header: no data kept */
    }
    SDL_RWclose(in);
    return ok;
}

/* the game of this build in <storage>/game-<build>, unpacked once; the builds before it removed */
static int unpack_game(const char *storage, const char *root)
{
    char tmp[1100], marker[1100], old[2048];
    DIR *dir;
    struct dirent *e;
    FILE *f;
    snprintf(marker, sizeof marker, "%s/.unpacked", root);
    if (access(marker, F_OK) == 0) {
        return 1;
    }
    __android_log_print(ANDROID_LOG_INFO, TAG, "unpacking the game to %s", root);
    snprintf(tmp, sizeof tmp, "%s.tmp", root);
    rm_rf(tmp);
    if (!untar_asset("game.tar", tmp)) {
        __android_log_print(ANDROID_LOG_ERROR, TAG, "cannot unpack the game (assets/game.tar)");
        return 0;
    }
    snprintf(marker, sizeof marker, "%s/.unpacked", tmp);
    if ((f = fopen(marker, "w"))) {
        fclose(f);
    }
    rm_rf(root);
    if (rename(tmp, root) != 0) {
        __android_log_print(ANDROID_LOG_ERROR, TAG, "cannot move the game to %s: %s", root, strerror(errno));
        return 0;
    }
    if ((dir = opendir(storage))) {
        while ((e = readdir(dir))) {
            snprintf(old, sizeof old, "%s/%s", storage, e->d_name);
            if (strncmp(e->d_name, "game-", 5) == 0 && strcmp(old, root) != 0) {
                rm_rf(old);
            }
        }
        closedir(dir);
    }
    return 1;
}

/* the PHP that boots the game (as the desktop's app.php) */
static const char *BOOT =
    "$root = getenv('JUMPHP_ANDROID_ROOT');\n"
    "chdir($root);\n"
    "require \"$root/vendor/autoload.php\";\n"
    "exit(JumPHP\\Kernel\\Application::boot($root)->run());\n";

/*
 * PHP's settings before it starts: opcache's lock file in the app's own temp folder (there is no /tmp); HTTPS's CA
 * certificates, the game's (tools/stage-game.sh): Android's own are not in OpenSSL's format, and OpenSSL reads no
 * SSL_CERT_FILE in an app (a process started by the zygote counts as setuid)
 */
static char tmp_dir[1024], ca_file[1100];

static void ini_set_default(HashTable *ini, const char *name, const char *value)
{
    zval v;
    ZVAL_NEW_STR(&v, zend_string_init(value, strlen(value), 1));
    zend_hash_str_update(ini, name, strlen(name), &v);
}

static void ini_defaults(HashTable *ini)
{
    ini_set_default(ini, "opcache.lockfile_path", tmp_dir);
    ini_set_default(ini, "memory_limit", "1G");
    ini_set_default(ini, "sys_temp_dir", tmp_dir);
    ini_set_default(ini, "openssl.cafile", ca_file);
    ini_set_default(ini, "error_reporting", "32767"); /* E_ALL */
}

int main(int argc, char *argv[])
{
    char root[1024], var[1024], tmp[1024];
    const char *storage;
    char *build;
    char *php_argv[] = {"jumphp", NULL};
    int status = 0;
    (void)argc;
    (void)argv;

    log_to_logcat();
    storage = SDL_AndroidGetInternalStoragePath();
    if (!storage) {
        __android_log_print(ANDROID_LOG_ERROR, TAG, "no internal storage: %s", SDL_GetError());
        return 1;
    }
    build = SDL_LoadFile("game.id", NULL);
    snprintf(root, sizeof root, "%s/game-%s", storage, build ? build : "0");
    snprintf(var, sizeof var, "%s/var", storage);
    snprintf(tmp, sizeof tmp, "%s/tmp", storage);
    SDL_free(build);
    mkdir(var, 0700);
    mkdir(tmp, 0700);
    if (!unpack_game(storage, root)) {
        return 1;
    }

    setenv("JUMPHP_ANDROID_ROOT", root, 1);
    setenv("JUMPHP_VAR", var, 1);
    setenv("JUMPHP_MOBILE", "1", 1);
    setenv("JUMPHP_TOUCH_CONTROLS", "1", 1);
    setenv("JUMPHP_ANDROID", "1", 1);
    setenv("HOME", storage, 1);
    setenv("TMPDIR", tmp, 1);

    snprintf(tmp_dir, sizeof tmp_dir, "%s", tmp);
    snprintf(ca_file, sizeof ca_file, "%s/cacert.pem", root);
    php_embed_module.ini_defaults = ini_defaults;
    PHP_EMBED_START_BLOCK(1, php_argv)
        /* the request has started (as php_execute_script says): errors are the script's, not the startup's */
        PG(during_request_startup) = 0;
        if (zend_eval_string((char *)BOOT, NULL, "jumphp-android") == FAILURE) {
            __android_log_write(ANDROID_LOG_ERROR, TAG, "the boot script did not run");
        }
        status = EG(exit_status);
    PHP_EMBED_END_BLOCK()

    __android_log_print(ANDROID_LOG_INFO, TAG, "the game ended (%d)", status);
    return status;
}
