// A JumPHP desktop app (ui: webview) on an iPhone / iPad: the app's browser build (bin/jumphp build: PHP in
// WebAssembly, engine/web/jumphp-app.js doing what Tauri does on the desktop) in the bundle's web/ folder, shown by a
// WKWebView from jumphp://app/ (a scheme of its own, served by a WKURLSchemeHandler: wasm streams, IndexedDB keeps
// var/). What a page can not do goes through webkit.messageHandlers.jumphp.postMessage({op, ...}) (jumphp-app.js uses
// it when it is there): save (the system's "save to Files"), notify, open (a link in Safari), share, copy, log (the
// page's console, in the app's output). Opening files: the page's <input type=file>, answered by iOS itself.
// Built by tools/apple/build.sh (clang, no Xcode project): UIKit + WebKit + UserNotifications.
#import <UIKit/UIKit.h>
#import <WebKit/WebKit.h>
#import <UserNotifications/UserNotifications.h>

static NSString *const Scheme = @"jumphp";

@interface Files : NSObject <WKURLSchemeHandler>
@end

@implementation Files
- (NSString *)typeOf:(NSString *)ext {
    static NSDictionary *types;
    if (!types) types = @{@"html": @"text/html", @"js": @"text/javascript", @"mjs": @"text/javascript", @"css": @"text/css",
        @"json": @"application/json", @"wasm": @"application/wasm", @"png": @"image/png", @"jpg": @"image/jpeg",
        @"jpeg": @"image/jpeg", @"gif": @"image/gif", @"webp": @"image/webp", @"svg": @"image/svg+xml", @"ico": @"image/x-icon",
        @"wav": @"audio/wav", @"ogg": @"audio/ogg", @"mp3": @"audio/mpeg", @"ttf": @"font/ttf", @"woff": @"font/woff",
        @"woff2": @"font/woff2", @"txt": @"text/plain", @"webmanifest": @"application/manifest+json", @"xml": @"application/xml"};
    return types[ext.lowercaseString] ?: @"application/octet-stream";
}

- (void)webView:(WKWebView *)webView startURLSchemeTask:(id<WKURLSchemeTask>)task {
    NSString *path = task.request.URL.path ?: @"";
    while ([path hasPrefix:@"/"]) path = [path substringFromIndex:1];
    if (path.length == 0 || [path hasSuffix:@"/"]) path = [path stringByAppendingString:@"index.html"];
    NSInteger status = 200;
    NSData *data = nil;
    if ([path containsString:@".."]) status = 403;
    else {
        NSString *file = [[[NSBundle mainBundle].resourcePath stringByAppendingPathComponent:@"web"] stringByAppendingPathComponent:path];
        data = [NSData dataWithContentsOfFile:file];
        if (!data) status = 404;
    }
    NSString *type = status == 200 ? [self typeOf:path.pathExtension] : @"text/plain";
    if (!data) data = [NSData data];
    NSHTTPURLResponse *response = [[NSHTTPURLResponse alloc] initWithURL:task.request.URL statusCode:status HTTPVersion:@"HTTP/1.1"
        headerFields:@{@"Content-Type": type, @"Content-Length": [NSString stringWithFormat:@"%lu", (unsigned long)data.length],
                       @"Cache-Control": @"no-cache", @"Access-Control-Allow-Origin": @"*"}];
    [task didReceiveResponse:response];
    [task didReceiveData:data];
    [task didFinish];
}

- (void)webView:(WKWebView *)webView stopURLSchemeTask:(id<WKURLSchemeTask>)task {
}
@end

@interface AppView : UIViewController <WKScriptMessageHandler, WKNavigationDelegate, WKUIDelegate>
@property(nonatomic, strong) WKWebView *web;
@end

@implementation AppView
- (void)loadView {
    WKWebViewConfiguration *config = [WKWebViewConfiguration new];
    [config setURLSchemeHandler:[Files new] forURLScheme:Scheme];
    config.allowsInlineMediaPlayback = YES;
    config.mediaTypesRequiringUserActionForPlayback = WKAudiovisualMediaTypeNone;
    // the page's console in the app's output (tools/apple/check.sh reads it)
    NSString *console = @"['log','info','warn','error'].forEach(function (k) { var f = console[k]; console[k] = function () {"
        "try { webkit.messageHandlers.jumphp.postMessage({op: 'log', level: k, text: Array.prototype.map.call(arguments, String).join(' ')}); } catch (e) {}"
        "return f.apply(console, arguments); }; });"
        "addEventListener('error', function (e) { console.error(e.message + ' (' + e.filename + ':' + e.lineno + ')'); });";
    [config.userContentController addUserScript:[[WKUserScript alloc] initWithSource:console
        injectionTime:WKUserScriptInjectionTimeAtDocumentStart forMainFrameOnly:NO]];
    [config.userContentController addScriptMessageHandler:self name:@"jumphp"];
    self.web = [[WKWebView alloc] initWithFrame:CGRectZero configuration:config];
    self.web.navigationDelegate = self;
    self.web.UIDelegate = self;
    self.web.scrollView.contentInsetAdjustmentBehavior = UIScrollViewContentInsetAdjustmentNever;
    if (@available(iOS 16.4, *)) self.web.inspectable = YES;
    self.view = self.web;
}

- (void)viewDidLoad {
    [super viewDidLoad];
    [self.web loadRequest:[NSURLRequest requestWithURL:[NSURL URLWithString:[Scheme stringByAppendingString:@"://app/index.html"]]]];
}

// links out of the app: Safari (or the app of mailto: / tel:)
- (void)webView:(WKWebView *)webView decidePolicyForNavigationAction:(WKNavigationAction *)action
    decisionHandler:(void (^)(WKNavigationActionPolicy))decide {
    NSURL *url = action.request.URL;
    if ([url.scheme isEqualToString:Scheme] || [url.scheme isEqualToString:@"about"] || [url.scheme isEqualToString:@"blob"]
        || [url.scheme isEqualToString:@"data"]) {
        decide(WKNavigationActionPolicyAllow);
        return;
    }
    [self openUrl:url.absoluteString];
    decide(WKNavigationActionPolicyCancel);
}

- (void)openUrl:(NSString *)text {
    NSURL *url = [NSURL URLWithString:text];
    if (!url || ![@[@"http", @"https", @"mailto", @"tel"] containsObject:url.scheme.lowercaseString]) return;
    [[UIApplication sharedApplication] openURL:url options:@{} completionHandler:nil];
}

// window.alert / confirm / prompt (a WKWebView shows nothing without them)
- (void)webView:(WKWebView *)webView runJavaScriptAlertPanelWithMessage:(NSString *)message initiatedByFrame:(WKFrameInfo *)frame
    completionHandler:(void (^)(void))done {
    UIAlertController *a = [UIAlertController alertControllerWithTitle:nil message:message preferredStyle:UIAlertControllerStyleAlert];
    [a addAction:[UIAlertAction actionWithTitle:@"OK" style:UIAlertActionStyleDefault handler:^(UIAlertAction *x) { done(); }]];
    [self presentViewController:a animated:YES completion:nil];
}

- (void)webView:(WKWebView *)webView runJavaScriptConfirmPanelWithMessage:(NSString *)message initiatedByFrame:(WKFrameInfo *)frame
    completionHandler:(void (^)(BOOL))done {
    UIAlertController *a = [UIAlertController alertControllerWithTitle:nil message:message preferredStyle:UIAlertControllerStyleAlert];
    [a addAction:[UIAlertAction actionWithTitle:@"Cancel" style:UIAlertActionStyleCancel handler:^(UIAlertAction *x) { done(NO); }]];
    [a addAction:[UIAlertAction actionWithTitle:@"OK" style:UIAlertActionStyleDefault handler:^(UIAlertAction *x) { done(YES); }]];
    [self presentViewController:a animated:YES completion:nil];
}

- (void)webView:(WKWebView *)webView runJavaScriptTextInputPanelWithPrompt:(NSString *)prompt defaultText:(NSString *)text
    initiatedByFrame:(WKFrameInfo *)frame completionHandler:(void (^)(NSString *))done {
    UIAlertController *a = [UIAlertController alertControllerWithTitle:nil message:prompt preferredStyle:UIAlertControllerStyleAlert];
    [a addTextFieldWithConfigurationHandler:^(UITextField *field) { field.text = text; }];
    [a addAction:[UIAlertAction actionWithTitle:@"Cancel" style:UIAlertActionStyleCancel handler:^(UIAlertAction *x) { done(nil); }]];
    [a addAction:[UIAlertAction actionWithTitle:@"OK" style:UIAlertActionStyleDefault handler:^(UIAlertAction *x) {
        done(a.textFields.firstObject.text);
    }]];
    [self presentViewController:a animated:YES completion:nil];
}

// webkit.messageHandlers.jumphp: what engine/web/jumphp-app.js asks of the phone
- (void)userContentController:(WKUserContentController *)controller didReceiveScriptMessage:(WKScriptMessage *)message {
    if (![message.body isKindOfClass:[NSDictionary class]]) return;
    NSDictionary *m = message.body;
    NSString *op = [m[@"op"] description];
    NSString *(^str)(NSString *) = ^NSString *(NSString *key) { id v = m[key]; return v && v != [NSNull null] ? [v description] : @""; };
    if ([op isEqualToString:@"log"]) {
        NSLog(@"[%@] %@", str(@"level"), str(@"text"));
    } else if ([op isEqualToString:@"open"]) {
        [self openUrl:str(@"url")];
    } else if ([op isEqualToString:@"copy"]) {
        [UIPasteboard generalPasteboard].string = str(@"text");
    } else if ([op isEqualToString:@"share"]) {
        NSMutableArray *items = [NSMutableArray array];
        if (str(@"text").length) [items addObject:str(@"text")];
        NSURL *url = [NSURL URLWithString:str(@"url")];
        if (str(@"url").length && url) [items addObject:url];
        if (!items.count) return;
        UIActivityViewController *share = [[UIActivityViewController alloc] initWithActivityItems:items applicationActivities:nil];
        share.popoverPresentationController.sourceView = self.view;
        share.popoverPresentationController.sourceRect = CGRectMake(CGRectGetMidX(self.view.bounds), CGRectGetMidY(self.view.bounds), 1, 1);
        [self presentViewController:share animated:YES completion:nil];
    } else if ([op isEqualToString:@"save"]) {
        // the bytes in a temporary file of that name, then the system's "save to Files"
        NSData *data = [[NSData alloc] initWithBase64EncodedString:str(@"data") options:0];
        NSString *name = [str(@"name") lastPathComponent];
        if (!data || name.length == 0) return;
        NSURL *file = [[NSURL fileURLWithPath:NSTemporaryDirectory()] URLByAppendingPathComponent:name];
        if (![data writeToURL:file atomically:YES]) return;
        UIDocumentPickerViewController *picker;
        if (@available(iOS 14.0, *)) picker = [[UIDocumentPickerViewController alloc] initForExportingURLs:@[file] asCopy:YES];
        [self presentViewController:picker animated:YES completion:nil];
    } else if ([op isEqualToString:@"notify"]) {
        NSString *title = str(@"title"), *body = str(@"body");
        UNUserNotificationCenter *center = [UNUserNotificationCenter currentNotificationCenter];
        [center requestAuthorizationWithOptions:UNAuthorizationOptionAlert | UNAuthorizationOptionSound
            completionHandler:^(BOOL granted, NSError *error) {
                if (!granted) return;
                UNMutableNotificationContent *content = [UNMutableNotificationContent new];
                content.title = title;
                content.body = body;
                [center addNotificationRequest:[UNNotificationRequest requestWithIdentifier:[NSUUID UUID].UUIDString content:content
                    trigger:nil] withCompletionHandler:nil];
            }];
    }
    // "exit": iOS apps do not quit themselves (Apple's rules); the page stays
}
@end

@interface AppDelegate : UIResponder <UIApplicationDelegate, UNUserNotificationCenterDelegate>
@property(nonatomic, strong) UIWindow *window;
@end

@implementation AppDelegate
- (BOOL)application:(UIApplication *)application didFinishLaunchingWithOptions:(NSDictionary *)options {
    [UNUserNotificationCenter currentNotificationCenter].delegate = self;
    self.window = [[UIWindow alloc] initWithFrame:[UIScreen mainScreen].bounds];
    self.window.rootViewController = [AppView new];
    [self.window makeKeyAndVisible];
    return YES;
}

// notifications show while the app is in front too (as on the desktop)
- (void)userNotificationCenter:(UNUserNotificationCenter *)center willPresentNotification:(UNNotification *)notification
    withCompletionHandler:(void (^)(UNNotificationPresentationOptions))done {
    if (@available(iOS 14.0, *)) done(UNNotificationPresentationOptionBanner | UNNotificationPresentationOptionSound);
}
@end

int main(int argc, char *argv[]) {
    @autoreleasepool {
        return UIApplicationMain(argc, argv, nil, NSStringFromClass([AppDelegate class]));
    }
}
