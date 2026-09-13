<?php
require_once __DIR__ . '/../config.php';
require_login();
init_db();
$db = db();
$user_id = $_SESSION['user_id'];

$stmt = $db->prepare("SELECT id, username, email FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$pref_stmt = $db->prepare("SELECT * FROM config WHERE key_name = ?");
$pref_stmt->execute(['contact_prefs_' . $user_id]);
$pref_row = $pref_stmt->fetch(PDO::FETCH_ASSOC);
$prefs = $pref_row ? json_decode($pref_row['value'], true) : [];
$notification_email = $prefs['notification_email'] ?? ($user['email'] ?? '');
$sys_notifications = isset($prefs['sys_notifications']) ? (int)$prefs['sys_notifications'] : 1;
$security_alerts = isset($prefs['security_alerts']) ? (int)$prefs['security_alerts'] : 1;
$marketing_emails = isset($prefs['marketing_emails']) ? (int)$prefs['marketing_emails'] : 0;
$contact_method = $prefs['contact_method'] ?? 'email';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_email') {
        $new_email = trim($_POST['new_email'] ?? '');
        $confirm_email = trim($_POST['confirm_email'] ?? '');
        $current_password = $_POST['verify_password'] ?? '';

        if (empty($new_email) || empty($confirm_email) || empty($current_password)) {
            flash('error', 'All fields are required');
            redirect('/cpanel/contact-info.php');
        }

        if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Invalid email address format');
            redirect('/cpanel/contact-info.php');
        }

        if ($new_email !== $confirm_email) {
            flash('error', 'Email addresses do not match');
            redirect('/cpanel/contact-info.php');
        }

        if ($new_email === $user['email']) {
            flash('error', 'New email must be different from current email');
            redirect('/cpanel/contact-info.php');
        }

        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current_password, $hash)) {
            flash('error', 'Incorrect password');
            redirect('/cpanel/contact-info.php');
        }

        $check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$new_email, $user_id]);
        if ($check->fetch()) {
            flash('error', 'This email is already in use by another account');
            redirect('/cpanel/contact-info.php');
        }

        $db->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$new_email, $user_id]);
        $_SESSION['flash'] = ['success' => 'Email address updated successfully to ' . $new_email];
        redirect('/cpanel/contact-info.php');

    } elseif ($action === 'save_prefs') {
        $notification_email = trim($_POST['notification_email'] ?? '');
        $sys_notifications = isset($_POST['sys_notifications']) ? 1 : 0;
        $security_alerts = isset($_POST['security_alerts']) ? 1 : 0;
        $marketing_emails = isset($_POST['marketing_emails']) ? 1 : 0;
        $contact_method = $_POST['contact_method'] ?? 'email';

        if (!empty($notification_email) && !filter_var($notification_email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Notification email address is invalid');
            redirect('/cpanel/contact-info.php');
        }

        if (!in_array($contact_method, ['email', 'sms', 'none'])) {
            $contact_method = 'email';
        }

        $prefs_data = json_encode([
            'notification_email' => $notification_email,
            'sys_notifications' => $sys_notifications,
            'security_alerts' => $security_alerts,
            'marketing_emails' => $marketing_emails,
            'contact_method' => $contact_method,
        ]);

        $check = $db->prepare("SELECT key_name FROM config WHERE key_name = ?");
        $check->execute(['contact_prefs_' . $user_id]);
        if ($check->fetch()) {
            $db->prepare("UPDATE config SET value = ? WHERE key_name = ?")->execute([$prefs_data, 'contact_prefs_' . $user_id]);
        } else {
            $db->prepare("INSERT INTO config (key_name, value) VALUES (?, ?)")->execute(['contact_prefs_' . $user_id, $prefs_data]);
        }

        flash('success', 'Contact preferences saved successfully');
        redirect('/cpanel/contact-info.php');
    }
    redirect('/cpanel/contact-info.php');
}

$nav = 'contactinfo';
$page_title = 'Contact Information';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="page-hero fade-in">
    <div class="hero-icon blue"><i data-lucide="mail" class="lucide"></i></div>
    <div class="hero-text">
        <div class="hero-title">Contact Information</div>
        <div class="hero-desc">Keep your email address up to date and choose which notifications you receive from the panel.</div>
    </div>
    <div class="hero-actions">
        <span class="badge <?= $user['email'] ? 'badge-active' : 'badge-suspended' ?>" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px"><span class="dot <?= $user['email'] ? 'green' : 'gray' ?>"></span> <?= $user['email'] ? 'Email on file' : 'No email set' ?></span>
    </div>
</div>

<?php
$enabled_toggles = (int)$sys_notifications + (int)$security_alerts + (int)$marketing_emails;
$method_icon = $contact_method === 'sms' ? 'message-square' : ($contact_method === 'none' ? 'bell-off' : 'mail');
?>

<div class="stats-grid fade-in-delay-1">
    <div class="stat-card stat-blue fade-in">
        <div class="stat-icon icon-blue"><i data-lucide="mail" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $user['email'] ? 'Set' : 'None' ?></div>
            <div class="stat-label">Account Email</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($user['email'] ?: 'no email on file') ?></div>
        </div>
    </div>
    <div class="stat-card stat-green fade-in-delay-1">
        <div class="stat-icon icon-green"><i data-lucide="bell" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= $enabled_toggles ?>/3</div>
            <div class="stat-label">Notifications On</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">system, security &amp; marketing</div>
        </div>
    </div>
    <div class="stat-card stat-purple fade-in-delay-1">
        <div class="stat-icon icon-purple"><i data-lucide="<?= $method_icon ?>" class="lucide"></i></div>
        <div class="stat-info">
            <div class="stat-number"><?= h(ucfirst($contact_method)) ?></div>
            <div class="stat-label">Preferred Contact</div>
            <div style="font-size:11px;color:var(--text4);margin-top:2px">how the panel reaches you</div>
        </div>
    </div>
</div>

<div class="card fade-in">
    <div class="card-header">
        <h3><i data-lucide="mail" class="lucide"></i> Current Email Address</h3>
    </div>
    <div class="card-body">
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <div class="email-badge">
                <i data-lucide="mail" class="lucide" style="width:16px;height:16px"></i>
                <?= h($user['email'] ?: 'No email set') ?>
            </div>
            <?php if ($user['email']): ?>
                <span class="badge badge-active" style="display:inline-flex;align-items:center;gap:5px"><span class="dot green"></span> Verified</span>
            <?php else: ?>
                <span class="badge badge-pending" style="display:inline-flex;align-items:center;gap:5px"><span class="dot amber"></span> No email on file</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card fade-in" style="margin-top:20px">
    <div class="card-header">
        <h3><i data-lucide="pencil" class="lucide"></i> Change Email Address</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="action" value="change_email">
            <div class="form-group">
                <label>New Email Address</label>
                <input type="email" name="new_email" required placeholder="your@email.com" autocomplete="email">
            </div>
            <div class="form-group">
                <label>Confirm New Email Address</label>
                <input type="email" name="confirm_email" required placeholder="your@email.com" autocomplete="email">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Current Password <span class="label-req">*</span></label>
                <input type="password" name="verify_password" required placeholder="Enter your current password" autocomplete="current-password">
                <div class="form-hint"><i data-lucide="key-round" class="lucide"></i> Required to confirm you own this account.</div>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <div class="tip-card tip-orange" style="margin-bottom:0">
                    <i data-lucide="alert-triangle" class="lucide"></i>
                    <div class="tip-body">Changing your email address updates the contact email for your account. You may need to re-verify the new address &mdash; a confirmation email will be sent.</div>
                </div>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <button type="submit" class="btn btn-primary" style="width:100%"><i data-lucide="save" class="lucide"></i> Update Email Address</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-1" style="margin-top:20px">
    <div class="card-header">
        <h3><i data-lucide="bell" class="lucide"></i> Contact Preferences</h3>
    </div>
    <div class="card-body">
        <form method="POST" id="prefsForm">
            <input type="hidden" name="action" value="save_prefs">
            <div style="padding:0 0 20px;border-bottom:1px solid var(--border);margin-bottom:20px">
                <div class="form-group" style="margin-bottom:0">
                    <label>Notification Email Address</label>
                    <input type="email" name="notification_email" value="<?= h($notification_email) ?>" placeholder="notifications@yourdomain.com">
                    <div class="form-hint"><i data-lucide="info" class="lucide"></i> Separate address for system notifications (defaults to account email if empty)</div>
                </div>
            </div>

            <div style="padding:0 0 20px;border-bottom:1px solid var(--border);margin-bottom:20px">
                <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:16px">Email Notifications</p>
                <div class="pref-row">
                    <div>
                        <div class="pref-label">System Notifications</div>
                        <div class="pref-desc">Server maintenance, updates, and system announcements</div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="sys_notifications" value="1" <?= $sys_notifications ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                <div class="pref-row">
                    <div>
                        <div class="pref-label">Security Alerts</div>
                        <div class="pref-desc">Login attempts, password changes, and suspicious activity</div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="security_alerts" value="1" <?= $security_alerts ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
                <div class="pref-row">
                    <div>
                        <div class="pref-label">Marketing Emails</div>
                        <div class="pref-desc">Product updates, offers, and hosting tips</div>
                    </div>
                    <label class="toggle">
                        <input type="checkbox" name="marketing_emails" value="1" <?= $marketing_emails ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            </div>

            <div style="padding:0 0 20px">
                <p style="font-size:13px;font-weight:600;color:var(--text);margin-bottom:12px">Preferred Contact Method</p>
                <div class="radio-group" id="contactMethodGroup">
                    <label class="radio-option <?= $contact_method === 'email' ? 'selected' : '' ?>" onclick="selectMethod(this, 'email')">
                        <input type="radio" name="contact_method" value="email" <?= $contact_method === 'email' ? 'checked' : '' ?>>
                        <i data-lucide="mail" class="lucide" style="width:14px;height:14px"></i> Email
                    </label>
                    <label class="radio-option <?= $contact_method === 'sms' ? 'selected' : '' ?>" onclick="selectMethod(this, 'sms')">
                        <input type="radio" name="contact_method" value="sms" <?= $contact_method === 'sms' ? 'checked' : '' ?>>
                        <i data-lucide="message-square" class="lucide" style="width:14px;height:14px"></i> SMS
                    </label>
                    <label class="radio-option <?= $contact_method === 'none' ? 'selected' : '' ?>" onclick="selectMethod(this, 'none')">
                        <input type="radio" name="contact_method" value="none" <?= $contact_method === 'none' ? 'checked' : '' ?>>
                        <i data-lucide="bell-off" class="lucide" style="width:14px;height:14px"></i> None
                    </label>
                </div>
            </div>

            <div class="form-group" style="margin-top:20px;margin-bottom:0">
                <button type="submit" class="btn btn-primary" style="width:100%"><i data-lucide="save" class="lucide"></i> Save Preferences</button>
            </div>
        </form>
    </div>
</div>

<div class="card fade-in-delay-1" style="margin-top:20px">
    <div class="card-header">
        <h3><i data-lucide="list-checks" class="lucide"></i> Current Preferences Summary</h3>
    </div>
    <div class="card-body">
        <div class="kv-row">
            <span class="kv-key"><i data-lucide="mail" class="lucide"></i> Notification Email</span>
            <span class="kv-val"><?= h($notification_email ?: $user['email'] ?: 'Not set') ?></span>
        </div>
        <div class="kv-row">
            <span class="kv-key"><i data-lucide="bell" class="lucide"></i> System Notifications</span>
            <span class="badge <?= $sys_notifications ? 'badge-active' : 'badge-suspended' ?>"><?= $sys_notifications ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <div class="kv-row">
            <span class="kv-key"><i data-lucide="shield" class="lucide"></i> Security Alerts</span>
            <span class="badge <?= $security_alerts ? 'badge-active' : 'badge-suspended' ?>"><?= $security_alerts ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <div class="kv-row">
            <span class="kv-key"><i data-lucide="sparkles" class="lucide"></i> Marketing Emails</span>
            <span class="badge <?= $marketing_emails ? 'badge-active' : 'badge-suspended' ?>"><?= $marketing_emails ? 'Enabled' : 'Disabled' ?></span>
        </div>
        <div class="kv-row" style="border-bottom:none">
            <span class="kv-key"><i data-lucide="message-square" class="lucide"></i> Contact Method</span>
            <span class="kv-val"><?= h(ucfirst($contact_method)) ?></span>
        </div>
    </div>
</div>

<script>
function selectMethod(el, val) {
    document.querySelectorAll('.radio-option').forEach(function(o) { o.classList.remove('selected'); });
    el.classList.add('selected');
    el.querySelector('input').checked = true;
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>