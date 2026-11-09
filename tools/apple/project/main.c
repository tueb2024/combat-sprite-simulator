/*
 * A JumPHP game as a native Apple app (macOS, iPhone / iPad): SDL starts (on iOS through SDL2main's UIKit main,
 * which calls this as SDL_main), then PHP (the embed SAPI, JumPHP's extensions inside) runs the game. No browser,
 * no WebView.
 *
 * The game is in the bundle, as the desktop runs it: <Game>.app/game (iOS) or <Game>.app/Contents/Resources/game
 * (macOS), read-only. Saves and options: the app's Application Support folder (JUMPHP_VAR). PHP's output and
 * errors: stdout / stderr (Xcode's console, `xcrun simctl launch --console`, or the terminal on a Mac).
 */
#include <SDL.h>
#include <TargetConditionals.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>

#include <sapi/embed/php_embed.h>

#ifndef JUMPHP_GAME_ID
#define JUMPHP_GAME_ID "game"
#endif

/* the PHP that boots the game (as the desktop's app.php) */
static const char *BOOT =
    "$root = getenv('JUMPHP_APPLE_ROOT');\n"
    "chdir($root);\n"
    "require \"$root/vendor/autoload.php\";\n"
    "exit(JumPHP\\Kernel\\Application::boot($root)->run());\n";

/*
 * PHP's settings before it starts: opcache's lock file and the temporary files in the app's own temp folder (an iOS
 * app may write nowhere else); HTTPS's CA certificates, the game's (tools/stage-game.sh): Apple's own are in the
 * keychain, which OpenSSL does not read
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
    char root[1024], var[1024];
    char *base, *pref;
    const char *tmp;
    char *php_argv[] = {"jumphp", NULL};
    int status = 0;
    (void)argc;
    (void)argv;

    setvbuf(stdout, NULL, _IOLBF, 0);
    base = SDL_GetBasePath(); /* the bundle's resources: <Game>.app/ (iOS), <Game>.app/Contents/Resources/ (macOS) */
    pref = SDL_GetPrefPath("JumPHP", JUMPHP_GAME_ID); /* Application Support/JumPHP/<game>/, kept across updates */
    if (!base || !pref) {
        fprintf(stderr, "JumPHP: no bundle or no Application Support folder: %s\n", SDL_GetError());
        return 1;
    }
    snprintf(root, sizeof root, "%sgame", base);
    snprintf(var, sizeof var, "%svar", pref);
    mkdir(var, 0700);
    tmp = getenv("TMPDIR"); /* the app's own temporary folder (macOS and iOS both set it) */
    if (tmp && tmp[0]) {
        snprintf(tmp_dir, sizeof tmp_dir, "%s", tmp);
    } else {
        snprintf(tmp_dir, sizeof tmp_dir, "%stmp", pref);
        mkdir(tmp_dir, 0700);
    }
    snprintf(ca_file, sizeof ca_file, "%s/cacert.pem", root);
    SDL_free(base);
    SDL_free(pref);

    setenv("JUMPHP_APPLE_ROOT", root, 1);
    setenv("JUMPHP_VAR", var, 0); /* a test run may give its own (JUMPHP_VAR=...) */
#if TARGET_OS_IPHONE
    setenv("JUMPHP_IOS", "1", 1);
    setenv("JUMPHP_MOBILE", "1", 1);
    setenv("JUMPHP_TOUCH_CONTROLS", "1", 1);
    /* SDL's OpenGL ES renderer, as on Android: the games' own GL (gl(), opengl()) shares its context */
    setenv("SDL_RENDER_DRIVER", "opengles2", 0);
#else
    setenv("JUMPHP_MACOS", "1", 1);
    /* SDL's OpenGL renderer, as on Linux and Windows: the games' own GL (gl(), opengl()) shares its context */
    setenv("SDL_RENDER_DRIVER", "opengl", 0);
#endif

    php_embed_module.ini_defaults = ini_defaults;
    PHP_EMBED_START_BLOCK(1, php_argv)
        /* the request has started (as php_execute_script says): errors are the script's, not the startup's */
        PG(during_request_startup) = 0;
        if (zend_eval_string((char *)BOOT, NULL, "jumphp-apple") == FAILURE) {
            fprintf(stderr, "JumPHP: the boot script did not run\n");
        }
        status = EG(exit_status);
    PHP_EMBED_END_BLOCK()

    fprintf(stderr, "JumPHP: the game ended (%d)\n", status);
    return status;
}
