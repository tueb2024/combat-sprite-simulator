package net.jumphp.app;

import android.Manifest;
import android.app.Activity;
import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.util.Base64;
import android.util.Log;
import android.webkit.ConsoleMessage;
import android.webkit.JavascriptInterface;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.window.OnBackInvokedDispatcher;

import java.io.FileNotFoundException;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.util.HashMap;
import java.util.Map;

/**
 * A JumPHP desktop app (ui: webview) on Android: the app's browser build (bin/jumphp build: PHP in WebAssembly,
 * engine/web/jumphp-app.js doing what Tauri does on the desktop) in assets/web/, served to a WebView from
 * https://appassets.androidplatform.net/ (a secure origin, so IndexedDB keeps var/ and wasm streams). What a page
 * can not do goes through window.JumPHPAndroid (jumphp-app.js uses it when it is there): saving a file (the system's
 * "save as"), notifications, links in the browser, the clipboard, sharing, quitting. Opening files: the page's
 * &lt;input type=file&gt;, answered by the system's file picker. Logs: adb logcat -s JumPHP.
 */
public class WebAppActivity extends Activity {
    static final String TAG = "JumPHP";
    static final String HOST = "appassets.androidplatform.net";
    static final String HOME = "https://" + HOST + "/index.html";
    static final int PICK = 1, SAVE = 2;
    static final String CHANNEL = "app";

    static final Map<String, String> TYPES = new HashMap<>();
    static {
        String[][] t = {{"html", "text/html"}, {"js", "text/javascript"}, {"mjs", "text/javascript"}, {"css", "text/css"},
            {"json", "application/json"}, {"wasm", "application/wasm"}, {"png", "image/png"}, {"jpg", "image/jpeg"},
            {"jpeg", "image/jpeg"}, {"gif", "image/gif"}, {"webp", "image/webp"}, {"svg", "image/svg+xml"},
            {"ico", "image/x-icon"}, {"wav", "audio/wav"}, {"ogg", "audio/ogg"}, {"mp3", "audio/mpeg"},
            {"ttf", "font/ttf"}, {"woff", "font/woff"}, {"woff2", "font/woff2"}, {"txt", "text/plain"},
            {"webmanifest", "application/manifest+json"}, {"xml", "application/xml"}};
        for (String[] p : t) TYPES.put(p[0], p[1]);
    }

    WebView web;
    ValueCallback<Uri[]> picking;
    byte[] saving;

    @Override
    protected void onCreate(Bundle state) {
        super.onCreate(state);
        web = new WebView(this);
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        web.setWebViewClient(new WebViewClient() {
            @Override
            public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                return HOST.equals(request.getUrl().getHost()) ? asset(request.getUrl()) : null;
            }

            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (HOST.equals(request.getUrl().getHost())) return false;
                openUrl(request.getUrl().toString());
                return true;
            }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
                if (picking != null) picking.onReceiveValue(null);
                picking = callback;
                Intent intent = params.createIntent();
                if (params.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE) intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
                try {
                    startActivityForResult(intent, PICK);
                } catch (ActivityNotFoundException e) {
                    picking = null;
                    return false;
                }
                return true;
            }

            @Override
            public boolean onConsoleMessage(ConsoleMessage m) {
                Log.i(TAG, m.message() + " (" + m.sourceId() + ":" + m.lineNumber() + ")");
                return true;
            }
        });
        web.addJavascriptInterface(new Bridge(), "JumPHPAndroid");
        android.widget.FrameLayout root = new android.widget.FrameLayout(this);
        root.setBackgroundColor(0xff000000);
        root.addView(web);
        setContentView(root);
        // Android 15+ draws apps edge to edge: the page goes between the status bar and the navigation bar
        root.setOnApplyWindowInsetsListener((v, insets) -> {
            if (Build.VERSION.SDK_INT >= 30) {
                android.graphics.Insets bars = insets.getInsets(android.view.WindowInsets.Type.systemBars() | android.view.WindowInsets.Type.ime());
                v.setPadding(bars.left, bars.top, bars.right, bars.bottom);
            }
            return insets;
        });
        if (Build.VERSION.SDK_INT >= 33) {
            getOnBackInvokedDispatcher().registerOnBackInvokedCallback(OnBackInvokedDispatcher.PRIORITY_DEFAULT, this::back);
        }
        if (state == null) web.loadUrl(HOME);
        else web.restoreState(state);
    }

    void back() {
        if (web.canGoBack()) web.goBack();
        else finish();
    }

    @Override
    @SuppressWarnings("deprecation")
    public void onBackPressed() {
        back();
    }

    @Override
    protected void onSaveInstanceState(Bundle state) {
        super.onSaveInstanceState(state);
        web.saveState(state);
    }

    /** A file of assets/web/ (the browser build), "/" being index.html. */
    WebResourceResponse asset(Uri url) {
        String path = url.getPath() == null ? "" : url.getPath().replaceFirst("^/+", "");
        if (path.isEmpty() || path.endsWith("/")) path += "index.html";
        if (path.contains("..")) return new WebResourceResponse("text/plain", "utf-8", 403, "Forbidden", null, null);
        String ext = path.substring(path.lastIndexOf('.') + 1).toLowerCase();
        String type = TYPES.containsKey(ext) ? TYPES.get(ext) : "application/octet-stream";
        try {
            InputStream in = getAssets().open("web/" + path);
            Map<String, String> headers = new HashMap<>();
            headers.put("Cache-Control", "no-cache");
            boolean text = type.startsWith("text/") || type.endsWith("json") || type.endsWith("xml");
            return new WebResourceResponse(type, text ? "utf-8" : null, 200, "OK", headers, in);
        } catch (FileNotFoundException e) {
            return new WebResourceResponse("text/plain", "utf-8", 404, "Not Found", null, null);
        } catch (IOException e) {
            return new WebResourceResponse("text/plain", "utf-8", 500, "Error", null, null);
        }
    }

    void openUrl(String url) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
        } catch (ActivityNotFoundException e) {
            Log.w(TAG, "nothing opens " + url);
        }
    }

    @Override
    protected void onActivityResult(int request, int result, Intent data) {
        super.onActivityResult(request, result, data);
        if (request == PICK && picking != null) {
            Uri[] uris = null;
            if (result == RESULT_OK && data != null) {
                if (data.getClipData() != null) {
                    ClipData clip = data.getClipData();
                    uris = new Uri[clip.getItemCount()];
                    for (int i = 0; i < uris.length; i++) uris[i] = clip.getItemAt(i).getUri();
                } else if (data.getData() != null) {
                    uris = new Uri[] {data.getData()};
                }
            }
            picking.onReceiveValue(uris);
            picking = null;
        } else if (request == SAVE) {
            byte[] bytes = saving;
            saving = null;
            if (result != RESULT_OK || data == null || data.getData() == null || bytes == null) return;
            try (OutputStream out = getContentResolver().openOutputStream(data.getData())) {
                if (out != null) out.write(bytes);
            } catch (IOException e) {
                Log.e(TAG, "saving failed: " + e);
            }
        }
    }

    /** window.JumPHPAndroid, for engine/web/jumphp-app.js. Called on a WebView thread: the UI work goes to the main one. */
    final class Bridge {
        @JavascriptInterface
        public void save(String name, String base64) {
            byte[] bytes = Base64.decode(base64, Base64.DEFAULT);
            runOnUiThread(() -> {
                saving = bytes;
                Intent intent = new Intent(Intent.ACTION_CREATE_DOCUMENT);
                intent.addCategory(Intent.CATEGORY_OPENABLE);
                intent.setType("*/*");
                intent.putExtra(Intent.EXTRA_TITLE, name);
                try {
                    startActivityForResult(intent, SAVE);
                } catch (ActivityNotFoundException e) {
                    saving = null;
                }
            });
        }

        @JavascriptInterface
        public void notify(String title, String body) {
            runOnUiThread(() -> {
                if (Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
                    requestPermissions(new String[] {Manifest.permission.POST_NOTIFICATIONS}, 3);
                    return;
                }
                NotificationManager nm = getSystemService(NotificationManager.class);
                nm.createNotificationChannel(new NotificationChannel(CHANNEL, getString(R.string.app_name), NotificationManager.IMPORTANCE_DEFAULT));
                nm.notify((int) (System.currentTimeMillis() & 0xffffff), new Notification.Builder(WebAppActivity.this, CHANNEL)
                    .setSmallIcon(R.mipmap.ic_launcher).setContentTitle(title).setContentText(body).setAutoCancel(true).build());
            });
        }

        @JavascriptInterface
        public void openUrl(String url) {
            if (url != null && url.matches("^(https?|mailto|tel):.*")) runOnUiThread(() -> WebAppActivity.this.openUrl(url));
        }

        @JavascriptInterface
        public void share(String text, String url) {
            runOnUiThread(() -> {
                Intent send = new Intent(Intent.ACTION_SEND);
                send.setType("text/plain");
                send.putExtra(Intent.EXTRA_TEXT, (text == null ? "" : text) + (url == null || url.isEmpty() ? "" : " " + url));
                startActivity(Intent.createChooser(send, null));
            });
        }

        @JavascriptInterface
        public void copy(String text) {
            runOnUiThread(() -> getSystemService(ClipboardManager.class).setPrimaryClip(ClipData.newPlainText("text", text)));
        }

        @JavascriptInterface
        public String paste() {
            ClipboardManager cm = getSystemService(ClipboardManager.class);
            ClipData clip = cm.getPrimaryClip();
            return clip != null && clip.getItemCount() > 0 ? String.valueOf(clip.getItemAt(0).coerceToText(WebAppActivity.this)) : "";
        }

        @JavascriptInterface
        public void exit() {
            runOnUiThread(WebAppActivity.this::finish);
        }
    }
}
