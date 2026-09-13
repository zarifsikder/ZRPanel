document.addEventListener('DOMContentLoaded', function() {

    // 1. Alert Auto-dismiss with smooth animation
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(el) {
        setTimeout(function() {
            el.style.transition = 'all 0.4s cubic-bezier(0.4,0,0.2,1)';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-10px) scale(0.98)';
            setTimeout(function() { el.remove(); }, 400);
        }, 8000);
    });

    // 2. Sidebar Toggle
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var menuBtn = document.getElementById('mobileMenuBtn');
    var closeBtn = document.getElementById('sidebarCloseBtn');

    function openSidebar() {
        sidebar.classList.add('open');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (menuBtn) {
        menuBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }

    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // Close sidebar on nav link click (mobile) — delegated
    var sidebarNav = document.getElementById('sidebarNav');
    if (sidebarNav) {
        sidebarNav.addEventListener('click', function(e) {
            if (e.target.closest('.nav-item') && window.innerWidth <= 768) closeSidebar();
        });
    }

    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
    });

    // 3. Swipe-to-close sidebar (touch)
    var touchStartX = 0;
    var touchCurrentX = 0;

    sidebar.addEventListener('touchstart', function(e) {
        touchStartX = e.touches[0].clientX;
    }, { passive: true });

    sidebar.addEventListener('touchmove', function(e) {
        touchCurrentX = e.touches[0].clientX;
        var diff = touchStartX - touchCurrentX;
        if (diff > 0) {
            sidebar.style.transform = 'translateX(-' + Math.min(diff, 260) + 'px)';
        }
    }, { passive: true });

    sidebar.addEventListener('touchend', function() {
        var diff = touchStartX - touchCurrentX;
        sidebar.style.transform = '';
        if (diff > 80) closeSidebar();
        touchStartX = 0;
        touchCurrentX = 0;
    });

    // 4. File Upload Feedback
    document.querySelectorAll('input[type="file"]').forEach(function(input) {
        input.addEventListener('change', function() {
            if (this.files.length > 0) {
                var btn = this.closest('form').querySelector('button, label');
                if (btn) {
                    btn.textContent = 'Uploading...';
                    btn.style.opacity = '0.7';
                    btn.style.pointerEvents = 'none';
                }
                this.closest('form').submit();
            }
        });
    });

    // 5. Animate stat numbers on page load
    document.querySelectorAll('.stat-number').forEach(function(el) {
        el.style.opacity = '0';
        el.style.transform = 'translateY(8px)';
        setTimeout(function() {
            el.style.transition = 'all 0.5s cubic-bezier(0.4,0,0.2,1)';
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';
        }, 200);
    });

    // 6. Animate progress bars on page load
    document.querySelectorAll('.progress-fill').forEach(function(bar) {
        var width = bar.style.width;
        bar.style.width = '0';
        setTimeout(function() {
            bar.style.width = width;
        }, 400);
    });

    // 7. Smooth card hover interaction
    document.querySelectorAll('.stat-card').forEach(function(card) {
        card.addEventListener('mouseenter', function() {
            var icon = this.querySelector('.stat-icon');
            if (icon) {
                icon.style.transition = 'transform 0.3s cubic-bezier(0.4,0,0.2,1)';
                icon.style.transform = 'scale(1.08)';
            }
        });
        card.addEventListener('mouseleave', function() {
            var icon = this.querySelector('.stat-icon');
            if (icon) {
                icon.style.transform = 'scale(1)';
            }
        });
    });

    // 8. Button ripple effect on click
    document.querySelectorAll('.btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            var ripple = document.createElement('span');
            var rect = this.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height);
            ripple.style.cssText = 'position:absolute;border-radius:50%;background:rgba(255,255,255,0.3);width:' + size + 'px;height:' + size + 'px;left:' + (e.clientX - rect.left - size/2) + 'px;top:' + (e.clientY - rect.top - size/2) + 'px;transform:scale(0);animation:ripple 0.6s ease-out;pointer-events:none';
            this.style.position = 'relative';
            this.style.overflow = 'hidden';
            this.appendChild(ripple);
            setTimeout(function() { ripple.remove(); }, 600);
        });
    });

    // Add ripple animation keyframes (only once)
    if (!document.getElementById('rippleStyle')) {
        var style = document.createElement('style');
        style.id = 'rippleStyle';
        style.textContent = '@keyframes ripple{to{transform:scale(4);opacity:0}}';
        document.head.appendChild(style);
    }

    // 9. Table row hover effect
    document.querySelectorAll('table tbody tr').forEach(function(row) {
        row.addEventListener('mouseenter', function() {
            this.style.transition = 'background 0.15s ease';
        });
    });

    // 10. Auto-resize textareas
    document.querySelectorAll('.code-editor').forEach(function(ta) {
        ta.addEventListener('input', function() {
            this.style.height = 'auto';
            if (this.scrollHeight > 400) {
                this.style.height = this.scrollHeight + 'px';
            }
        });
    });

    // 11. File Manager - Modal, Select All, Bulk, Type Toggle
    window.openModal = function() {
        var m = document.getElementById('createModal');
        if (m) { m.classList.add('open'); if (typeof lucide !== 'undefined') lucide.createIcons(); }
    };
    window.closeModal = function() {
        var m = document.getElementById('createModal');
        if (m) m.classList.remove('open');
    };

    window.setType = function(type) {
        var typeInput = document.getElementById('createType');
        var label = document.getElementById('nameLabel');
        var nameInput = document.getElementById('createName');
        var btnText = document.getElementById('createBtnText');
        var btn = document.getElementById('createBtn');
        var fileBtn = document.getElementById('typeFile');
        var folderBtn = document.getElementById('typeFolder');

        typeInput.value = type;
        if (fileBtn) fileBtn.classList.toggle('active', type === 'file');
        if (folderBtn) folderBtn.classList.toggle('active', type === 'folder');

        if (type === 'folder') {
            label.textContent = 'Folder Name';
            nameInput.placeholder = 'images';
            btnText.textContent = 'Create Folder';
        } else {
            label.textContent = 'File Name';
            nameInput.placeholder = 'index.php';
            btnText.textContent = 'Create File';
        }
        var oldIco = btn.querySelector('i.lucide');
        if (oldIco) oldIco.remove();
        var ico = document.createElement('i');
        ico.setAttribute('data-lucide', type === 'folder' ? 'folder-plus' : 'file-plus');
        ico.className = 'lucide';
        btn.insertBefore(ico, btnText);
        if (typeof lucide !== 'undefined') lucide.createIcons();
    };

    window.toggleAll = function(cb) {
        document.querySelectorAll('.fm-cb').forEach(function(c) { c.checked = cb.checked; });
        updateBulk();
    };

    window.updateBulk = function() {
        var checked = document.querySelectorAll('.fm-cb:checked');
        var bar = document.getElementById('bulkBar');
        var count = document.getElementById('bulkCount');
        var hidden = document.getElementById('bulkHidden');
        if (!bar) return;
        if (checked.length > 0) {
            bar.classList.add('open');
            count.textContent = checked.length + ' selected';
            hidden.innerHTML = '';
            checked.forEach(function(c) {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'targets[]';
                inp.value = c.value;
                hidden.appendChild(inp);
            });
        } else {
            bar.classList.remove('open');
        }
    };

    // Close modal on overlay click
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            e.target.classList.remove('open');
        }
    });
    // Close modal on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var m = document.getElementById('createModal');
            if (m && m.classList.contains('open')) m.classList.remove('open');
        }
    });
});
