// JumPHP WebAssembly entry point.
//
// phpw_run(code) boots the embedded PHP, evaluates `code` (the page prelude + engine/bootstrap/wasm.php),
// then hands the PHP function jumphp_frame() to emscripten_set_main_loop(): the browser calls it once per
// requestAnimationFrame. PHP is never shut down: objects and SDL resources live between frames.
//
// phpw_restart(code) (hot reload, engine/src/Debug/HotReload.php): the PHP request ends (the game's objects,
// classes and SDL resources freed) and a new one boots the game again from its files, in the same engine and
// the same main loop: no page reload, no new WebAssembly. Returns 1 when the game booted, 0 when it did not.
#include "sapi/embed/php_embed.h"
#include <emscripten.h>
#include <stdlib.h>

#include "zend_globals_macros.h"
#include "zend_exceptions.h"

static zval frame_name, frame_retval;
static zend_fcall_info frame_fci;
static zend_fcall_info_cache frame_fcc;
static int frame_ready = 0;

static void main_loop(void)
{
    if (!frame_ready) return;

    zend_try {
        if (zend_call_function(&frame_fci, &frame_fcc) == SUCCESS) {
            zval_ptr_dtor(&frame_retval);
        } else {
            fprintf(stderr, "[jumphp] jumphp_frame() call failed\n");
        }
        if (EG(exception)) {
            zend_exception_error(EG(exception), E_WARNING);
            zend_clear_exception();
        }
    } zend_catch {
        fprintf(stderr, "[jumphp] fatal error in jumphp_frame()\n");
    } zend_end_try();
}

int main(void)
{
    return 0;
}

// evaluates the boot code, then finds jumphp_frame(): 1 when the game is ready to run its frames
static int boot(char *code)
{
    int ready = 0;
    zend_try {
        zend_eval_string(code, NULL, "jumphp boot");

        if (EG(exception)) {
            zend_exception_error(EG(exception), E_ERROR);
        } else {
            ZVAL_STRING(&frame_name, "jumphp_frame");
            if (zend_fcall_info_init(&frame_name, 0, &frame_fci, &frame_fcc, NULL, NULL) != SUCCESS) {
                fprintf(stderr, "[jumphp] jumphp_frame() is not defined: the boot script failed\n");
                zval_ptr_dtor(&frame_name);
                ZVAL_UNDEF(&frame_name);
            } else {
                frame_fci.param_count = 0;
                frame_fci.params = NULL;
                frame_fci.retval = &frame_retval;
                ready = 1;
            }
        }
    } zend_catch {
        ready = 0;
    } zend_end_try();
    return ready;
}

void EMSCRIPTEN_KEEPALIVE phpw_run(char *code)
{
    // phpinfo() as a page (the playground's console shows it as HTML); errors stay text (html_errors=0)
    php_embed_module.phpinfo_as_text = 0;
    php_embed_init(0, NULL);
    ZVAL_UNDEF(&frame_name);
    if (boot(code)) {
        frame_ready = 1;
        // one call per requestAnimationFrame; throws "unwind" to JS, which is the normal case
        emscripten_set_main_loop(main_loop, 0, 1);
    }

    php_embed_shutdown();
}

int EMSCRIPTEN_KEEPALIVE phpw_restart(char *code)
{
    frame_ready = 0;
    // the request's memory goes with it: its strings first
    zval_ptr_dtor(&frame_name);
    ZVAL_UNDEF(&frame_name);
    zend_try {
        php_request_shutdown(NULL);
    } zend_end_try();
    if (php_request_startup() == FAILURE) {
        fprintf(stderr, "[jumphp] the PHP request could not start again\n");
        return 0;
    }
    // as php_embed_init() does for the first request: no headers, output straight to the console
    SG(headers_sent) = 1;
    SG(request_info).no_headers = 1;
    frame_ready = boot(code);
    return frame_ready;
}
