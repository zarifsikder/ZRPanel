<?php
/**
 * ZRPanel Web Apps — deployer for AI-generated React/TS (and other) projects.
 *
 * Supports:
 *   - ZIP upload / Git clone / existing directory deploys
 *   - Framework + package-manager auto-detection (Vite, Next, Astro, Vue,
 *     Svelte, SvelteKit, Angular, Nuxt, Remix, CRA, plain Node, static HTML)
 *   - Auto-install + auto-build via an async CLI worker
 *   - PM2 process management for server-mode apps
 *   - Path-based public serving under the panel domain (base path)
 *
 * Public request handler: wa_proxy_handle() (called from router.php).
 */

require_once __DIR__ . '/config.php';

if (!function_exists('wa_init')) {
    function wa_init() {
        static $done = false;
        if (!$done) { init_db(); $done = true; }
    }
}

// ---------------------------------------------------------------------------
//  Path / naming helpers
// ---------------------------------------------------------------------------

function wa_slugify($name) {
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = preg_replace('/-{2,}/', '-', $slug);
    $slug = substr($slug, 0, 50);
    return $slug === '' ? 'app' : $slug;
}

function wa_base_path($slug) {
    return '/' . $slug;
}

function wa_reserved_slugs() {
    return ['cpanel', 'whm', 'api', 'assets', 'login', 'logout', 'docs',
            'file-manager', 'webapps', 'index',
            'files', 'favicon'];
}

function wa_apps_dir($user) {
    $home = $user['home_dir'] ?? (dirname(__DIR__) . '/user_data/' . ($user['username'] ?? 'user'));
    return rtrim($home, '/') . '/apps';
}

function wa_app_dir($user, $app_name) {
    return wa_apps_dir($user) . '/' . basename(wa_slugify($app_name));
}

// ---------------------------------------------------------------------------
//  Internal build workspace
//
//  Android emulated storage (/storage/emulated/0) does not support symlinks
//  and the dynamic linker cannot dlopen native .node addons from it, so Node
//  toolchains must install and build on internal Termux storage. Source stays
//  on the sdcard (visible in File Manager); static output is copied back.
// ---------------------------------------------------------------------------

function wa_ws_root() {
    return (getenv('HOME') ?: '/data/data/com.termux/files/home') . '/.zenpanel/apps';
}

function wa_workspace_dir($user, $app_name) {
    return wa_ws_root() . '/' . ($user['username'] ?? 'u') . '_' . wa_slugify($app_name);
}

function wa_ws_supported() {
    $root = wa_ws_root();
    if (!is_dir($root)) @mkdir($root, 0755, true);
    $test = $root . '/.symlink-test';
    @unlink($test);
    $ok = @symlink($root, $test);
    @unlink($test);
    return $ok;
}

function wa_sync_src($src, $dst) {
    $src = rtrim($src, '/');
    @exec('rm -rf ' . escapeshellarg($dst) . ' 2>/dev/null');
    @mkdir($dst, 0755, true);
    $exclude = ['node_modules', 'dist', '.git', '.next', '.output', 'build',
                '.svelte-kit', 'build.log', '.zenpanel', 'start.sh'];
    $rdi = new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($rdi, function ($current) use ($exclude) {
        if ($current->isDir() && in_array($current->getFilename(), $exclude, true)) return false;
        return true;
    });
    $it = new RecursiveIteratorIterator($filter);
    foreach ($it as $f) {
        if ($f->isDir()) continue;
        $rel = substr($f->getPathname(), strlen($src) + 1);
        $dest = $dst . '/' . $rel;
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @copy($f->getPathname(), $dest);
    }
}

function wa_copy_dir($src, $dst) {
    $src = rtrim($src, '/');
    $dst = rtrim($dst, '/');
    @exec('rm -rf ' . escapeshellarg($dst) . ' 2>/dev/null');
    @mkdir($dst, 0755, true);
    $rdi = new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS);
    $it = new RecursiveIteratorIterator($rdi);
    foreach ($it as $f) {
        if ($f->isDir()) continue;
        $rel = substr($f->getPathname(), strlen($src) + 1);
        $dest = $dst . '/' . $rel;
        $dir = dirname($dest);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @copy($f->getPathname(), $dest);
    }
}

function wa_pm2_name($user_id, $app_name) {
    return 'wp' . (int)$user_id . '_' . wa_slugify($app_name);
}

function wa_alloc_port($user_id) {
    $db = db();
    $used = [];
    $stmt = $db->query("SELECT port FROM web_apps");
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $p) { $used[(int)$p] = true; }
    for ($port = 3000; $port <= 9000; $port++) {
        if (isset($used[$port])) continue;
        $fp = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 0.3);
        if ($fp) { fclose($fp); continue; }
        return $port;
    }
    return 3000;
}

function wa_home_of($user_id) {
    $db = db();
    $stmt = $db->prepare("SELECT id, username, home_dir FROM users WHERE id = ?");
    $stmt->execute([(int)$user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    return $u ?: null;
}

// ---------------------------------------------------------------------------
//  Framework detection
// ---------------------------------------------------------------------------

function wa_read_json($dir, $name) {
    $f = rtrim($dir, '/') . '/' . $name;
    if (!is_file($f)) return null;
    $data = @json_decode(@file_get_contents($f), true);
    return is_array($data) ? $data : null;
}

function wa_detect_pm($dir) {
    if (is_file($dir . '/pnpm-lock.yaml')) return 'pnpm';
    if (is_file($dir . '/bun.lock') || is_file($dir . '/bun.lockb')) return 'bun';
    if (is_file($dir . '/yarn.lock')) return 'yarn';
    return 'npm';
}

function wa_pm_install_cmd($pm) {
    if ($pm === 'pnpm') return 'pnpm install';
    if ($pm === 'bun') return 'bun install';
    if ($pm === 'yarn') return 'yarn install';
    return 'npm install';
}

function wa_pm_run_cmd($pm, $script, $extra = '') {
    if ($pm === 'yarn') return 'yarn run ' . $script . ($extra !== '' ? ' ' . $extra : '');
    return $pm . ' run ' . $script . ($extra !== '' ? ' -- ' . $extra : '');
}

/**
 * Build a full build command line that runs through the package manager so
 * node_modules/.bin is on PATH. Falls back to direct node invocation of the
 * framework CLI when no build script exists.
 */
function wa_build_cmd($pm, $scripts, $framework, $base) {
    $base = rtrim((string)$base, '/');
    $extra = '';
    if ($base !== '') {
        if (in_array($framework, ['vite', 'react', 'vue', 'svelte', 'astro'], true)) {
            $extra = '--base=' . $base . '/';
        } elseif ($framework === 'angular') {
            $extra = '--base-href=' . $base . '/';
        }
    }
    if (!empty($scripts['build'])) {
        return wa_pm_run_cmd($pm, 'build', $extra);
    }
    $entry = [
        'next'     => 'node node_modules/next/dist/bin/next build',
        'astro'    => 'node node_modules/astro/astro.js build',
        'nuxt'     => 'node node_modules/nuxt/bin/nuxt.mjs build',
        'remix'    => 'node node_modules/@remix-run/dev/dist/cli.js build',
        'sveltekit' => 'node node_modules/@sveltejs/kit/svelte-kit.js build',
        'angular'  => 'node node_modules/@angular/cli/bin/ng.js build',
    ];
    if (isset($entry[$framework])) {
        return $entry[$framework] . ($extra !== '' ? ' ' . $extra : '');
    }
    if (in_array($framework, ['vite', 'react', 'vue', 'svelte', 'cra'], true)) {
        return 'node node_modules/vite/bin/vite.js build' . ($extra !== '' ? ' ' . $extra : '');
    }
    return wa_pm_run_cmd($pm, 'build', '');
}

/**
 * Detect framework and derive build/start settings.
 * Returns null when the directory has no recognizable project.
 */
function wa_detect($dir, $base_path = '') {
    $pkg = wa_read_json($dir, 'package.json');
    $hasNode = is_file($dir . '/package.json');

    if (!$hasNode) {
        if (is_file($dir . '/index.html') || is_file($dir . '/public/index.html')) {
            return [
                'framework' => 'static', 'mode' => 'static',
                'package_manager' => 'npm', 'install_command' => null,
                'build_command' => null, 'start_command' => null,
                'static_dir' => '.', 'needs_rewrite' => true,
            ];
        }
        return null;
    }

    $deps = [];
    foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $k) {
        if (isset($pkg[$k]) && is_array($pkg[$k])) {
            foreach (array_keys($pkg[$k]) as $dk) $deps[$dk] = true;
        }
    }
    $scripts = (isset($pkg['scripts']) && is_array($pkg['scripts'])) ? $pkg['scripts'] : [];
    $main = $pkg['main'] ?? null;

    $pm = wa_detect_pm($dir);
    $install = wa_pm_install_cmd($pm);
    $out_dir = 'dist';
    $needs_rewrite = false;
    $base = $base_path === '' ? '' : rtrim($base_path, '/') . '/';

    $has = function ($name) use ($deps) { return isset($deps[$name]); };

    // ---- Next.js (server, basePath config) ----
    if ($has('next')) {
        return [
            'framework' => 'next', 'mode' => 'server',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => wa_build_cmd($pm, $scripts, 'next', $base),
            'start_command' => 'next start',
            'static_dir' => '.next', 'needs_rewrite' => false,
        ];
    }

    // ---- Nuxt ----
    if ($has('nuxt') || $has('nuxt3')) {
        return [
            'framework' => 'nuxt', 'mode' => 'server',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => wa_build_cmd($pm, $scripts, 'nuxt', $base),
            'start_command' => 'nuxt start',
            'static_dir' => '.output', 'needs_rewrite' => false,
        ];
    }

    // ---- Remix ----
    if ($has('@remix-run/react')) {
        $start = $scripts['start'] ?? 'remix-serve build';
        return [
            'framework' => 'remix', 'mode' => 'server',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => wa_build_cmd($pm, $scripts, 'remix', $base),
            'start_command' => $start,
            'static_dir' => 'build', 'needs_rewrite' => false,
        ];
    }

    // ---- Angular ----
    if ($has('@angular/core')) {
        return [
            'framework' => 'angular', 'mode' => 'static',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => wa_build_cmd($pm, $scripts, 'angular', $base),
            'start_command' => null,
            'static_dir' => 'dist', 'needs_rewrite' => false,
        ];
    }

    // ---- Astro ----
    if ($has('astro')) {
        $adapter = (string)@file_get_contents($dir . '/astro.config.mjs')
                 . (string)@file_get_contents($dir . '/astro.config.js')
                 . (string)@file_get_contents($dir . '/astro.config.ts');
        $mode = strpos($adapter, 'adapter') !== false ? 'server' : 'static';
        return [
            'framework' => 'astro', 'mode' => $mode,
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => wa_build_cmd($pm, $scripts, 'astro', $base),
            'start_command' => 'astro dev --host 127.0.0.1',
            'static_dir' => 'dist', 'needs_rewrite' => false,
        ];
    }

    // ---- SvelteKit ----
    if ($has('@sveltejs/kit')) {
        $adapter = (string)@file_get_contents($dir . '/svelte.config.js')
                 . (string)@file_get_contents($dir . '/svelte.config.ts');
        $isStatic = strpos($adapter, 'adapter-static') !== false;
        $mode = $isStatic ? 'static' : 'server';
        return [
            'framework' => 'sveltekit', 'mode' => $mode,
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => wa_build_cmd($pm, $scripts, 'sveltekit', $base),
            'start_command' => $scripts['preview'] ?? ($scripts['start'] ?? 'node build'),
            'static_dir' => $isStatic ? 'build' : 'build',
            'needs_rewrite' => false,
        ];
    }

    // ---- Vite (React / Vue / Svelte / generic) ----
    if ($has('vite')) {
        $fw = 'vite';
        if ($has('react')) $fw = 'react';
        elseif ($has('vue')) $fw = 'vue';
        elseif ($has('svelte')) $fw = 'svelte';
        // Best-effort outDir from vite.config
        $viteCfg = (string)@file_get_contents($dir . '/vite.config.ts')
                 . (string)@file_get_contents($dir . '/vite.config.js')
                 . (string)@file_get_contents($dir . '/vite.config.mts')
                 . (string)@file_get_contents($dir . '/vite.config.mjs');
        if (preg_match('/outDir\s*:\s*["\']([^"\']+)["\']/', $viteCfg, $m)) {
            $out_dir = trim($m[1], '/');
        }
        return [
            'framework' => $fw, 'mode' => 'static',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => wa_build_cmd($pm, $scripts, 'vite', $base),
            'start_command' => null,
            'static_dir' => $out_dir, 'needs_rewrite' => false,
        ];
    }

    // ---- Create React App ----
    if ($has('react-scripts') || $has('react-app-rewired')) {
        return [
            'framework' => 'cra', 'mode' => 'static',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => wa_build_cmd($pm, $scripts, 'cra', $base),
            'start_command' => null,
            'static_dir' => 'build', 'needs_rewrite' => true,
        ];
    }

    // ---- Vue CLI ----
    if ($has('@vue/cli-service')) {
        $build = $scripts['build'] ?? 'vue-cli-service build';
        return [
            'framework' => 'vue', 'mode' => 'static',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => $build, 'start_command' => null,
            'static_dir' => 'dist', 'needs_rewrite' => true,
        ];
    }

    // ---- Plain Node server ----
    $build = $scripts['build'] ?? null;
    $start = $scripts['start'] ?? null;
    if (!$start && $main) $start = 'node ' . $main;
    if (!$start && (is_file($dir . '/server.js') || is_file($dir . '/index.js'))) {
        $start = 'node ' . (is_file($dir . '/server.js') ? 'server.js' : 'index.js');
    }
    if ($start) {
        return [
            'framework' => 'node', 'mode' => 'server',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => $build, 'start_command' => $start,
            'static_dir' => '', 'needs_rewrite' => false,
        ];
    }

    // ---- JS package with no start ----
    if ($scripts) {
        return [
            'framework' => 'node', 'mode' => 'server',
            'package_manager' => $pm, 'install_command' => $install,
            'build_command' => $build, 'start_command' => 'node index.js',
            'static_dir' => '', 'needs_rewrite' => false,
        ];
    }

    return null;
}

// ---------------------------------------------------------------------------
//  Deploy helpers
// ---------------------------------------------------------------------------

function wa_extract_zip($zipFile, $targetDir) {
    if (!class_exists('ZipArchive')) return 'PHP Zip extension is not available';
    $za = new ZipArchive();
    if ($za->open($zipFile) !== true) return 'Could not open ZIP archive';
    $za->extractTo($targetDir);
    $count = $za->numFiles;
    $za->close();
    @unlink($zipFile);

    // Promote a single top-level folder up to the app root (common for
    // "Download as ZIP" exports).
    $entries = array_values(array_diff(scandir($targetDir), ['.', '..']));
    $rootHasProject = is_file($targetDir . '/package.json') || is_file($targetDir . '/index.html');
    if (count($entries) === 1 && is_dir($targetDir . '/' . $entries[0]) && !$rootHasProject) {
        $sub = $targetDir . '/' . $entries[0];
        $subEntries = array_values(array_diff(scandir($sub), ['.', '..']));
        foreach ($subEntries as $se) {
            $ok = @rename($sub . '/' . $se, $targetDir . '/' . $se);
            if (!$ok) {
                @exec('cp -a ' . escapeshellarg($sub . '/' . $se) . ' ' . escapeshellarg($targetDir . '/') . ' 2>/dev/null');
            }
        }
        @exec('rm -rf ' . escapeshellarg($sub) . ' 2>/dev/null');
    }
    return null;
}

function wa_git_clone($repoUrl, $targetDir) {
    @exec('rm -rf ' . escapeshellarg($targetDir) . ' 2>/dev/null');
    $cmd = 'git clone --depth 1 ' . escapeshellarg($repoUrl) . ' ' . escapeshellarg($targetDir) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return $code === 0 ? null : implode("\n", array_slice($out, -8));
}

function wa_write_env($appDir, $envText) {
    $envText = trim($envText ?? '');
    if ($envText === '') return;
    $lines = [];
    foreach (explode("\n", $envText) as $line) {
        $line = rtrim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) $lines[] = $line;
    }
    if ($lines) {
        @file_put_contents(rtrim($appDir, '/') . '/.env', implode("\n", $lines) . "\n");
    }
}

/**
 * Make Next.js serve from a sub-path and (on Android/Termux) use the WASM SWC
 * fallback. Handles CommonJS and object-literal ESM next.config files.
 */
function wa_inject_next_basepath($appDir, $base_path) {
    $cfg = $appDir . '/next.config.js';
    $base = rtrim($base_path, '/');
    $hasBase = $base !== '';
    $content = is_file($cfg) ? (string)@file_get_contents($cfg) : '';

    if ($content === '') {
        $snippets = [];
        $snippets[] = "/** @type {import('next').NextConfig} */";
        $snippets[] = 'module.exports = {';
        if ($hasBase) $snippets[] = '    basePath: ' . json_encode($base) . ',';
        $snippets[] = '    experimental: { useWasmBinary: true },';
        $snippets[] = '};';
        @file_put_contents($cfg, implode("\n", $snippets) . "\n");
        return;
    }

    if (strpos($content, 'module.exports') !== false) {
        if ($hasBase && strpos($content, 'basePath') === false) {
            $content .= "\nmodule.exports.basePath = " . json_encode($base) . ";\n";
        }
        if (strpos($content, 'useWasmBinary') === false) {
            $content .= "\nmodule.exports.experimental = Object.assign({}, module.exports.experimental, { useWasmBinary: true });\n";
        }
        @file_put_contents($cfg, $content);
        return;
    }

    if (strpos($content, 'export default') !== false && strpos($content, 'export default {') !== false) {
        $inject = 'export default {';
        if ($hasBase) $inject .= ' basePath: ' . json_encode($base) . ',';
        $inject .= ' experimental: { useWasmBinary: true },';
        $content = str_replace('export default {', $inject, $content);
        @file_put_contents($cfg, $content);
        return;
    }

    // Unusual config shape (function / dynamic default export) — leave untouched.
}

/**
 * Android/Termux has no native @next/swc binary. Patch Next's compiled SWC
 * loader so it (1) recognises android as an unsupported platform, (2) tries the
 * WASM fallback first, and (3) finds a locally installed @next/swc-wasm-nodejs.
 * Each patch is skipped when its marker is already present, so it is safe to
 * re-run. Returns true when at least one patch was applied.
 */
function wa_patch_next_swc($appDir) {
    $f = $appDir . '/node_modules/next/dist/build/swc/index.js';
    if (!is_file($f)) return false;
    $c = (string)@file_get_contents($f);
    if ($c === '') return false;
    $changed = false;

    $target = 'const unsupportedPlatform = triples.some((triple)=>!!(triple == null ? void 0 : triple.raw) && knownDefaultWasmFallbackTriples.includes(triple.raw));';
    $fix = 'const unsupportedPlatform = triples.some((triple)=>!!(triple == null ? void 0 : triple.raw) && knownDefaultWasmFallbackTriples.includes(triple.raw)) || PlatformName === "android";';
    if (strpos($c, '|| PlatformName === "android"') === false && strpos($c, $target) !== false) {
        $c = str_replace($target, $fix, $c);
        $changed = true;
    }

    $target2 = 'shouldLoadWasmFallbackFirst = !disableWasmFallback && unsupportedPlatform && useWasmBinary || isWebContainer;';
    $fix2 = 'shouldLoadWasmFallbackFirst = !disableWasmFallback && (unsupportedPlatform || useWasmBinary) || isWebContainer;';
    if (strpos($c, '(unsupportedPlatform || useWasmBinary)') === false && strpos($c, $target2) !== false) {
        $c = str_replace($target2, $fix2, $c);
        $changed = true;
    }

    $target3 = 'let pkgPath = pkg;';
    $fix3 = 'let pkgPath = pkg; try { pkgPath = require.resolve(pkg + "/wasm.js"); } catch (e) {}';
    if (strpos($c, 'require.resolve(pkg') === false && strpos($c, $target3) !== false) {
        $c = str_replace($target3, $fix3, $c);
        $changed = true;
    }

    if ($changed) {
        @file_put_contents($f, $c);
    }
    return $changed;
}

/**
 * Ensure @next/swc-wasm-nodejs is installed so the WASM SWC fallback can be
 * loaded. Picks the exact Next version when available, otherwise the nearest
 * lower release, otherwise the newest published package.
 */
function wa_ensure_next_swc_wasm($appDir, $log) {
    $pkg = $appDir . '/node_modules/next/package.json';
    if (!is_file($pkg)) return;
    $nextVer = '';
    $pd = @json_decode((string)@file_get_contents($pkg), true);
    if (is_array($pd)) $nextVer = trim((string)($pd['version'] ?? ''));
    if ($nextVer === '') return;

    $available = [];
    exec('npm view @next/swc-wasm-nodejs versions --json 2>/dev/null', $out, $code);
    if ($code !== 0) return;
    $list = json_decode(implode('', $out), true);
    if (!is_array($list)) return;
    foreach ($list as $v) {
        if (strpos($v, 'canary') === false && strpos($v, 'preview') === false) $available[] = $v;
    }
    if (!$available) return;

    $pick = null;
    if (in_array($nextVer, $available, true)) {
        $pick = $nextVer;
    } else {
        usort($available, 'version_compare');
        $flip = array_flip($available);
        foreach (array_reverse($available) as $v) {
            if (version_compare($v, $nextVer, '<=')) { $pick = $v; break; }
        }
        if ($pick === null) $pick = $available[count($available) - 1];
    }

    @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] Installing @next/swc-wasm-nodejs@" . $pick . " (WASM SWC fallback for Android)\n", FILE_APPEND);
    exec('cd ' . escapeshellarg($appDir) . ' && npm install --no-save @next/swc-wasm-nodejs@' . escapeshellarg($pick) . ' 2>&1', $o, $c2);
    @file_put_contents($log, '[' . date('Y-m-d H:i:s') . '] exit=' . $c2 . "\n", FILE_APPEND);
}

/**
 * Pre-install TypeScript + React type packages for projects that have a
 * tsconfig.json but omit them from package.json (common with Next.js). This
 * avoids Next's automatic TypeScript installation, which is broken on Termux
 * ("The id argument must be of type string" in the build worker).
 */
function wa_ensure_ts_deps($appDir, $log) {
    $pkgFile = $appDir . '/package.json';
    if (!is_file($appDir . '/tsconfig.json') || !is_file($pkgFile)) return;
    $pkg = @json_decode((string)@file_get_contents($pkgFile), true);
    if (!is_array($pkg)) return;

    $deps = [];
    foreach (['dependencies', 'devDependencies', 'peerDependencies'] as $k) {
        if (isset($pkg[$k]) && is_array($pkg[$k])) {
            foreach (array_keys($pkg[$k]) as $d) $deps[$d] = 1;
        }
    }
    $need = [];
    if (!isset($deps['typescript'])) $need['typescript'] = '^5';
    if (isset($deps['react']) && !isset($deps['@types/react'])) $need['@types/react'] = '*';
    if (isset($deps['react-dom']) && !isset($deps['@types/react-dom'])) $need['@types/react-dom'] = '*';
    if (!isset($deps['@types/node'])) $need['@types/node'] = '*';
    if (!$need) return;

    $pkg['devDependencies'] = array_merge($pkg['devDependencies'] ?? [], $need);
    @file_put_contents($pkgFile, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] Installing TypeScript deps: " . implode(', ', array_keys($need)) . "\n", FILE_APPEND);
    exec('cd ' . escapeshellarg($appDir) . ' && ' . wa_pm_install_cmd(wa_detect_pm($appDir)) . ' 2>&1', $o, $code);
    @file_put_contents($log, '[' . date('Y-m-d H:i:s') . '] exit=' . $code . "\n", FILE_APPEND);
}

function wa_build_log_path($appDir) {
    return rtrim($appDir, '/') . '/build.log';
}

function wa_run_log($cmd, $cwd, $logFile) {
    $stamp = '[' . date('Y-m-d H:i:s') . ']';
    @file_put_contents($logFile, $stamp . " $cmd\n", FILE_APPEND);
    $cmdLine = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmdLine, $out, $code);
    $tail = implode("\n", $out);
    if ($tail !== '') @file_put_contents($logFile, $tail . "\n", FILE_APPEND);
    @file_put_contents($logFile, $stamp . ' exit=' . $code . "\n", FILE_APPEND);
    return $code === 0;
}

function wa_locate_static($appDir, $configured) {
    $dir = rtrim($appDir, '/');
    $cfg = trim((string)$configured, '/');
    if ($cfg === '' || $cfg === '.') $cfg = 'dist';
    if (is_file($dir . '/' . $cfg . '/index.html')) return $cfg;
    if (is_dir($dir . '/' . $cfg)) {
        $found = [];
        foreach ((array)glob($dir . '/' . $cfg . '/*/index.html') as $f) {
            $found[] = dirname($f);
        }
        if (count($found) === 1) {
            return substr($found[0], strlen($dir) + 1);
        }
    }
    // Fallback: shallowest index.html outside node_modules.
    $best = null;
    $bestDepth = PHP_INT_MAX;
    $rdi = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rdi as $f) {
        if ($f->isFile() && $f->getFilename() === 'index.html') {
            $rel = substr($f->getPathname(), strlen($dir) + 1);
            if (strpos($rel, 'node_modules/') === 0) continue;
            $d = substr_count($rel, '/');
            if ($d < $bestDepth) { $bestDepth = $d; $best = $rel; }
        }
    }
    if ($best !== null) {
        return dirname($best) === '.' ? '.' : dirname($best);
    }
    return $cfg;
}

// ---------------------------------------------------------------------------
//  Async build worker
// ---------------------------------------------------------------------------

function wa_launch_build($appId) {
    $db = db();
    $stmt = $db->prepare("SELECT a.*, u.home_dir, u.username FROM web_apps a JOIN users u ON u.id = a.user_id WHERE a.id = ?");
    $stmt->execute([(int)$appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) return;

    $dir = wa_app_dir($app, $app['app_name']);
    $log = wa_build_log_path($dir);
    @file_put_contents($log, '[' . date('Y-m-d H:i:s') . '] Build queued for ' . $app['app_name'] . "\n");
    $db->prepare("UPDATE web_apps SET build_status = 'installing' WHERE id = ?")->execute([(int)$appId]);

    $phpBin = PHP_BINARY;
    $worker = __DIR__ . '/webapps_worker.php';
    $cmd = 'setsid nohup ' . escapeshellarg($phpBin) . ' ' . escapeshellarg($worker) . ' ' . (int)$appId
         . ' >/dev/null 2>&1 &';
    @exec($cmd);
}

/**
 * Runs install + build synchronously (called by the CLI worker).
 * Builds happen in the internal workspace; static output is copied back to
 * the sdcard app dir. Server apps are auto-started via PM2 from the workspace.
 */
function wa_build_worker($appId) {
    wa_init();
    $db = db();
    $stmt = $db->prepare("SELECT a.*, u.home_dir, u.username FROM web_apps a JOIN users u ON u.id = a.user_id WHERE a.id = ?");
    $stmt->execute([(int)$appId]);
    $app = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$app) return;

    $src = wa_app_dir($app, $app['app_name']);
    $log = wa_build_log_path($src);

    if (!is_dir($src)) {
        @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] ERROR: app directory missing\n", FILE_APPEND);
        $db->prepare("UPDATE web_apps SET build_status = 'failed' WHERE id = ?")->execute([(int)$appId]);
        return;
    }

    // Plain static site (no package.json): nothing to install/build.
    if (empty($app['install_command']) && empty($app['build_command'])) {
        $db->prepare("UPDATE web_apps SET static_dir = '.', build_status = 'done' WHERE id = ?")->execute([(int)$appId]);
        @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] No build step needed — serving source directory.\n", FILE_APPEND);
        return;
    }

    $ws = wa_workspace_dir($app, $app['app_name']);
    @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] Syncing source to build workspace…\n", FILE_APPEND);
    wa_sync_src($src, $ws);

    wa_write_env($ws, $app['env_vars'] ?? '');

    if ($app['framework'] === 'next') {
        wa_inject_next_basepath($ws, $app['base_path']);
    }

    $ok = true;

    if (!empty($app['install_command'])) {
        $db->prepare("UPDATE web_apps SET build_status = 'installing' WHERE id = ?")->execute([(int)$appId]);
        if (!wa_run_log($app['install_command'], $ws, $log)) {
            $ok = false;
        } else {
            // Rewrite /usr/bin/env shebangs so npm scripts (.bin) execute on Termux.
            @exec('for f in "' . $ws . '/node_modules/.bin/"*; do [ -e "$f" ] && termux-fix-shebang "$f" >/dev/null 2>&1; done');
            if ($app['framework'] === 'next') {
                if (wa_patch_next_swc($ws)) {
                    @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] Patched Next.js SWC loader for WASM fallback on Android.\n", FILE_APPEND);
                }
                wa_ensure_next_swc_wasm($ws, $log);
                if (is_file($ws . '/node_modules/.bin/next')) {
                    @exec('termux-fix-shebang ' . escapeshellarg($ws . '/node_modules/.bin/next') . ' >/dev/null 2>&1');
                }
            }
            wa_ensure_ts_deps($ws, $log);
            // Re-fix .bin shebangs after any post-install dependency additions.
            @exec('for f in "' . $ws . '/node_modules/.bin/"*; do [ -e "$f" ] && termux-fix-shebang "$f" >/dev/null 2>&1; done');
        }
    }

    if ($ok && !empty($app['build_command'])) {
        $db->prepare("UPDATE web_apps SET build_status = 'building' WHERE id = ?")->execute([(int)$appId]);
        if (!wa_run_log($app['build_command'], $ws, $log)) $ok = false;
    }

    if ($ok && $app['mode'] === 'static') {
        $outRel = wa_locate_static($ws, $app['static_dir'] ?? 'dist');
        if ($outRel === '.') {
            @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] WARNING: build output index.html found at project root — serving source directory.\n", FILE_APPEND);
        } else {
            $outWs = rtrim($ws, '/') . '/' . trim($outRel, '/');
            if (is_dir($outWs)) {
                $outSrc = rtrim($src, '/') . '/' . trim($outRel, '/');
                wa_copy_dir($outWs, $outSrc);
            } else {
                $ok = false;
                @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] ERROR: build output '{$outRel}' not found\n", FILE_APPEND);
            }
        }
        $db->prepare("UPDATE web_apps SET static_dir = ? WHERE id = ?")->execute([$outRel, (int)$appId]);
    }

    if ($ok) {
        $db->prepare("UPDATE web_apps SET build_status = 'done' WHERE id = ?")->execute([(int)$appId]);
        @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] Build complete.\n", FILE_APPEND);
        if ($app['mode'] === 'server') {
            wa_pm2_start($app);
        }
    } else {
        $db->prepare("UPDATE web_apps SET build_status = 'failed' WHERE id = ?")->execute([(int)$appId]);
        @file_put_contents($log, '[' . date('Y-m-d H:i:s') . "] Build FAILED — see output above.\n", FILE_APPEND);
    }
}

// ---------------------------------------------------------------------------
//  PM2 process management
// ---------------------------------------------------------------------------

function wa_shell_ok($cmd, &$out = null) {
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    return $code === 0;
}

function wa_pm2_list() {
    $map = [];
    $out = [];
    $code = 0;
    exec('pm2 jlist 2>/dev/null', $out, $code);
    if ($code !== 0) return $map;
    $data = json_decode(implode('', $out), true);
    if (!is_array($data)) return $map;
    foreach ($data as $proc) {
        if (empty($proc['name'])) continue;
        $env = $proc['pm2_env'] ?? [];
        $map[$proc['name']] = [
            'status' => $env['status'] ?? 'unknown',
            'restarts' => $env['restart_time'] ?? 0,
            'uptime_ms' => isset($env['pm_uptime']) ? (int)$env['pm_uptime'] : 0,
            'cpu' => $proc['monit']['cpu'] ?? 0,
            'memory' => $proc['monit']['memory'] ?? 0,
            'pid' => $env['pid'] ?? null,
            'version' => $env['node_version'] ?? '',
        ];
    }
    return $map;
}

function wa_pm2_status_of($pm2Name) {
    $list = wa_pm2_list();
    return $list[$pm2Name] ?? null;
}

function wa_write_start_script($appDir, $app) {
    $dir = rtrim($appDir, '/');
    $envLines = [];
    $envLines[] = "#!/data/data/com.termux/files/usr/bin/sh";
    $envLines[] = "cd '" . str_replace("'", "'\\''", $dir) . "' || exit 1";
    $envLines[] = "export PATH=\"" . $dir . "/node_modules/.bin:\$PATH\"";
    $envLines[] = "export PORT='" . (int)$app['port'] . "'";
    if ($app['framework'] === 'nuxt' && !empty($app['base_path'])) {
        $envLines[] = "export NUXT_APP_BASE_URL='" . rtrim($app['base_path'], '/') . "/'";
    }
    foreach (explode("\n", (string)($app['env_vars'] ?? '')) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
            $envLines[] = 'export ' . $k . "='" . str_replace("'", "'\\''", $v) . "'";
        }
    }
    $cmd = trim($app['start_command'] ?? 'node index.js');
    if (preg_match('/^[A-Za-z0-9_\-\/\.]+(\s+[^;&|<>]*)?$/', $cmd)) {
        $envLines[] = 'exec ' . $cmd;
    } else {
        $envLines[] = $cmd;
    }
    $script = implode("\n", $envLines) . "\n";
    @file_put_contents($dir . '/start.sh', $script);
    @chmod($dir . '/start.sh', 0755);
}

function wa_pm2_start($app) {
    $db = db();
    $stmt = $db->prepare("SELECT id, username, home_dir FROM users WHERE id = ?");
    $stmt->execute([(int)$app['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;

    $name = wa_pm2_name($app['user_id'], $app['app_name']);

    // Server apps run from the internal build workspace (native addons and
    // symlinked .bin dirs don't work on Android emulated storage).
    $runDir = ($app['mode'] === 'server')
        ? wa_workspace_dir($user, $app['app_name'])
        : wa_app_dir($user, $app['app_name']);

    if (!is_dir($runDir)) return false;

    $script = $runDir . '/start.sh';
    wa_write_start_script($runDir, $app);

    // Keep the sdcard copy of .env in sync for File Manager visibility.
    if ($app['mode'] === 'server' && !empty($app['env_vars'])) {
        wa_write_env(wa_app_dir($user, $app['app_name']), $app['env_vars']);
    }

    // Stop an existing instance if present.
    $list = wa_pm2_list();
    if (isset($list[$name])) {
        wa_shell_ok('pm2 delete ' . escapeshellarg($name));
    }

    $ok = wa_shell_ok('pm2 start ' . escapeshellarg($script) . ' --name ' . escapeshellarg($name));
    if ($ok) {
        $db->prepare("UPDATE web_apps SET pm2_name = ?, status = 'running' WHERE id = ?")
           ->execute([$name, (int)$app['id']]);
        wa_shell_ok('pm2 save');
    }
    return $ok;
}

function wa_pm2_stop($app) {
    $db = db();
    $name = $app['pm2_name'] ?: wa_pm2_name($app['user_id'], $app['app_name']);
    wa_shell_ok('pm2 stop ' . escapeshellarg($name));
    $db->prepare("UPDATE web_apps SET status = 'stopped' WHERE id = ?")->execute([(int)$app['id']]);
    return true;
}

function wa_pm2_restart($app) {
    $db = db();
    $name = $app['pm2_name'] ?: wa_pm2_name($app['user_id'], $app['app_name']);
    $list = wa_pm2_list();
    if (!isset($list[$name])) return wa_pm2_start($app);
    $runDir = ($app['mode'] === 'server')
        ? wa_workspace_dir(['username' => $app['username'], 'home_dir' => $app['home_dir']], $app['app_name'])
        : wa_app_dir(['username' => $app['username'], 'home_dir' => $app['home_dir']], $app['app_name']);
    if (is_dir($runDir)) {
        wa_write_start_script($runDir, $app);
    }
    $ok = wa_shell_ok('pm2 restart ' . escapeshellarg($name));
    if ($ok) {
        $db->prepare("UPDATE web_apps SET status = 'running' WHERE id = ?")->execute([(int)$app['id']]);
    }
    return $ok;
}

function wa_pm2_delete($app) {
    $db = db();
    $name = $app['pm2_name'] ?: wa_pm2_name($app['user_id'], $app['app_name']);
    wa_shell_ok('pm2 delete ' . escapeshellarg($name));
    $db->prepare("UPDATE web_apps SET pm2_name = NULL, status = 'stopped' WHERE id = ?")->execute([(int)$app['id']]);
    wa_shell_ok('pm2 save');
    return true;
}

function wa_pm2_logs($pm2Name, $lines = 200) {
    $home = getenv('HOME') ?: '/data/data/com.termux/files/home';
    $logDir = $home . '/.pm2/logs';
    $out = '';
    foreach (['-out.log', '-err.log'] as $suffix) {
        $f = $logDir . '/' . $pm2Name . $suffix;
        if (is_file($f)) {
            $data = @file($f, FILE_IGNORE_NEW_LINES);
            if ($data) $out .= '--- ' . basename($f) . " ---\n" . implode("\n", array_slice($data, -$lines)) . "\n";
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
//  Public request handler (serves apps under their base path)
// ---------------------------------------------------------------------------

function wa_mime($path) {
    $map = [
        'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
        'js' => 'application/javascript', 'mjs' => 'application/javascript',
        'json' => 'application/json', 'map' => 'application/json',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'webp' => 'image/webp', 'avif' => 'image/avif', 'woff' => 'font/woff',
        'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject',
        'txt' => 'text/plain', 'xml' => 'application/xml', 'wasm' => 'application/wasm',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'wav' => 'audio/wav',
    ];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $map[$ext] ?? 'application/octet-stream';
}

function wa_rewrite_html($html, $base) {
    $baseQ = preg_quote(rtrim($base, '/') . '/', '/');
    // Rewrite root-absolute URLs (href/src/srcset/action/poster/data-src)
    $patterns = ['href', 'src', 'action', 'poster', 'data-src', 'data-href'];
    foreach ($patterns as $attr) {
        $html = preg_replace_callback(
            '#(=' . $attr . '=")/(?!' . $baseQ . ')(?!/)([^"]*)"#',
            function ($m) use ($base) {
                return $m[1] . rtrim($base, '/') . '/"' . $m[2] . '"';
            },
            $html
        );
    }
    // url("...") in inline styles
    $html = preg_replace_callback(
        '#url\(["\']?/(?!' . $baseQ . ')(?!/)([^"\')]+)["\']?\)#',
        function ($m) use ($base) {
            return 'url(' . rtrim($base, '/') . '/' . $m[1] . ')';
        },
        $html
    );
    return $html;
}

function wa_serve_file($absPath, $rewrite = false, $base = '') {
    $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $isHtml = in_array($ext, ['html', 'htm']);

    if ($isHtml) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        if ($rewrite && $base !== '') {
            $html = @file_get_contents($absPath);
            echo wa_rewrite_html($html, $base);
            return;
        }
        readfile($absPath);
        return;
    }

    header('Content-Type: ' . wa_mime($absPath));
    header('Cache-Control: public, max-age=604800');
    $etag = '"' . @md5_file($absPath) . '"';
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        return;
    }
    header('ETag: ' . $etag);
    readfile($absPath);
}

function wa_offline_page($app, $status) {
    http_response_code(502);
    $label = $status === 'failed' ? 'Build failed' : ($status === 'building' ? 'Building…' : 'Offline');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>App Offline</title><style>'
       . 'body{font-family:Inter,system-ui,sans-serif;background:#f8fafc;display:flex;align-items:center;'
       . 'justify-content:center;min-height:100vh;margin:0;color:#0f172a}'
       . '.c{text-align:center;max-width:440px;padding:24px}h1{font-size:22px;margin:12px 0}'
       . 'p{color:#64748b;font-size:14px;line-height:1.6}code{background:#e2e8f0;border-radius:6px;padding:2px 6px;font-size:12px}</style></head>'
       . '<body><div class="c"><div style="font-size:44px">🚀</div>'
       . '<h1>' . htmlspecialchars($label) . '</h1>'
       . '<p>The application <strong>' . htmlspecialchars($app['app_name']) . '</strong> is not currently accepting requests.'
       . ' Check its status and logs in the panel under <strong>Software → Web Apps</strong>.</p></div></body></html>';
}

function wa_stream_to($host, $port, $target, $method) {
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 3);
    if (!$fp) {
        return false;
    }

    $headers = [];
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            $lower = strtolower($name);
            if (in_array($lower, ['host', 'connection', 'content-length', 'transfer-encoding',
                                  'accept-encoding', 'keep-alive', 'upgrade', 'proxy-connection'], true)) {
                continue;
            }
            $headers[] = $name . ': ' . $value;
        }
    }
    $headers[] = 'Host: ' . $host . ':' . $port;
    $headers[] = 'Connection: close';

    $hasBody = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? '';
    if ($hasBody && ctype_digit((string)$contentLength) && (int)$contentLength >= 0) {
        $headers[] = 'Content-Length: ' . (int)$contentLength;
    } elseif ($hasBody) {
        $headers[] = 'Transfer-Encoding: chunked';
    }

    $req = $method . ' ' . $target . " HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n";
    fwrite($fp, $req);

    if ($hasBody) {
        $in = fopen('php://input', 'rb');
        if ($in) {
            if (in_array('Transfer-Encoding: chunked', $headers, true)) {
                while (!feof($in)) {
                    $chunk = fread($in, 8192);
                    if ($chunk === false || $chunk === '') break;
                    fwrite($fp, dechex(strlen($chunk)) . "\r\n" . $chunk . "\r\n");
                }
                fwrite($fp, "0\r\n\r\n");
            } else {
                while (!feof($in)) {
                    $chunk = fread($in, 8192);
                    if ($chunk === false || $chunk === '') break;
                    fwrite($fp, $chunk);
                }
            }
            fclose($in);
        }
    }

    stream_set_timeout($fp, 60);

    $statusLine = fgets($fp);
    if ($statusLine === false || !preg_match('#^HTTP/\S+\s+(\d+)#', $statusLine, $m)) {
        fclose($fp);
        http_response_code(502);
        echo 'Upstream returned an invalid response.';
        return true;
    }
    http_response_code((int)$m[1]);

    $chunked = false;
    while (($line = fgets($fp)) !== false) {
        $trim = rtrim($line, "\r\n");
        if ($trim === '') break;
        if (preg_match('/^transfer-encoding:\s*chunked$/i', $trim)) {
            $chunked = true;
            continue;
        }
        if (preg_match('/^(connection|keep-alive|upgrade|proxy-connection|content-length):/i', $trim)) {
            continue;
        }
        header($trim, false);
    }
    header('Vary: Accept-Encoding', false);

    if ($chunked) {
        while (true) {
            $sizeLine = fgets($fp);
            if ($sizeLine === false) break;
            $size = (int)hexdec(trim($sizeLine));
            if ($size === 0) {
                while (($t = fgets($fp)) !== false && rtrim($t, "\r\n") !== '') {}
                break;
            }
            $remaining = $size;
            while ($remaining > 0) {
                $data = fread($fp, min(65536, $remaining));
                if ($data === false || $data === '') break 2;
                echo $data;
                $remaining -= strlen($data);
            }
            fgets($fp);
        }
    } else {
        while (!feof($fp)) {
            $data = fread($fp, 65536);
            if ($data === false || $data === '') break;
            echo $data;
        }
    }

    fclose($fp);
    return true;
}

/**
 * Route a request under an app base path.
 * Returns true when handled.
 */
function wa_proxy_handle() {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($uri === false || $uri === '') $uri = '/';

    $segments = array_values(array_filter(explode('/', $uri), fn($s) => $s !== ''));
    if (empty($segments)) return false;

    $first = '/' . $segments[0];

    // Fast path: most traffic (customer assets, panel pages) does not map to
    // a deployed web app. Skip the database query unless the first path
    // segment is a known app base path.
    if (!in_array($first, cached_web_app_base_paths(), true)) {
        return false;
    }

    $db = db();
    try {
        $stmt = $db->prepare("SELECT a.*, u.home_dir, u.username FROM web_apps a JOIN users u ON u.id = a.user_id WHERE a.base_path = ?");
        $stmt->execute([$first]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Table not created yet — initialize once and retry.
        init_db();
        $stmt = $db->prepare("SELECT a.*, u.home_dir, u.username FROM web_apps a JOIN users u ON u.id = a.user_id WHERE a.base_path = ?");
        $stmt->execute([$first]);
        $app = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$app) return false;

    // If this exact path maps to a system file (e.g. an app named 'cpanel'),
    // never shadow system routes.
    if (in_array($segments[0], wa_reserved_slugs(), true)) return false;

    $base = $app['base_path'];

    if ($app['mode'] === 'static') {
        return wa_serve_static($app, $uri, $base);
    }

    // Server mode: proxy to the app port.
    $list = wa_pm2_list();
    $pm2Name = $app['pm2_name'] ?: wa_pm2_name($app['user_id'], $app['app_name']);
    $status = $list[$pm2Name]['status'] ?? 'offline';

    $fp = @stream_socket_client("tcp://127.0.0.1:" . (int)$app['port'], $errno, $errstr, 0.5);
    if (!$fp) {
        wa_offline_page($app, $app['build_status']);
        return true;
    }
    fclose($fp);

    if ((int)$app['proxy_strip_base'] === 1) {
        $rel = substr($uri, strlen($base));
        if ($rel === '' || $rel === false) $rel = '/';
        $target = $rel;
    } else {
        $target = $uri;
    }
    if (!empty($_SERVER['QUERY_STRING'])) {
        $target .= '?' . $_SERVER['QUERY_STRING'];
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    return wa_stream_to('127.0.0.1', (int)$app['port'], $target, $_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function wa_serve_static($app, $uri, $base) {
    $stmt = db()->prepare("SELECT id, username, home_dir FROM users WHERE id = ?");
    $stmt->execute([(int)$app['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) { http_response_code(404); return true; }

    $appDir = wa_app_dir($user, $app['app_name']);
    if (!is_dir($appDir)) { http_response_code(404); return true; }

    $staticDir = realpath($appDir . '/' . trim((string)$app['static_dir'], '/'));
    if ($staticDir === false || !is_dir($staticDir)) {
        $staticDir = $appDir;
    }
    $staticDir = rtrim($staticDir, '/');

    $rel = ($base === $uri) ? '' : substr($uri, strlen($base));
    if ($rel === false || $rel === '') $rel = '/';
    $rel = '/' . ltrim($rel, '/');

    $needsRewrite = ((string)$app['static_dir'] === '.' || trim((string)$app['static_dir'], '/') === '');

    if ($rel !== '/') {
        $candidate = realpath($staticDir . $rel);
        if ($candidate !== false && strpos($candidate, $staticDir) === 0 && is_file($candidate)) {
            wa_serve_file($candidate, $needsRewrite, $base);
            return true;
        }
        if ($candidate !== false && strpos($candidate, $staticDir) === 0 && is_dir($candidate)) {
            $idx = $candidate . '/index.html';
            if (is_file($idx)) {
                wa_serve_file($idx, $needsRewrite, $base);
                return true;
            }
        }
    }

    // SPA fallback
    $index = $staticDir . '/index.html';
    if (is_file($index)) {
        wa_serve_file($index, $needsRewrite, $base);
        return true;
    }

    http_response_code(404);
    echo 'Not found';
    return true;
}
