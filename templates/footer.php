            </div>
        </main>
    </div>

    <?php if (class_exists('Debug')) Debug::render(); ?>

    <?php if (feature_flag('tool_search')): ?>
    <!-- Tool Search Overlay -->
    <?php $toolSearchRole = $_SESSION['role'] ?? 'cpanel'; ?>
    <div class="tool-search-overlay" id="toolSearchOverlay">
        <div class="tool-search-box">
            <div class="tool-search-input-wrap">
                <i data-lucide="search" class="lucide"></i>
                <input type="text" id="toolSearchInput" placeholder="Search tools..." autocomplete="off" spellcheck="false">
                <kbd>Esc</kbd>
            </div>
            <div class="tool-search-results" id="toolSearchResults">
                <?php if ($toolSearchRole !== 'whm'): ?>
                <div class="tool-search-group">
                    <div class="tool-search-group-title">Quick Links</div>
                    <?php if (feature_flag('kod_file_manager')): ?>
                    <a href="/file-manager" class="tool-search-item" data-q="file manager files folder">
                        <div class="tsi-icon" style="background:rgba(0,115,230,.08);color:#0073e6"><i data-lucide="folder-open" class="lucide"></i></div>
                        <div class="tsi-text"><strong>File Manager</strong><span>Browse and manage files</span></div>
                    </a>
                    <?php endif; ?>
                    <a href="/cpanel/domains.php" class="tool-search-item" data-q="domain website url">
                        <div class="tsi-icon" style="background:rgba(217,119,6,.08);color:#d97706"><i data-lucide="globe" class="lucide"></i></div>
                        <div class="tsi-text"><strong>My Domains</strong><span>Manage domains</span></div>
                    </a>
                </div>
                <?php endif; ?>
                <?php if ($toolSearchRole === 'whm'): ?>
                <div class="tool-search-group">
                    <div class="tool-search-group-title">Quick Links</div>
                    <?php if (feature_flag('whm_accounts')): ?>
                    <a href="/whm/accounts.php" class="tool-search-item" data-q="whm accounts users hosting">
                        <div class="tsi-icon" style="background:rgba(124,58,237,.08);color:#7C3AED"><i data-lucide="users" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Accounts</strong><span>Manage cPanel accounts</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('whm_packages')): ?>
                    <a href="/whm/packages.php" class="tool-search-item" data-q="whm packages plan hosting">
                        <div class="tsi-icon" style="background:rgba(124,58,237,.08);color:#7C3AED"><i data-lucide="package" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Packages</strong><span>Hosting plans &amp; packages</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('whm_domains')): ?>
                    <a href="/whm/domains.php" class="tool-search-item" data-q="whm domains sites">
                        <div class="tsi-icon" style="background:rgba(217,119,6,.08);color:#d97706"><i data-lucide="globe" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Domains</strong><span>Manage all domains</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('whm_api_keys')): ?>
                    <a href="/whm/api-keys.php" class="tool-search-item" data-q="whm api keys token">
                        <div class="tsi-icon" style="background:rgba(220,38,38,.08);color:#dc2626"><i data-lucide="key-round" class="lucide"></i></div>
                        <div class="tsi-text"><strong>API Keys</strong><span>Manage API keys</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('whm_password')): ?>
                    <a href="/whm/password.php" class="tool-search-item" data-q="whm password change security">
                        <div class="tsi-icon" style="background:rgba(99,102,241,.08);color:#6366f1"><i data-lucide="lock-keyhole" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Password</strong><span>Change the WHM password</span></div>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="tool-search-group">
                    <div class="tool-search-group-title">All Tools</div>
                    <?php if ($toolSearchRole !== 'whm'): ?>
                    <?php if (feature_flag('ftp_services')): ?>
                    <a href="/cpanel/ftp.php" class="tool-search-item" data-q="ftp file transfer protocol upload download">
                        <div class="tsi-icon" style="background:rgba(5,150,105,.08);color:#059669"><i data-lucide="hard-drive" class="lucide"></i></div>
                        <div class="tsi-text"><strong>FTP Accounts</strong><span>Manage FTP accounts</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('subdomain_services')): ?>
                    <a href="/cpanel/subdomains.php" class="tool-search-item" data-q="subdomain sub domain">
                        <div class="tsi-icon" style="background:rgba(217,119,6,.08);color:#d97706"><i data-lucide="git-branch" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Subdomains</strong><span>Create subdomains</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('addon_domain_services')): ?>
                    <a href="/cpanel/addon-domains.php" class="tool-search-item" data-q="addon domain extra parked">
                        <div class="tsi-icon" style="background:rgba(217,119,6,.08);color:#d97706"><i data-lucide="plus-circle" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Addon Domains</strong><span>Add additional domains</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('dns_services')): ?>
                    <a href="/cpanel/dns.php" class="tool-search-item" data-q="dns zone editor record a cname mx txt">
                        <div class="tsi-icon" style="background:rgba(217,119,6,.08);color:#d97706"><i data-lucide="network" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Zone Editor</strong><span>Manage DNS records</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('ssl_services')): ?>
                    <a href="/cpanel/ssl.php" class="tool-search-item" data-q="ssl tls certificate https secure lets encrypt">
                        <div class="tsi-icon" style="background:rgba(220,38,38,.08);color:#dc2626"><i data-lucide="lock" class="lucide"></i></div>
                        <div class="tsi-text"><strong>SSL/TLS</strong><span>Manage SSL certificates</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('two_factor_auth')): ?>
                    <a href="/cpanel/two-factor.php" class="tool-search-item" data-q="two factor 2fa authentication security">
                        <div class="tsi-icon" style="background:rgba(220,38,38,.08);color:#dc2626"><i data-lucide="shield" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Two-Factor Auth</strong><span>Extra login security</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('ip_blocker')): ?>
                    <a href="/cpanel/ip-blocker.php" class="tool-search-item" data-q="ip blocker block ban firewall">
                        <div class="tsi-icon" style="background:rgba(220,38,38,.08);color:#dc2626"><i data-lucide="shield-x" class="lucide"></i></div>
                        <div class="tsi-text"><strong>IP Blocker</strong><span>Block IP addresses</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('wordpress_toolkit')): ?>
                    <a href="/cpanel/wordpress.php" class="tool-search-item" data-q="wordpress wp cms install">
                        <div class="tsi-icon" style="background:rgba(0,115,230,.08);color:#0073e6"><i data-lucide="globe" class="lucide"></i></div>
                        <div class="tsi-text"><strong>WordPress Toolkit</strong><span>Install and manage WordPress</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('nodejs_apps')): ?>
                    <a href="/cpanel/nodejs-apps.php" class="tool-search-item" data-q="nodejs node js javascript npm">
                        <div class="tsi-icon" style="background:rgba(0,115,230,.08);color:#0073e6"><i data-lucide="terminal" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Node.js Apps</strong><span>Deploy Node.js applications</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('python_apps')): ?>
                    <a href="/cpanel/python-apps.php" class="tool-search-item" data-q="python django flask app">
                        <div class="tsi-icon" style="background:rgba(0,115,230,.08);color:#0073e6"><i data-lucide="code" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Python Apps</strong><span>Deploy Python applications</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('php_selector')): ?>
                    <a href="/cpanel/php-versions.php" class="tool-search-item" data-q="php version switcher">
                        <div class="tsi-icon" style="background:rgba(0,115,230,.08);color:#0073e6"><i data-lucide="settings-2" class="lucide"></i></div>
                        <div class="tsi-text"><strong>PHP Version</strong><span>Switch PHP version</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('php_extensions')): ?>
                    <a href="/cpanel/php-extensions.php" class="tool-search-item" data-q="php extensions enable disable modules a z">
                        <div class="tsi-icon" style="background:rgba(0,115,230,.08);color:#0073e6"><i data-lucide="plug" class="lucide"></i></div>
                        <div class="tsi-text"><strong>PHP Extensions</strong><span>Enable/disable PHP modules (A-Z)</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('cron_services')): ?>
                    <a href="/cpanel/cron.php" class="tool-search-item" data-q="cron job scheduler task automation">
                        <div class="tsi-icon" style="background:rgba(124,58,237,.08);color:#7C3AED"><i data-lucide="clock" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Cron Jobs</strong><span>Schedule automated tasks</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('backup_services')): ?>
                    <a href="/cpanel/backups.php" class="tool-search-item" data-q="backup restore download">
                        <div class="tsi-icon" style="background:rgba(124,58,237,.08);color:#7C3AED"><i data-lucide="archive" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Backups</strong><span>Create and restore backups</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('cloudflare_tunnel')): ?>
                    <a href="/cpanel/tunnel.php" class="tool-search-item" data-q="cloudflare tunnel proxy">
                        <div class="tsi-icon" style="background:rgba(124,58,237,.08);color:#7C3AED"><i data-lucide="cloud" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Cloudflare Tunnel</strong><span>Expose local servers</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('metrics_services')): ?>
                    <a href="/cpanel/metrics.php" class="tool-search-item" data-q="metrics analytics traffic visitors">
                        <div class="tsi-icon" style="background:rgba(13,148,136,.08);color:#0d9488"><i data-lucide="bar-chart-3" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Metrics</strong><span>View traffic analytics</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('bandwidth_services')): ?>
                    <a href="/cpanel/bandwidth.php" class="tool-search-item" data-q="bandwidth usage data transfer">
                        <div class="tsi-icon" style="background:rgba(13,148,136,.08);color:#0d9488"><i data-lucide="activity" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Bandwidth</strong><span>View bandwidth usage</span></div>
                    </a>
                    <?php endif; ?>
                    <a href="/cpanel/password-security.php" class="tool-search-item" data-q="password security change">
                        <div class="tsi-icon" style="background:rgba(217,119,6,.08);color:#d97706"><i data-lucide="key-round" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Password &amp; Security</strong><span>Change password settings</span></div>
                    </a>
                    <a href="/cpanel/contact-info.php" class="tool-search-item" data-q="contact info email notification">
                        <div class="tsi-icon" style="background:rgba(217,119,6,.08);color:#d97706"><i data-lucide="contact" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Contact Information</strong><span>Update contact details</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if ($toolSearchRole === 'whm'): ?>
                    <?php if (feature_flag('whm_tunnels')): ?>
                    <a href="/whm/tunnels.php" class="tool-search-item" data-q="whm tunnel cloudflare proxy">
                        <div class="tsi-icon" style="background:rgba(13,148,136,.08);color:#0d9488"><i data-lucide="cloud" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Tunnels</strong><span>Manage Cloudflare tunnels</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('whm_server')): ?>
                    <a href="/whm/server.php" class="tool-search-item" data-q="whm server info">
                        <div class="tsi-icon" style="background:rgba(0,115,230,.08);color:#0073e6"><i data-lucide="server" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Server Info</strong><span>Server details &amp; status</span></div>
                    </a>
                    <?php endif; ?>
                    <?php if (feature_flag('whm_health')): ?>
                    <a href="/whm/health.php" class="tool-search-item" data-q="whm health monitor cpu memory">
                        <div class="tsi-icon" style="background:rgba(13,148,136,.08);color:#0d9488"><i data-lucide="activity" class="lucide"></i></div>
                        <div class="tsi-text"><strong>Server Health</strong><span>Monitor server resources</span></div>
                    </a>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="tool-search-empty" id="toolSearchEmpty">
                    <i data-lucide="search-x" class="lucide"></i>
                    <strong>No tools found</strong>
                    <span>Try a different keyword</span>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/app.js?v=20260731"></script>
    <script>if (typeof lucide !== 'undefined') lucide.createIcons();</script>
    <script>
    (function(){
        var overlay = document.getElementById('toolSearchOverlay');
        var input = document.getElementById('toolSearchInput');
        if (!overlay || !input) return;

        var items = overlay.querySelectorAll('.tool-search-item');
        var empty = document.getElementById('toolSearchEmpty');
        var groups = overlay.querySelectorAll('.tool-search-group');

        function openSearch() {
            overlay.classList.add('open');
            input.value = '';
            input.dispatchEvent(new Event('input'));
            setTimeout(function(){ input.focus(); }, 60);
        }

        function closeSearch() {
            overlay.classList.remove('open');
            input.value = '';
        }

        var searchBtn = document.getElementById('topbarSearchBtn');
        if (searchBtn) searchBtn.addEventListener('click', function(){
            if (overlay.classList.contains('open')) {
                closeSearch();
            } else {
                openSearch();
            }
        });

        document.addEventListener('keydown', function(e){
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openSearch();
            }
        });

        input.addEventListener('input', function(){
            var q = this.value.toLowerCase().trim();
            var anyVisible = false;
            if (!q) {
                items.forEach(function(el){ el.style.display = ''; });
                groups.forEach(function(g){ g.style.display = ''; });
                anyVisible = true;
            } else {
                items.forEach(function(el){
                    var data = (el.getAttribute('data-q') || '').toLowerCase() + ' ' + el.textContent.toLowerCase();
                    var show = data.indexOf(q) !== -1;
                    el.style.display = show ? '' : 'none';
                    if (show) anyVisible = true;
                });
                groups.forEach(function(g){
                    var vis = g.querySelectorAll('.tool-search-item[style=""],.tool-search-item:not([style])');
                    g.style.display = vis.length ? '' : 'none';
                });
            }
            if (empty) {
                empty.style.display = anyVisible ? 'none' : 'flex';
                empty.querySelectorAll('.lucide').forEach(function(i){ lucide.createIcons({root: i.parentNode}); });
            }
        });

        input.addEventListener('keydown', function(e){
            if (e.key === 'Escape') {
                overlay.classList.remove('open');
                input.value = '';
                input.dispatchEvent(new Event('input'));
            }
        });

        overlay.addEventListener('click', function(e){
            if (e.target === overlay) {
                overlay.classList.remove('open');
                input.value = '';
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
