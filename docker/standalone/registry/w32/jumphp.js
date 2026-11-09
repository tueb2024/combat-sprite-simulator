// JumPHP's Windows builds of the SDL, OpenGL and OpenAL extensions (prepended to each config.w32 by the registry):
// the include folders of the libraries built by StaticPHP (buildroot\include, \include\SDL2), their static
// libraries and the Windows libraries they need.
function jumphp_includes(ext) {
    var root = PHP_PHP_BUILD + '\\include';
    // + the extension's own folder (its src/*.h include its root's php_<ext>.h, as configure gives on Unix)
    ADD_FLAG('CFLAGS_' + ext.toUpperCase(), '/I "' + configure_module_dirname + '" /I "' + root + '" /I "' + root + '\\SDL2" /I "' + root + '\\AL"');
}
function jumphp_libs(ext, libs) {
    for (var i = 0; i < libs.length; i++) {
        if (!CHECK_LIB(libs[i], ext)) {
            ERROR(ext + ': ' + libs[i] + ' not found');
        }
    }
}
var JUMPHP_SDL_SYSTEM_LIBS = ['user32.lib', 'gdi32.lib', 'winmm.lib', 'imm32.lib', 'ole32.lib', 'oleaut32.lib',
    'version.lib', 'uuid.lib', 'advapi32.lib', 'setupapi.lib', 'shell32.lib', 'opengl32.lib'];
