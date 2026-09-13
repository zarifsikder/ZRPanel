<?php
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-Content-Type-Options" content="nosniff">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <title>How to Connect a Custom Domain to ZRPanel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: rgba(99, 102, 241, 0.08);
            --primary-border: rgba(99, 102, 241, 0.2);
            --accent: #06b6d4;
            --dark: #0f172a;
            --slate: #475569;
            --slate-light: #94a3b8;
            --bg: #ffffff;
            --bg2: #f8fafc;
            --bg3: #f1f5f9;
            --border: #e2e8f0;
            --border2: #cbd5e1;
            --green: #16a34a;
            --green-bg: #f0fdf4;
            --green-border: #bbf7d0;
            --amber: #d97706;
            --amber-bg: #fffbeb;
            --amber-border: #fde68a;
            --red: #dc2626;
            --red-bg: #fef2f2;
            --red-border: #fecaca;
            --radius: 16px;
            --radius-sm: 10px;
            --radius-xs: 6px;
            --shadow: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.05);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        html { scroll-behavior: smooth; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg2);
            color: var(--dark);
            line-height: 1.7;
            -webkit-font-smoothing: antialiased;
            -webkit-user-select: none;
            user-select: none;
        }

        .hero {
            background: linear-gradient(135deg, var(--dark) 0%, #1e293b 50%, #334155 100%);
            padding: 80px 40px 60px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .hero::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(ellipse at 30% 50%, rgba(99, 102, 241, 0.15) 0%, transparent 60%),
                        radial-gradient(ellipse at 70% 50%, rgba(6, 182, 212, 0.1) 0%, transparent 60%);
            pointer-events: none;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 100px;
            color: #a5b4fc;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 24px;
            position: relative;
        }
        .hero h1 {
            font-size: clamp(2rem, 5vw, 3.2rem);
            font-weight: 800;
            color: white;
            letter-spacing: -1.5px;
            margin-bottom: 16px;
            position: relative;
        }
        .hero h1 span {
            background: linear-gradient(135deg, #a5b4fc, var(--accent));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero p {
            font-size: 17px;
            color: #94a3b8;
            max-width: 600px;
            margin: 0 auto;
            position: relative;
        }

        .toc {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border);
            padding: 0 40px;
        }
        .toc-inner {
            max-width: 900px;
            margin: 0 auto;
            display: flex;
            gap: 4px;
            overflow-x: auto;
            scrollbar-width: none;
        }
        .toc-inner::-webkit-scrollbar { display: none; }
        .toc a {
            padding: 14px 16px;
            font-size: 13px;
            font-weight: 600;
            color: var(--slate-light);
            text-decoration: none;
            white-space: nowrap;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
        }
        .toc a:hover { color: var(--primary); border-bottom-color: var(--primary); }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 40px 24px 80px;
        }

        .section {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 40px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }
        .section-title {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 22px;
            font-weight: 800;
            color: var(--dark);
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        .section-title .icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        .section-desc {
            color: var(--slate);
            font-size: 15px;
            margin-bottom: 28px;
            padding-left: 52px;
        }

        .method-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: var(--radius-xs);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 16px;
        }
        .method-badge.recommended { background: var(--green-bg); color: var(--green); border: 1px solid var(--green-border); }
        .method-badge.alternative { background: var(--primary-light); color: var(--primary); border: 1px solid var(--primary-border); }
        .method-badge.advanced { background: var(--amber-bg); color: var(--amber); border: 1px solid var(--amber-border); }

        .steps { counter-reset: step; }
        .step {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            position: relative;
        }
        .step:last-child { margin-bottom: 0; }
        .step::before {
            counter-increment: step;
            content: counter(step);
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: var(--bg3);
            border: 2px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            color: var(--slate);
            flex-shrink: 0;
            margin-top: 2px;
        }
        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 15px;
            top: 36px;
            bottom: -8px;
            width: 2px;
            background: var(--border);
        }
        .step-content h4 {
            font-size: 15px;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 6px;
        }
        .step-content p, .step-content li {
            font-size: 14px;
            color: var(--slate);
            line-height: 1.7;
        }
        .step-content ol, .step-content ul {
            padding-left: 18px;
            margin-top: 6px;
        }
        .step-content li { margin-bottom: 4px; }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin: 16px 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: var(--bg3);
            padding: 12px 16px;
            font-weight: 700;
            color: var(--slate);
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 11px;
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
        }
        td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            color: var(--dark);
        }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: var(--bg2); }

        code {
            font-family: 'JetBrains Mono', Consolas, monospace;
            background: var(--bg3);
            padding: 3px 8px;
            border-radius: var(--radius-xs);
            font-size: 12px;
            font-weight: 500;
            color: var(--primary);
            border: 1px solid var(--border);
        }

        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            font-size: 14px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            margin: 16px 0;
            line-height: 1.6;
        }
        .alert-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-info { background: var(--primary-light); border: 1px solid var(--primary-border); color: #4338ca; }
        .alert-warn { background: var(--amber-bg); border: 1px solid var(--amber-border); color: #92400e; }
        .alert-success { background: var(--green-bg); border: 1px solid var(--green-border); color: #166534; }

        .ref-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }
        .ref-card {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px 20px;
        }
        .ref-card-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--slate-light);
            margin-bottom: 4px;
        }
        .ref-card-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            font-weight: 600;
            color: var(--dark);
            word-break: break-all;
        }

        .faq-item {
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            margin-bottom: 12px;
            overflow: hidden;
        }
        .faq-q {
            padding: 16px 20px;
            font-weight: 700;
            font-size: 14px;
            background: var(--bg2);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            user-select: none;
            transition: background 0.2s;
        }
        .faq-q:hover { background: var(--bg3); }
        .faq-q::after { content: '+'; font-size: 20px; color: var(--slate-light); transition: transform 0.2s; }
        .faq-item.open .faq-q::after { transform: rotate(45deg); }
        .faq-a {
            padding: 0 20px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease, padding 0.3s ease;
        }
        .faq-item.open .faq-a {
            max-height: 300px;
            padding: 16px 20px;
        }
        .faq-a p, .faq-a li {
            font-size: 14px;
            color: var(--slate);
            line-height: 1.7;
        }
        .faq-a ul { padding-left: 18px; margin-top: 6px; }
        .faq-a li { margin-bottom: 4px; }

        .doc-footer {
            text-align: center;
            padding: 40px;
            color: var(--slate-light);
            font-size: 13px;
        }

        @media (max-width: 768px) {
            .hero { padding: 60px 24px 40px; }
            .section { padding: 28px 20px; }
            .section-desc { padding-left: 0; }
            .toc { padding: 0 16px; }
            .ref-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="hero">
    <div class="hero-badge">&#128279; ZRPanel Documentation</div>
    <h1>How to Connect a<br><span>Custom Domain</span></h1>
    <p>Step-by-step guide to point your own domain to your ZRPanel hosting server.</p>
</div>

<div class="toc">
    <div class="toc-inner">
        <a href="#prerequisites">Prerequisites</a>
        <a href="#method1">Cloudflare (Recommended)</a>
        <a href="#method2">Other DNS Provider</a>
        <a href="#method3">Cloudflare Tunnel</a>
        <a href="#ssl">SSL / HTTPS</a>
        <a href="#troubleshoot">Troubleshooting</a>
        <a href="#reference">Quick Reference</a>
    </div>
</div>

<div class="container">

    <div class="section" id="prerequisites">
        <div class="section-title">
            <span class="icon" style="background:var(--primary-light);color:var(--primary)">&#9881;</span>
            Prerequisites
        </div>
        <div class="section-desc">What you need before getting started.</div>
        <ul style="padding-left:20px;color:var(--slate);font-size:14px;line-height:2">
            <li>A domain name registered with a registrar (Namecheap, GoDaddy, Cloudflare, etc.)</li>
            <li>Your server's public IP: <code>103.175.245.124</code></li>
            <li>A Cloudflare account (if using Cloudflare for DNS)</li>
        </ul>
    </div>

    <div class="section" id="method1">
        <div class="method-badge recommended">&#9733; Recommended</div>
        <div class="section-title">
            <span class="icon" style="background:#fff7ed;color:#ea580c">&#9729;</span>
            Method 1: Domain on Cloudflare
        </div>
        <div class="section-desc">If your domain's nameservers are already pointing to Cloudflare.</div>

        <div class="steps">
            <div class="step">
                <div class="step-content">
                    <h4>Add Domain to Cloudflare</h4>
                    <ol>
                        <li>Log in to <strong>Cloudflare Dashboard</strong> (dash.cloudflare.com)</li>
                        <li>Click <strong>Add a Site</strong></li>
                        <li>Enter your domain (e.g., <code>example.com</code>)</li>
                        <li>Select a plan (Free is fine)</li>
                        <li>Cloudflare will scan existing DNS records</li>
                        <li>Update your domain's nameservers at your registrar to the Cloudflare nameservers shown</li>
                    </ol>
                </div>
            </div>

            <div class="step">
                <div class="step-content">
                    <h4>Add DNS Records</h4>
                    <p>Go to <strong>DNS &#8594; Records</strong> and add:</p>
                    <div class="table-wrap">
                        <table>
                            <tr><th>Type</th><th>Name</th><th>Content</th><th>Proxy</th><th>TTL</th></tr>
                            <tr><td><code>A</code></td><td><code>@</code></td><td><code>103.175.245.124</code></td><td>DNS only (grey)</td><td>Auto</td></tr>
                            <tr><td><code>A</code></td><td><code>www</code></td><td><code>103.175.245.124</code></td><td>DNS only (grey)</td><td>Auto</td></tr>
                        </table>
                    </div>
                    <div class="alert alert-warn">
                        <span class="alert-icon">&#9888;</span>
                        <div>Use <strong>DNS only</strong> (grey cloud) for custom domains, NOT proxied (orange cloud), unless your domain is also added to the Cloudflare Tunnel.</div>
                    </div>
                </div>
            </div>

            <div class="step">
                <div class="step-content">
                    <h4>Create Account in ZRPanel</h4>
                    <ol>
                        <li>Go to <strong>WHM &#8594; Accounts &#8594; Create New Account</strong></li>
                        <li>Enter a <strong>Username</strong></li>
                        <li>Select <strong>Custom Domain</strong> tab</li>
                        <li>Enter your domain (e.g., <code>example.com</code>)</li>
                        <li>Fill in Password and Email</li>
                        <li>Click <strong>Create Account</strong></li>
                    </ol>
                </div>
            </div>

            <div class="step">
                <div class="step-content">
                    <h4>Upload Your Website</h4>
                    <ol>
                        <li>Go to <strong>cPanel &#8594; File Manager</strong></li>
                        <li>Upload your website files to the <code>public_html/</code> folder</li>
                        <li>Your site is now live at <code>https://example.com</code></li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    <div class="section" id="method2">
        <div class="method-badge alternative">Alternative</div>
        <div class="section-title">
            <span class="icon" style="background:var(--primary-light);color:var(--primary)">&#127760;</span>
            Method 2: Domain NOT on Cloudflare
        </div>
        <div class="section-desc">If your domain uses another DNS provider (registrar's default, etc.).</div>

        <div class="steps">
            <div class="step">
                <div class="step-content">
                    <h4>Add DNS Records at Your Registrar</h4>
                    <p>Log in to your domain registrar and add these DNS records:</p>
                    <div class="table-wrap">
                        <table>
                            <tr><th>Type</th><th>Host / Name</th><th>Value</th><th>TTL</th></tr>
                            <tr><td><code>A</code></td><td><code>@</code></td><td><code>103.175.245.124</code></td><td>3600</td></tr>
                            <tr><td><code>A</code></td><td><code>www</code></td><td><code>103.175.245.124</code></td><td>3600</td></tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="step">
                <div class="step-content">
                    <h4>Wait for DNS Propagation</h4>
                    <p>DNS changes take <strong>15 minutes to 48 hours</strong> to propagate worldwide.</p>
                    <p style="margin-top:8px">Check status at:</p>
                    <ul>
                        <li><code>dnschecker.org</code></li>
                        <li><code>whatsmydns.net</code></li>
                    </ul>
                </div>
            </div>

            <div class="step">
                <div class="step-content">
                    <h4>Create Account in ZRPanel</h4>
                    <p>Same as Method 1, Step 3.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="section" id="method3">
        <div class="method-badge advanced">Advanced</div>
        <div class="section-title">
            <span class="icon" style="background:var(--amber-bg);color:var(--amber)">&#128274;</span>
            Method 3: Using Cloudflare Tunnel
        </div>
        <div class="section-desc">If your server is behind a router/NAT and can't receive direct connections.</div>

        <div class="steps">
            <div class="step">
                <div class="step-content">
                    <h4>Add Domain to Cloudflare</h4>
                    <p>Same as Method 1, Step 1.</p>
                </div>
            </div>

            <div class="step">
                <div class="step-content">
                    <h4>Add Domain to Tunnel</h4>
                    <ol>
                        <li>Go to <strong>Zero Trust &#8594; Networks &#8594; Tunnels</strong></li>
                        <li>Click your tunnel &#8594; <strong>Public Hostname</strong> tab</li>
                        <li>Click <strong>Add a public hostname</strong></li>
                        <li>Fill in:
                            <ul style="margin-top:8px">
                                <li><strong>Subdomain:</strong> <code>@</code> (leave empty for root) or <code>www</code></li>
                                <li><strong>Domain:</strong> <code>example.com</code></li>
                                <li><strong>Type:</strong> HTTP</li>
                                <li><strong>URL:</strong> <code>localhost:8080</code></li>
                            </ul>
                        </li>
                        <li>Save</li>
                    </ol>
                </div>
            </div>

            <div class="step">
                <div class="step-content">
                    <h4>Add DNS Record</h4>
                    <p>Go to <strong>DNS &#8594; Records</strong> and add:</p>
                    <div class="table-wrap">
                        <table>
                            <tr><th>Type</th><th>Name</th><th>Content</th><th>Proxy</th><th>TTL</th></tr>
                            <tr><td><code>CNAME</code></td><td><code>@</code></td><td><code>81f2bc12-...cfargotunnel.com</code></td><td>Proxied (orange)</td><td>Auto</td></tr>
                            <tr><td><code>CNAME</code></td><td><code>www</code></td><td><code>81f2bc12-...cfargotunnel.com</code></td><td>Proxied (orange)</td><td>Auto</td></tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="step">
                <div class="step-content">
                    <h4>Create Account in ZRPanel</h4>
                    <p>Same as Method 1, Step 3. The WHM panel will create Cloudflare DNS records automatically.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="section" id="ssl">
        <div class="section-title">
            <span class="icon" style="background:var(--green-bg);color:var(--green)">&#128274;</span>
            SSL / HTTPS
        </div>
        <div class="section-desc">How to secure your domain with HTTPS.</div>

        <div class="alert alert-success">
            <span class="alert-icon">&#10003;</span>
            <div><strong>Cloudflare proxied domains:</strong> SSL is automatic. Cloudflare provides and manages the certificate for you.</div>
        </div>

        <div class="alert alert-info">
            <span class="alert-icon">&#8505;</span>
            <div><strong>Non-Cloudflare domains:</strong> Use cPanel &#8594; <strong>SSL/TLS</strong> to generate a self-signed certificate, or use <strong>Certbot</strong> for a free Let's Encrypt certificate.</div>
        </div>
    </div>

    <div class="section" id="troubleshoot">
        <div class="section-title">
            <span class="icon" style="background:var(--red-bg);color:var(--red)">&#128269;</span>
            Troubleshooting
        </div>
        <div class="section-desc">Common issues and how to fix them.</div>

        <div class="faq-item" onclick="this.classList.toggle('open')">
            <div class="faq-q">Domain shows "Subdomain Not Found"</div>
            <div class="faq-a">
                <ul>
                    <li>DNS records are not set up yet or haven't propagated</li>
                    <li>Check DNS at <code>dnschecker.org</code></li>
                    <li>Make sure the DNS record points to <code>103.175.245.124</code></li>
                </ul>
            </div>
        </div>

        <div class="faq-item" onclick="this.classList.toggle('open')">
            <div class="faq-q">Domain shows "Connection Refused"</div>
            <div class="faq-a">
                <ul>
                    <li>Server firewall may be blocking port 80/443</li>
                    <li>Make sure port forwarding is set up (if not using Cloudflare Tunnel)</li>
                    <li>Check that the PHP server is running on port 8080</li>
                </ul>
            </div>
        </div>

        <div class="faq-item" onclick="this.classList.toggle('open')">
            <div class="faq-q">Domain shows "Account Suspended"</div>
            <div class="faq-a">
                <ul>
                    <li>The hosting account has been suspended by the admin</li>
                    <li>Contact the hosting administrator to reactivate</li>
                </ul>
            </div>
        </div>

        <div class="faq-item" onclick="this.classList.toggle('open')">
            <div class="faq-q">DNS records exist but site doesn't load</div>
            <div class="faq-a">
                <ul>
                    <li>Verify the A record points to <code>103.175.245.124</code></li>
                    <li>If using Cloudflare, ensure the orange cloud is OFF for non-tunnel domains</li>
                    <li>Clear your browser cache and try in incognito mode</li>
                    <li>Check if the account exists in ZRPanel WHM</li>
                </ul>
            </div>
        </div>
    </div>

    <div class="section" id="reference">
        <div class="section-title">
            <span class="icon" style="background:var(--bg3);color:var(--slate)">&#128203;</span>
            Quick Reference
        </div>
        <div class="section-desc">Important values at a glance.</div>

        <div class="ref-grid">
            <div class="ref-card">
                <div class="ref-card-label">Server IP</div>
                <div class="ref-card-value">103.175.245.124</div>
            </div>
            <div class="ref-card">
                <div class="ref-card-label">Tunnel ID</div>
                <div class="ref-card-value">81f2bc12-1e7e-40d7-a14e-503335e7ee5d</div>
            </div>
            <div class="ref-card">
                <div class="ref-card-label">Tunnel CNAME</div>
                <div class="ref-card-value">81f2bc12-...cfargotunnel.com</div>
            </div>
            <div class="ref-card">
                <div class="ref-card-label">Panel URL</div>
                <div class="ref-card-value">https://dzhost.shop</div>
            </div>
            <div class="ref-card">
                <div class="ref-card-label">WHM Login</div>
                <div class="ref-card-value">https://dzhost.shop/whm/login</div>
            </div>
            <div class="ref-card">
                <div class="ref-card-label">cPanel Login</div>
                <div class="ref-card-value">https://dzhost.shop/login</div>
            </div>
        </div>
    </div>

</div>

<div class="doc-footer">
    ZRPanel Documentation &middot; Last updated <?= date('F Y') ?>
</div>

<script>
document.addEventListener('contextmenu', function(e) { e.preventDefault(); return false; });
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey && (e.key === 's' || e.key === 'S' || e.key === 'u' || e.key === 'U' || e.key === 'p' || e.key === 'P' || e.key === 'j' || e.key === 'J')) {
        e.preventDefault();
        return false;
    }
    if (e.key === 'F12') {
        e.preventDefault();
        return false;
    }
});
document.addEventListener('dragstart', function(e) { e.preventDefault(); return false; });
document.addEventListener('selectstart', function(e) {
    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
        e.preventDefault();
        return false;
    }
});
document.addEventListener('copy', function(e) {
    if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
        e.preventDefault();
        return false;
    }
});
</script>

</body>
</html>
