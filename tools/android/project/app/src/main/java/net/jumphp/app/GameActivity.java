package net.jumphp.app;

import org.libsdl.app.SDLActivity;

/**
 * A JumPHP game: SDL's activity, whose SDL_main (jni/src/main.c) runs PHP on the game. SDL2 is a library of its own;
 * SDL_image, SDL_mixer, SDL_ttf, OpenAL and PHP are in libmain.so.
 */
public class GameActivity extends SDLActivity {
    @Override
    protected String[] getLibraries() {
        return new String[] {"SDL2", "main"};
    }

    @Override
    protected void onDestroy() {
        super.onDestroy();
        // PHP keeps global state: the next launch starts a fresh process
        System.exit(0);
    }
}
