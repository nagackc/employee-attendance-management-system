document.addEventListener('DOMContentLoaded', function () {

    // ── Toast notifications ───────────────────────────────────────────────────
    function showToast(message, type, duration) {
        type = type || 'success';
        duration = duration || 4000;
        var container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'toast-container';
            document.body.appendChild(container);
        }
        var icons = { success: '✅', error: '❌', info: 'ℹ️' };
        var toast = document.createElement('div');
        toast.className = 'toast toast-' + type;
        toast.innerHTML = '<span>' + (icons[type] || 'ℹ️') + '</span><span>' + message + '</span>';
        container.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(18px)';
            setTimeout(function () { toast.remove(); }, 360);
        }, duration);
    }
    window.showToast = showToast;

    // Pick up PHP flash messages
    var flashOk  = document.getElementById('php-flash-success');
    var flashErr = document.getElementById('php-flash-error');
    if (flashOk  && flashOk.textContent.trim())  showToast(flashOk.textContent.trim(),  'success');
    if (flashErr && flashErr.textContent.trim()) showToast(flashErr.textContent.trim(), 'error');

    // ── Shared admin and employee sidebar interactions ────────────────────────
    const portalSidebarMedia = window.matchMedia('(max-width: 992px)');
    document.querySelectorAll('[data-portal-shell]').forEach(shell => {
        const sidebar = shell.querySelector('[data-portal-sidebar]');
        const sidebarToggle = shell.querySelector('[data-portal-sidebar-toggle]');
        const backdrop = shell.querySelector('[data-portal-backdrop]');
        const sidebarNav = shell.querySelector('[data-portal-sidebar-nav]');
        if (!sidebar || !sidebarToggle) return;

        const syncSidebarState = () => {
            const isMobile = portalSidebarMedia.matches;
            const visible = isMobile
                ? shell.classList.contains('sidebar-open')
                : !shell.classList.contains('sidebar-hidden');
            sidebarToggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
            sidebarToggle.setAttribute('aria-label', isMobile
                ? (visible ? 'Close navigation' : 'Open navigation')
                : (visible ? 'Hide sidebar' : 'Show sidebar'));
            sidebar.setAttribute('aria-hidden', visible ? 'false' : 'true');
            sidebar.inert = !visible;
        };

        const closeMobileSidebar = (returnFocus) => {
            if (!portalSidebarMedia.matches || !shell.classList.contains('sidebar-open')) return;
            shell.classList.remove('sidebar-open');
            syncSidebarState();
            if (returnFocus) sidebarToggle.focus();
        };

        const resetSidebarForViewport = () => {
            shell.classList.remove('sidebar-open', 'sidebar-hidden');
            syncSidebarState();
        };

        sidebarToggle.addEventListener('click', () => {
            if (portalSidebarMedia.matches) {
                shell.classList.remove('sidebar-hidden');
                shell.classList.toggle('sidebar-open');
            } else {
                shell.classList.remove('sidebar-open');
                shell.classList.toggle('sidebar-hidden');
            }
            syncSidebarState();
        });
        backdrop && backdrop.addEventListener('click', () => closeMobileSidebar(true));
        sidebarNav && sidebarNav.querySelectorAll('a').forEach(link => {
            link.addEventListener('click', () => closeMobileSidebar(false));
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') closeMobileSidebar(true);
        });
        if (typeof portalSidebarMedia.addEventListener === 'function') {
            portalSidebarMedia.addEventListener('change', resetSidebarForViewport);
        } else {
            portalSidebarMedia.addListener(resetSidebarForViewport);
        }
        resetSidebarForViewport();
    });

    document.querySelectorAll('[data-admin-nav-group]').forEach(group => {
        const toggle = group.querySelector('[data-admin-nav-toggle]');
        const storageKey = `admin-nav-${group.dataset.navKey || 'group'}`;
        if (!toggle) return;
        if (group.dataset.active !== 'true') {
            try {
                const stored = window.sessionStorage.getItem(storageKey);
                if (stored !== null) group.classList.toggle('is-open', stored === 'open');
            } catch (error) {
                // Navigation remains usable when browser storage is unavailable.
            }
        }
        toggle.setAttribute('aria-expanded', group.classList.contains('is-open') ? 'true' : 'false');
        toggle.addEventListener('click', () => {
            const open = group.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            try {
                window.sessionStorage.setItem(storageKey, open ? 'open' : 'closed');
            } catch (error) {
                // Ignore storage failures; the current interaction still succeeds.
            }
        });
    });

    const logoPreviewInput = document.querySelector('[data-logo-file-input]');
    const logoRemoveInput = document.querySelector('[data-logo-remove]');
    const logoFileName = document.querySelector('[data-logo-file-name]');
    const logoPreview = document.getElementById('settings-logo-preview');
    if (logoPreviewInput && logoPreview) {
        const initialSource = logoPreview.dataset.logoInitialSrc || '';
        let previewObjectUrl = '';
        const clearObjectUrl = () => {
            if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
            previewObjectUrl = '';
        };
        const showLogoFallback = () => {
            logoPreview.innerHTML = '';
            const fallback = document.createElement('span');
            fallback.textContent = 'FJ';
            logoPreview.appendChild(fallback);
        };
        const showLogoSource = source => {
            if (!source) {
                showLogoFallback();
                return;
            }
            logoPreview.innerHTML = '';
            const image = document.createElement('img');
            image.alt = 'Company logo preview';
            image.addEventListener('error', showLogoFallback, { once: true });
            image.src = source;
            logoPreview.appendChild(image);
        };
        const restoreInitialLogo = () => {
            clearObjectUrl();
            showLogoSource(initialSource);
            if (logoFileName) logoFileName.textContent = '';
        };
        const updateLogoPreview = () => {
            const file = logoPreviewInput.files && logoPreviewInput.files[0];
            if (!file) {
                restoreInitialLogo();
                return;
            }
            const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            if (!allowedTypes.includes(file.type) || file.size > 2 * 1024 * 1024) {
                logoPreviewInput.value = '';
                restoreInitialLogo();
                showToast('Choose a JPG, PNG, or WebP logo no larger than 2 MB.', 'error');
                return;
            }
            if (logoRemoveInput) logoRemoveInput.checked = false;
            clearObjectUrl();
            previewObjectUrl = URL.createObjectURL(file);
            showLogoSource(previewObjectUrl);
            if (logoFileName) logoFileName.textContent = `Selected: ${file.name}`;
        };
        const initialLogo = logoPreview.querySelector('img');
        if (initialLogo) {
            initialLogo.addEventListener('error', showLogoFallback, { once: true });
            if (initialLogo.complete && initialLogo.naturalWidth === 0) showLogoFallback();
        }
        logoPreviewInput.addEventListener('change', updateLogoPreview);
        logoRemoveInput && logoRemoveInput.addEventListener('change', () => {
            if (logoRemoveInput.checked) {
                logoPreviewInput.value = '';
                clearObjectUrl();
                showLogoFallback();
                if (logoFileName) logoFileName.textContent = 'The current logo will be removed when settings are saved.';
            } else {
                restoreInitialLogo();
            }
        });
        window.addEventListener('pagehide', clearObjectUrl, { once: true });
    }

    const reportCompany = document.getElementById('report-company');
    const reportEmployee = document.getElementById('report-employee');
    if (reportCompany && reportEmployee) {
        const filterReportEmployees = (resetSelection) => {
            const company = reportCompany.value;
            Array.from(reportEmployee.options).forEach(option => {
                if (!option.dataset.company) return;
                const visible = !company || option.dataset.company === company;
                option.hidden = !visible;
                option.disabled = !visible;
            });
            if (resetSelection && reportEmployee.selectedOptions[0]?.disabled) {
                reportEmployee.value = 'all';
            }
        };
        filterReportEmployees(false);
        reportCompany.addEventListener('change', () => filterReportEmployees(true));
    }

    document.querySelectorAll('[data-employee-nav-group]').forEach(group => {
        const toggle = group.querySelector('[data-employee-nav-toggle]');
        if (!toggle) return;
        toggle.addEventListener('click', () => {
            const open = group.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });

    // ── Employee dashboard action center ───────────────────────────────────
    const dashboardAlertCenter = document.getElementById('dashboard-action-center');
    if (dashboardAlertCenter) {
        const alerts = Array.from(dashboardAlertCenter.querySelectorAll('[data-dashboard-alert]'));
        const countLabel = dashboardAlertCenter.querySelector('[data-dashboard-alert-count]');
        const storageKey = id => `dashboard-alert-dismissed:${id}`;
        const updateAlertCount = () => {
            const visibleAlerts = alerts.filter(alert => !alert.hidden);
            if (countLabel) countLabel.textContent = `${visibleAlerts.length} active`;
            dashboardAlertCenter.hidden = visibleAlerts.length === 0;
            return visibleAlerts;
        };

        alerts.forEach(alert => {
            const dismissButton = alert.querySelector('[data-dashboard-alert-dismiss]');
            const alertId = alert.dataset.dashboardAlertId || '';
            if (!dismissButton || !alertId) return;
            try {
                alert.hidden = window.sessionStorage.getItem(storageKey(alertId)) === '1';
            } catch (error) {
                // The alert remains visible when browser storage is unavailable.
            }
            dismissButton.addEventListener('click', () => {
                alert.hidden = true;
                try {
                    window.sessionStorage.setItem(storageKey(alertId), '1');
                } catch (error) {
                    // Dismissal still applies to the current page rendering.
                }
                const visibleAlerts = updateAlertCount();
                const nextTarget = visibleAlerts[0]?.querySelector('a, button') || document.getElementById('attendance-panel');
                nextTarget && nextTarget.focus();
            });
        });
        updateAlertCount();
    }

    const profileToggle = document.getElementById('profile-menu-toggle');
    const profileMenu = document.getElementById('profile-menu');
    if (profileToggle && profileMenu) {
        profileToggle.addEventListener('click', () => {
            const expanded = profileMenu.classList.toggle('open');
            profileToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });
        document.addEventListener('click', (e) => {
            if (!profileMenu.contains(e.target) && !profileToggle.contains(e.target)) {
                profileMenu.classList.remove('open');
                profileToggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // ── Reusable table enhance (search/sort/pagination) ───────────────────────
    const tableEnhancers = document.querySelectorAll('[data-table-enhance="true"]');
    tableEnhancers.forEach((wrap) => {
        const table = wrap.querySelector('table');
        const tbody = table && table.querySelector('tbody');
        if (!table || !tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr'));
        if (!rows.length) return;

        const searchInput = wrap.querySelector('[data-table-search]') || (wrap.parentElement ? wrap.parentElement.querySelector('[data-table-search]') : null);
        const perPage = parseInt(wrap.dataset.pageSize || '10', 10) || 10;
        const pager = document.createElement('div');
        pager.className = 'table-pagination';
        wrap.appendChild(pager);

        let workingRows = rows.slice();
        let currentPage = 1;
        let sortState = { index: -1, direction: 'asc' };

        const render = () => {
            rows.forEach((r) => { r.style.display = 'none'; });
            const totalPages = Math.max(1, Math.ceil(workingRows.length / perPage));
            currentPage = Math.min(currentPage, totalPages);
            const start = (currentPage - 1) * perPage;
            workingRows.slice(start, start + perPage).forEach((r) => { r.style.display = ''; });

            pager.innerHTML = '';
            const pageLabel = document.createElement('span');
            pageLabel.textContent = `Page ${currentPage} of ${totalPages}`;
            const prev = document.createElement('button');
            prev.type = 'button';
            prev.textContent = 'Prev';
            prev.disabled = currentPage === 1;
            prev.addEventListener('click', () => { currentPage--; render(); });
            const next = document.createElement('button');
            next.type = 'button';
            next.textContent = 'Next';
            next.disabled = currentPage >= totalPages;
            next.addEventListener('click', () => { currentPage++; render(); });
            pager.append(prev, next, pageLabel);
        };

        const applySearch = () => {
            const q = (searchInput && searchInput.value || '').trim().toLowerCase();
            workingRows = rows.filter((row) => row.textContent.toLowerCase().includes(q));
            currentPage = 1;
            render();
        };

        if (searchInput) {
            searchInput.addEventListener('input', applySearch);
        }

        const headers = Array.from(table.querySelectorAll('thead th'));
        headers.forEach((th, idx) => {
            if (th.dataset.sort === 'false') return;
            th.classList.add('table-sortable');
            th.addEventListener('click', () => {
                const nextDir = sortState.index === idx && sortState.direction === 'asc' ? 'desc' : 'asc';
                sortState = { index: idx, direction: nextDir };
                headers.forEach((h) => h.classList.remove('sorted-asc', 'sorted-desc'));
                th.classList.add(nextDir === 'asc' ? 'sorted-asc' : 'sorted-desc');
                workingRows.sort((a, b) => {
                    const av = (a.children[idx] && a.children[idx].textContent || '').trim().toLowerCase();
                    const bv = (b.children[idx] && b.children[idx].textContent || '').trim().toLowerCase();
                    if (av === bv) return 0;
                    return nextDir === 'asc' ? (av > bv ? 1 : -1) : (av < bv ? 1 : -1);
                });
                currentPage = 1;
                render();
            });
        });

        render();
    });

    const globalSearch = document.getElementById('admin-global-search');
    if (globalSearch) {
        globalSearch.addEventListener('input', () => {
            const q = globalSearch.value.trim().toLowerCase();
            const container = document.querySelector('[data-searchable]');
            if (!container) return;
            container.querySelectorAll('[data-search-item]').forEach((el) => {
                const text = el.textContent.toLowerCase();
                el.style.display = text.includes(q) ? '' : 'none';
            });
        });
    }

    // ── Live clock ────────────────────────────────────────────────────────────
    const clockEl = document.getElementById('live-clock');
    if (clockEl) {
        const tz = clockEl.dataset.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone;
        const updateClock = () => {
            clockEl.textContent = new Date().toLocaleTimeString('en-US', { timeZone: tz });
        };
        updateClock();
        setInterval(updateClock, 1000);
    }

    // ── Elapsed work timer ────────────────────────────────────────────────────
    const elapsedEl     = document.getElementById('elapsed-time');
    const tracker       = document.getElementById('elapsed-tracker');
    const lunchTimerEl  = document.getElementById('lunch-timer');
    const lunchLabelEl  = document.getElementById('lunch-timer-label');
    const lunchCardEl   = document.getElementById('lunch-timer-card');
    const quickBreakEl  = document.getElementById('quick-break-total');

    if (elapsedEl && tracker) {
        const startRaw       = tracker.dataset.startTime  || '';
        const breakStartRaw  = tracker.dataset.breakStart || '';
        const baseBreakSecs  = parseInt(tracker.dataset.breakSeconds || '0', 10) || 0;
        const onBreak        = tracker.dataset.onBreak === '1';
        const lunchCompleted = tracker.dataset.lunchCompleted === '1';
        const baseQuickSecs  = parseInt(tracker.dataset.quickBreakSeconds || '0', 10) || 0;
        const quickStartRaw  = tracker.dataset.quickBreakStart || '';
        const onQuickBreak   = tracker.dataset.onQuickBreak === '1';

        const parseDate = (raw) => {
            if (!raw) return null;
            const t = String(raw).trim();
            if (!t)  return null;
            const d = new Date(t);
            if (!Number.isNaN(d.getTime())) return d;
            const m = t.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.(\d{3}))?$/);
            if (m) return new Date(+m[1], +m[2]-1, +m[3], +m[4], +m[5], +m[6], +(m[7]||0));
            const fb = new Date(t.replace(' ', 'T'));
            return Number.isNaN(fb.getTime()) ? null : fb;
        };

        const startTime  = parseDate(startRaw);
        const breakStart = parseDate(breakStartRaw);
        const quickStart = parseDate(quickStartRaw);
        let lunchAlertShown = false;

        const fmtDur = (secs) => {
            const s = Math.max(0, Math.floor(Number.isFinite(secs) ? secs : 0));
            return [Math.floor(s/3600), Math.floor((s%3600)/60), s%60]
                   .map(n => String(n).padStart(2,'0')).join(':');
        };

        const tick = () => {
            if (!startTime) {
                elapsedEl.textContent = '00:00:00';
                return;
            }
            const now           = new Date();
            const gross         = (now - startTime) / 1000;
            const liveBrk       = onBreak && breakStart ? (now - breakStart) / 1000 : 0;
            const totalBrk      = baseBreakSecs + liveBrk;
            elapsedEl.textContent = fmtDur(gross - totalBrk);

            if (lunchTimerEl && onBreak && breakStart) {
                const remaining = 3600 - liveBrk;
                if (remaining >= 0) {
                    lunchTimerEl.textContent = `${String(Math.floor(remaining / 60)).padStart(2, '0')}:${String(Math.floor(remaining % 60)).padStart(2, '0')}`;
                    if (lunchLabelEl) lunchLabelEl.textContent = 'Remaining';
                } else {
                    lunchTimerEl.textContent = '+' + fmtDur(Math.abs(remaining)) + ' overdue';
                    if (lunchLabelEl) lunchLabelEl.textContent = 'End lunch when you return';
                    lunchCardEl && lunchCardEl.classList.add('is-overdue');
                    if (!lunchAlertShown) {
                        lunchAlertShown = true;
                        showToast('Your 60-minute lunch timer has ended.', 'info', 6000);
                    }
                }
            } else if (lunchTimerEl && !lunchCompleted) {
                lunchTimerEl.textContent = '60:00';
            }

            if (quickBreakEl) {
                const liveQuick = onQuickBreak && quickStart ? Math.max(0, (now - quickStart) / 1000) : 0;
                const totalQuick = baseQuickSecs + liveQuick;
                const quickHours = Math.floor(totalQuick / 3600);
                const quickMinutes = Math.floor((totalQuick % 3600) / 60);
                const quickSeconds = Math.floor(totalQuick % 60);
                quickBreakEl.textContent = quickHours > 0
                    ? [quickHours, quickMinutes, quickSeconds].map((value, index) => index === 0 ? String(value) : String(value).padStart(2, '0')).join(':')
                    : [quickMinutes, quickSeconds].map(value => String(value).padStart(2, '0')).join(':');
            }
        };
        tick();
        setInterval(tick, 1000);
    }

    // ── Employee notification modal ─────────────────────────────────────────
    const notificationBell = document.getElementById('notification-bell');
    const notificationModal = document.getElementById('notification-modal');
    const notificationBox = notificationModal && notificationModal.querySelector('.notification-modal-box');
    const notificationBadge = document.getElementById('notification-badge');
    const notificationToolbar = document.getElementById('notification-modal-toolbar');
    const notificationUnreadText = document.getElementById('notification-unread-text');
    let notificationReturnFocus = null;

    const setNotificationCount = (count) => {
        const safeCount = Math.max(0, parseInt(count || '0', 10) || 0);
        if (notificationBadge) {
            notificationBadge.textContent = String(safeCount);
            notificationBadge.hidden = safeCount === 0;
        }
        if (notificationBell) {
            notificationBell.classList.toggle('has-dot', safeCount > 0);
            notificationBell.setAttribute('aria-label', safeCount > 0 ? `Open notifications, ${safeCount} unread` : 'Open notifications');
        }
        if (notificationUnreadText) notificationUnreadText.textContent = String(safeCount);
        if (notificationToolbar) notificationToolbar.hidden = safeCount === 0;
    };

    const openNotificationModal = () => {
        if (!notificationModal) return;
        notificationReturnFocus = document.activeElement;
        notificationModal.classList.add('active');
        notificationModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('modal-open');
        notificationBox && notificationBox.focus();
    };
    const closeNotificationModal = () => {
        if (!notificationModal) return;
        notificationModal.classList.remove('active');
        notificationModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('modal-open');
        if (notificationReturnFocus && typeof notificationReturnFocus.focus === 'function') notificationReturnFocus.focus();
    };

    notificationBell && notificationBell.addEventListener('click', openNotificationModal);
    notificationModal && notificationModal.querySelectorAll('[data-notification-close]').forEach(button => button.addEventListener('click', closeNotificationModal));
    notificationModal && notificationModal.addEventListener('click', event => {
        if (event.target === notificationModal) closeNotificationModal();
    });
    notificationModal && notificationModal.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            closeNotificationModal();
            return;
        }
        if (event.key !== 'Tab' || !notificationBox) return;
        const focusable = Array.from(notificationBox.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), [tabindex]:not([tabindex="-1"])'));
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });

    notificationModal && notificationModal.querySelectorAll('[data-notification-action]').forEach(button => {
        button.addEventListener('click', () => {
            const action = button.dataset.notificationAction || '';
            const notificationId = button.dataset.notificationId || '';
            const formData = new FormData();
            formData.append('csrf_token', window.__notificationCsrf || '');
            formData.append('action', action);
            if (notificationId) formData.append('notification_id', notificationId);
            button.disabled = true;
            fetch(window.__notificationUrl || 'notification_action.php', {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: formData
            })
                .then(async response => {
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Notification could not be updated.');
                    return payload;
                })
                .then(payload => {
                    if (action === 'mark_all_read') {
                        notificationModal.querySelectorAll('.notification-item').forEach(item => {
                            item.classList.remove('notification-unread');
                            item.dataset.notificationUnread = '0';
                            item.querySelector('.notification-new-label')?.remove();
                            item.querySelector('.notification-mark-read')?.remove();
                        });
                    } else {
                        const item = notificationModal.querySelector(`[data-notification-id="${notificationId}"]`);
                        if (item) {
                            item.classList.remove('notification-unread');
                            item.dataset.notificationUnread = '0';
                            item.querySelector('.notification-new-label')?.remove();
                            item.querySelector('.notification-mark-read')?.remove();
                        }
                    }
                    setNotificationCount(payload.unread_count);
                })
                .catch(error => {
                    button.disabled = false;
                    showToast(error.message || 'Notification could not be updated.', 'error');
                });
        });
    });

    // ── Employee leave calendar + request modal ─────────────────────────────
    const leaveRequestModal = document.getElementById('leave-request-modal');
    const leaveDetailModal = document.getElementById('leave-detail-modal');
    const openLeaveRequestBtn = document.getElementById('open-leave-request-modal');
    const closeLeaveRequestBtn = document.querySelector('[data-leave-request-close]');
    const closeLeaveDetailBtn = document.querySelector('[data-leave-detail-close]');
    const leaveRequestForm = document.getElementById('leave-request-form');
    const leaveStartDate = document.getElementById('leave_start_date');
    const leaveEndDate = document.getElementById('leave_end_date');

    const leaveDetailEmployee = document.getElementById('leave-detail-employee');
    const leaveDetailType = document.getElementById('leave-detail-type');
    const leaveDetailStart = document.getElementById('leave-detail-start');
    const leaveDetailEnd = document.getElementById('leave-detail-end');
    const leaveDetailDuration = document.getElementById('leave-detail-duration');
    const leaveCalendarGrid = document.getElementById('leave-calendar-grid');
    const leaveCalendarWrap = document.getElementById('leave-calendar-wrap');
    const leaveCalendarLoading = document.getElementById('leave-calendar-loading');
    const leaveCalendarError = document.getElementById('leave-calendar-error');
    const leaveCalendarTitle = document.getElementById('leave-calendar-title');
    const leaveMonthInput = document.getElementById('leave-month-input');
    const leavePrevMonth = document.getElementById('leave-prev-month');
    const leaveNextMonth = document.getElementById('leave-next-month');
    const leaveToday = document.getElementById('leave-today');

    const openModalById = function (modal) {
        if (!modal) return;
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        const box = modal.querySelector('.modal-box');
        if (box) box.focus();
    };

    const closeModalById = function (modal) {
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    };

    const correctionModal = document.getElementById('attendance-correction-modal');
    const correctionTitle = document.getElementById('attendance-correction-title');
    const correctionKind = document.getElementById('correction-kind');
    const correctionAttendanceId = document.getElementById('correction-attendance-id');
    const correctionDate = document.getElementById('correction-date');
    const correctionTimeIn = document.getElementById('correction-time-in');
    const correctionTimeOut = document.getElementById('correction-time-out');
    const correctionBreakStart = document.getElementById('correction-break-start');
    const correctionBreakEnd = document.getElementById('correction-break-end');
    const correctionReason = document.getElementById('correction-reason');
    document.querySelectorAll('[data-correction-open]').forEach(button => {
        button.addEventListener('click', () => {
            const existing = button.dataset.correctionKind === 'existing_record';
            if (correctionTitle) correctionTitle.textContent = existing ? 'Request Attendance Correction' : 'Report Missing Attendance';
            if (correctionKind) correctionKind.value = existing ? 'existing_record' : 'missing_record';
            if (correctionAttendanceId) correctionAttendanceId.value = existing ? (button.dataset.attendanceId || '') : '';
            if (correctionDate) {
                correctionDate.value = existing ? (button.dataset.attendanceDate || '') : '';
                correctionDate.readOnly = existing;
            }
            if (correctionTimeIn) correctionTimeIn.value = existing ? (button.dataset.timeIn || '') : '';
            if (correctionTimeOut) correctionTimeOut.value = existing ? (button.dataset.timeOut || '') : '';
            if (correctionBreakStart) correctionBreakStart.value = existing ? (button.dataset.breakStart || '') : '';
            if (correctionBreakEnd) correctionBreakEnd.value = existing ? (button.dataset.breakEnd || '') : '';
            if (correctionReason) correctionReason.value = '';
            openModalById(correctionModal);
        });
    });
    correctionModal?.querySelectorAll('[data-correction-close]').forEach(button => {
        button.addEventListener('click', () => closeModalById(correctionModal));
    });
    correctionModal?.addEventListener('click', event => {
        if (event.target === correctionModal) closeModalById(correctionModal);
    });
    correctionModal?.addEventListener('keydown', event => {
        if (event.key === 'Escape') closeModalById(correctionModal);
    });

    if (openLeaveRequestBtn && leaveRequestModal) {
        openLeaveRequestBtn.addEventListener('click', function () {
            openModalById(leaveRequestModal);
        });
    }

    if (closeLeaveRequestBtn && leaveRequestModal) {
        closeLeaveRequestBtn.addEventListener('click', function () {
            closeModalById(leaveRequestModal);
        });
    }

    if (closeLeaveDetailBtn && leaveDetailModal) {
        closeLeaveDetailBtn.addEventListener('click', function () {
            closeModalById(leaveDetailModal);
        });
    }

    if (leaveRequestModal) {
        leaveRequestModal.addEventListener('click', function (e) {
            if (e.target === leaveRequestModal) {
                closeModalById(leaveRequestModal);
            }
        });
    }

    if (leaveDetailModal) {
        leaveDetailModal.addEventListener('click', function (e) {
            if (e.target === leaveDetailModal) {
                closeModalById(leaveDetailModal);
            }
        });
    }

    if (leaveStartDate && leaveEndDate) {
        leaveStartDate.addEventListener('change', function () {
            leaveEndDate.min = leaveStartDate.value || '';
            if (leaveEndDate.value && leaveStartDate.value && leaveEndDate.value < leaveStartDate.value) {
                leaveEndDate.value = leaveStartDate.value;
            }
        });
    }

    if (leaveRequestForm && leaveStartDate && leaveEndDate) {
        leaveRequestForm.addEventListener('submit', function (e) {
            if ((document.querySelector('input[name="request_unit"]:checked')?.value || 'days') !== 'days') return;
            const start = (leaveStartDate.value || '').trim();
            const end = (leaveEndDate.value || '').trim();
            if (start && end && end < start) {
                e.preventDefault();
                showToast('End date cannot be before start date.', 'error');
            }
        });
    }

    if (leaveCalendarGrid) {
        leaveCalendarGrid.addEventListener('click', function (e) {
            const eventBtn = e.target.closest('.leave-event');
            if (!eventBtn) return;
            if (!leaveDetailModal) return;
            if (leaveDetailEmployee) leaveDetailEmployee.textContent = eventBtn.dataset.leaveEmployee || '';
            if (leaveDetailType) leaveDetailType.textContent = eventBtn.dataset.leaveType || '';
            if (leaveDetailStart) leaveDetailStart.textContent = eventBtn.dataset.leaveStart || '';
            if (leaveDetailEnd) leaveDetailEnd.textContent = eventBtn.dataset.leaveEnd || '';
            if (leaveDetailDuration) leaveDetailDuration.textContent = eventBtn.dataset.leaveDuration || '';
            openModalById(leaveDetailModal);
        });
    }

    const formatCalendarDate = (value) => {
        if (!value) return '';
        const parts = value.split('-').map(Number);
        if (parts.length !== 3) return value;
        return new Intl.DateTimeFormat('en-US', { month: 'long', day: 'numeric', year: 'numeric' })
            .format(new Date(parts[0], parts[1] - 1, parts[2]));
    };
    const shiftMonth = (month, amount) => {
        const match = String(month || '').match(/^(\d{4})-(\d{2})$/);
        if (!match) return '';
        const date = new Date(Number(match[1]), Number(match[2]) - 1 + amount, 1);
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    };
    const addCalendarDays = (value, amount) => {
        const parts = value.split('-').map(Number);
        const date = new Date(parts[0], parts[1] - 1, parts[2] + amount);
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    };

    const loadLeaveCalendar = (month) => {
        if (!leaveCalendarGrid || !leaveMonthInput) return;
        leaveCalendarLoading && (leaveCalendarLoading.hidden = false);
        leaveCalendarError && (leaveCalendarError.hidden = true);
        leaveCalendarWrap && (leaveCalendarWrap.hidden = true);
        fetch(`${window.__leaveCalendarUrl || 'leave_calendar_api.php'}?month=${encodeURIComponent(month)}`, { headers: { Accept: 'application/json' } })
            .then(async response => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || payload.error) throw new Error(payload.error || 'The leave calendar could not be loaded.');
                return payload;
            })
            .then(payload => {
                leaveCalendarGrid.innerHTML = '';
                const today = new Date();
                const todayKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
                for (let index = 0; index < 42; index++) {
                    const dateKey = addCalendarDays(payload.grid_start, index);
                    const day = document.createElement('div');
                    const events = payload.events_by_date[dateKey] || [];
                    day.className = 'leave-day';
                    if (!dateKey.startsWith(payload.month + '-')) day.classList.add('is-outside');
                    if (dateKey === todayKey) day.classList.add('is-today');
                    if (events.length) day.classList.add('has-event');

                    const dayNumber = document.createElement('div');
                    dayNumber.className = 'leave-day-number';
                    dayNumber.textContent = String(Number(dateKey.slice(-2)));
                    const eventList = document.createElement('div');
                    eventList.className = 'leave-day-events';
                    events.forEach(event => {
                        const eventButton = document.createElement('button');
                        eventButton.type = 'button';
                        eventButton.className = `leave-event leave-event-${event.kind}`;
                        eventButton.textContent = event.kind === 'holiday'
                            ? event.leave_type
                            : `${event.employee_name} · ${event.leave_type}${event.request_unit === 'hours' ? ` · ${event.duration_label}` : ''}`;
                        eventButton.dataset.leaveEmployee = event.employee_name;
                        eventButton.dataset.leaveType = event.leave_type;
                        eventButton.dataset.leaveStart = formatCalendarDate(event.start_date);
                        eventButton.dataset.leaveEnd = formatCalendarDate(event.end_date);
                        eventButton.dataset.leaveDuration = event.duration_label || '';
                        eventList.appendChild(eventButton);
                    });
                    day.append(dayNumber, eventList);
                    leaveCalendarGrid.appendChild(day);
                }
                leaveMonthInput.value = payload.month;
                if (leaveCalendarTitle) leaveCalendarTitle.textContent = payload.label;
                leaveCalendarLoading && (leaveCalendarLoading.hidden = true);
                leaveCalendarWrap && (leaveCalendarWrap.hidden = false);
                const nextUrl = new URL(window.location.href);
                nextUrl.searchParams.set('month', payload.month);
                window.history.replaceState({}, '', nextUrl);
            })
            .catch(error => {
                leaveCalendarLoading && (leaveCalendarLoading.hidden = true);
                if (leaveCalendarError) {
                    leaveCalendarError.textContent = error.message || 'The leave calendar could not be loaded.';
                    leaveCalendarError.hidden = false;
                }
            });
    };

    if (leaveMonthInput) {
        loadLeaveCalendar(window.__leaveInitialMonth || leaveMonthInput.value);
        leaveMonthInput.addEventListener('change', () => leaveMonthInput.value && loadLeaveCalendar(leaveMonthInput.value));
        leavePrevMonth && leavePrevMonth.addEventListener('click', () => loadLeaveCalendar(shiftMonth(leaveMonthInput.value, -1)));
        leaveNextMonth && leaveNextMonth.addEventListener('click', () => loadLeaveCalendar(shiftMonth(leaveMonthInput.value, 1)));
        leaveToday && leaveToday.addEventListener('click', () => {
            const today = new Date();
            loadLeaveCalendar(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`);
        });
    }

    // ── Leave balances and request preview ──────────────────────────────────
    let leaveDisplayUnit = 'days';
    const formatLeaveValue = (minutes, unit) => {
        const value = Number(minutes || 0) / (unit === 'hours' ? 60 : 480);
        return Number.isInteger(value) ? String(value) : value.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
    };
    const refreshLeaveUnits = (unit) => {
        leaveDisplayUnit = unit;
        document.querySelectorAll('.leave-balance-value[data-leave-minutes]').forEach(cell => {
            cell.textContent = formatLeaveValue(cell.dataset.leaveMinutes, unit);
        });
        document.querySelectorAll('[data-leave-unit]').forEach(button => {
            button.classList.toggle('is-active', button.dataset.leaveUnit === unit);
        });
    };
    document.querySelectorAll('[data-leave-unit]').forEach(button => {
        button.addEventListener('click', () => refreshLeaveUnits(button.dataset.leaveUnit || 'days'));
    });

    const balanceDetailModal = document.getElementById('leave-balance-detail-modal');
    const balanceDetailTitle = document.getElementById('leave-balance-detail-title');
    const balanceDetailCover = document.getElementById('leave-balance-cover');
    const balanceEarnedBody = document.getElementById('leave-balance-earned-body');
    const balanceUsedBody = document.getElementById('leave-balance-used-body');
    const appendDetailRow = (body, values, emptyLabel) => {
        if (!body) return;
        const row = document.createElement('tr');
        values.forEach(value => {
            const cell = document.createElement('td');
            cell.textContent = value;
            row.appendChild(cell);
        });
        if (!values.length) {
            const cell = document.createElement('td');
            cell.colSpan = 4;
            cell.className = 'table-empty-cell';
            cell.textContent = emptyLabel || 'No entries.';
            row.appendChild(cell);
        }
        body.appendChild(row);
    };
    document.querySelectorAll('.leave-balance-detail-button').forEach(button => {
        button.addEventListener('click', () => {
            const detail = (window.__leaveBalanceDetails || {})[button.dataset.balanceType || ''];
            if (!detail || !balanceDetailModal) return;
            if (balanceDetailTitle) balanceDetailTitle.textContent = `${detail.name} Balance`;
            if (balanceDetailCover) balanceDetailCover.textContent = `Cover period: January 1 – December 31, ${detail.year}`;
            if (balanceEarnedBody) {
                balanceEarnedBody.innerHTML = '';
                appendDetailRow(balanceEarnedBody, [`January 1, ${detail.year}`, 'Annual entitlement', `${formatLeaveValue(detail.annual_minutes, leaveDisplayUnit)} ${leaveDisplayUnit}`, 'Effective policy']);
                (detail.adjustments || []).forEach(adjustment => appendDetailRow(balanceEarnedBody, [
                    formatCalendarDate(adjustment.effective_date),
                    'Adjustment',
                    `${formatLeaveValue(adjustment.adjustment_minutes, leaveDisplayUnit)} ${leaveDisplayUnit}`,
                    adjustment.remarks || ''
                ]));
            }
            if (balanceUsedBody) {
                balanceUsedBody.innerHTML = '';
                if (!(detail.used || []).length) appendDetailRow(balanceUsedBody, [], 'No approved leave used in this period.');
                (detail.used || []).forEach(used => appendDetailRow(balanceUsedBody, [
                    `#${used.id}`,
                    `${formatCalendarDate(used.start_date)} – ${formatCalendarDate(used.end_date)}`,
                    `${formatLeaveValue(used.period_minutes, leaveDisplayUnit)} ${leaveDisplayUnit}`
                ]));
            }
            openModalById(balanceDetailModal);
        });
    });
    balanceDetailModal && balanceDetailModal.querySelector('[data-balance-detail-close]')?.addEventListener('click', () => closeModalById(balanceDetailModal));
    balanceDetailModal && balanceDetailModal.addEventListener('click', event => {
        if (event.target === balanceDetailModal) closeModalById(balanceDetailModal);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && balanceDetailModal?.classList.contains('active')) {
            closeModalById(balanceDetailModal);
        }
    });

    const leaveTypeSelect = document.getElementById('leave_type_id');
    const leaveDaysFields = document.getElementById('leave-days-fields');
    const leaveHoursFields = document.getElementById('leave-hours-fields');
    const leaveHourDate = document.getElementById('leave_hour_date');
    const leaveHoursRequested = document.getElementById('leave_hours_requested');
    const leaveRequestPreview = document.getElementById('leave-request-preview');
    const requestUnitInputs = document.querySelectorAll('input[name="request_unit"]');
    let previewTimer = null;

    const activeRequestUnit = () => document.querySelector('input[name="request_unit"]:checked')?.value || 'days';
    const toggleRequestUnit = () => {
        const hourly = activeRequestUnit() === 'hours';
        if (leaveDaysFields) leaveDaysFields.hidden = hourly;
        if (leaveHoursFields) leaveHoursFields.hidden = !hourly;
        if (leaveStartDate) { leaveStartDate.required = !hourly; leaveStartDate.disabled = hourly; }
        if (leaveEndDate) { leaveEndDate.required = !hourly; leaveEndDate.disabled = hourly; }
        if (leaveHourDate) { leaveHourDate.required = hourly; leaveHourDate.disabled = !hourly; }
        if (leaveHoursRequested) { leaveHoursRequested.required = hourly; leaveHoursRequested.disabled = !hourly; }
        scheduleLeavePreview();
    };

    const loadLeavePreview = () => {
        if (!leaveRequestPreview || !leaveTypeSelect) return;
        const unit = activeRequestUnit();
        const params = new URLSearchParams({ leave_type_id: leaveTypeSelect.value, request_unit: unit });
        if (!leaveTypeSelect.value) {
            leaveRequestPreview.textContent = 'Choose a leave type and dates to preview the balance.';
            leaveRequestPreview.classList.remove('is-negative');
            return;
        }
        if (unit === 'days') {
            if (!leaveStartDate?.value || !leaveEndDate?.value) return;
            params.set('start_date', leaveStartDate.value);
            params.set('end_date', leaveEndDate.value);
        } else {
            if (!leaveHourDate?.value || !leaveHoursRequested?.value) return;
            params.set('hour_date', leaveHourDate.value);
            params.set('hours_requested', leaveHoursRequested.value);
        }
        leaveRequestPreview.textContent = 'Calculating chargeable leave…';
        fetch(`${window.__leaveRequestPreviewUrl || 'leave_request_preview_api.php'}?${params}`, { headers: { Accept: 'application/json' } })
            .then(async response => {
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || payload.error) throw new Error(payload.error || 'Preview unavailable.');
                return payload;
            })
            .then(payload => {
                const parts = (payload.periods || []).map(period => {
                    return `${period.year}: available ${formatLeaveValue(period.available_minutes, unit)}, request ${formatLeaveValue(period.request_minutes, unit)}, projected ${formatLeaveValue(period.projected_minutes, unit)} ${unit}`;
                });
                leaveRequestPreview.textContent = `${payload.charge_count} chargeable workday${payload.charge_count === 1 ? '' : 's'} · ${parts.join(' · ')}`;
                leaveRequestPreview.classList.toggle('is-negative', (payload.periods || []).some(period => Number(period.projected_minutes) < 0));
            })
            .catch(error => {
                leaveRequestPreview.textContent = error.message || 'Preview unavailable.';
                leaveRequestPreview.classList.add('is-negative');
            });
    };
    function scheduleLeavePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(loadLeavePreview, 250);
    }
    requestUnitInputs.forEach(input => input.addEventListener('change', toggleRequestUnit));
    [leaveTypeSelect, leaveStartDate, leaveEndDate, leaveHourDate, leaveHoursRequested].forEach(input => input && input.addEventListener('input', scheduleLeavePreview));
    if (requestUnitInputs.length) toggleRequestUnit();

    // ── Admin leave modals ───────────────────────────────────────────────────
    const leaveViewModal = document.getElementById('leave-view-modal');
    const leaveActionModal = document.getElementById('leave-admin-action-modal');
    const leaveActionForm = document.getElementById('leave-admin-action-form');
    const leaveActionRequestId = document.getElementById('leave-action-request-id');
    const leaveActionName = document.getElementById('leave-action-name');
    const leaveActionMessage = document.getElementById('leave-action-message');
    const leaveActionComment = document.getElementById('leave-action-comment');
    const leaveActionBalance = document.getElementById('leave-action-balance');

    const lvEmployee = document.getElementById('lv-employee');
    const lvCompany = document.getElementById('lv-company');
    const lvPosition = document.getElementById('lv-position');
    const lvType = document.getElementById('lv-type');
    const lvStart = document.getElementById('lv-start');
    const lvEnd = document.getElementById('lv-end');
    const lvDays = document.getElementById('lv-days');
    const lvReason = document.getElementById('lv-reason');

    document.querySelectorAll('[data-leave-view="1"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!leaveViewModal) return;
            if (lvEmployee) lvEmployee.textContent = btn.dataset.employee || '';
            if (lvCompany) lvCompany.textContent = btn.dataset.company || '';
            if (lvPosition) lvPosition.textContent = btn.dataset.position || 'N/A';
            if (lvType) lvType.textContent = btn.dataset.type || '';
            if (lvStart) lvStart.textContent = btn.dataset.start || '';
            if (lvEnd) lvEnd.textContent = btn.dataset.end || '';
            if (lvDays) lvDays.textContent = btn.dataset.days || '0';
            if (lvReason) lvReason.textContent = btn.dataset.reason || '';
            openModalById(leaveViewModal);
        });
    });

    document.querySelectorAll('.leave-admin-action').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!leaveActionModal) return;
            const requestId = btn.dataset.requestId || '0';
            const action = btn.dataset.action || '';
            const label = btn.dataset.label || 'Confirm leave action';

            if (leaveActionRequestId) leaveActionRequestId.value = requestId;
            if (leaveActionName) leaveActionName.value = action;
            if (leaveActionMessage) leaveActionMessage.textContent = label + '. This action will be recorded in audit logs.';
            if (leaveActionComment) leaveActionComment.value = '';
            if (leaveActionBalance) {
                if (action === 'approve' && btn.dataset.balanceSummary !== undefined) {
                    leaveActionBalance.innerHTML = '';
                    const title = document.createElement('strong');
                    title.textContent = btn.dataset.balanceType || 'Leave balance';
                    const copy = document.createElement('span');
                    copy.textContent = btn.dataset.balanceSummary || 'Balance preview unavailable';
                    leaveActionBalance.append(title, copy);
                    leaveActionBalance.classList.toggle('is-negative', /after approval -/.test(copy.textContent));
                    leaveActionBalance.hidden = false;
                } else {
                    leaveActionBalance.hidden = true;
                }
            }
            openModalById(leaveActionModal);
        });
    });

    document.querySelectorAll('[data-leave-view-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModalById(leaveViewModal);
        });
    });

    document.querySelectorAll('[data-leave-action-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            closeModalById(leaveActionModal);
        });
    });

    if (leaveViewModal) {
        leaveViewModal.addEventListener('click', function (e) {
            if (e.target === leaveViewModal) closeModalById(leaveViewModal);
        });
    }

    if (leaveActionModal) {
        leaveActionModal.addEventListener('click', function (e) {
            if (e.target === leaveActionModal) closeModalById(leaveActionModal);
        });
    }

    // ── Shift assignment target ───────────────────────────────────────────────
    const shiftTargetType = document.getElementById('shift-target-type');
    const shiftEmployeeTarget = document.getElementById('shift-employee-target');
    const shiftCompanyTarget = document.getElementById('shift-company-target');
    if (shiftTargetType && shiftEmployeeTarget && shiftCompanyTarget) {
        const syncShiftTarget = function () {
            const companyMode = shiftTargetType.value === 'company';
            shiftEmployeeTarget.hidden = companyMode;
            shiftCompanyTarget.hidden = !companyMode;
            const employeeSelect = shiftEmployeeTarget.querySelector('select');
            const companySelect = shiftCompanyTarget.querySelector('select');
            if (employeeSelect) employeeSelect.disabled = companyMode;
            if (companySelect) companySelect.disabled = !companyMode;
        };
        shiftTargetType.addEventListener('change', syncShiftTarget);
        syncShiftTarget();
    }

    // ── Loading indicators on submit buttons ─────────────────────────────────
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            const submitBtn = form.querySelector('button[type="submit"][data-loading-text]');
            if (!submitBtn) return;
            submitBtn.dataset.originalText = submitBtn.textContent;
            submitBtn.textContent = submitBtn.dataset.loadingText;
            submitBtn.disabled = true;
        });
    });

    // ── Confirmation Modal ────────────────────────────────────────────────────
    const modalOverlay = document.getElementById('confirm-modal');
    let openModal = null;

    if (modalOverlay) {
        const titleEl   = modalOverlay.querySelector('.modal-title');
        const msgEl     = modalOverlay.querySelector('.modal-message');
        const confirmBtn = modalOverlay.querySelector('.modal-confirm');
        const cancelBtn  = modalOverlay.querySelector('.modal-cancel');
        let pending = null;

        openModal = (title, message, onConfirm) => {
            if (titleEl)  titleEl.textContent  = title;
            if (msgEl)    msgEl.textContent    = message;
            pending = onConfirm;
            modalOverlay.classList.add('active');
            confirmBtn && confirmBtn.focus();
        };

        const closeModal = () => {
            modalOverlay.classList.remove('active');
            pending = null;
        };

        cancelBtn  && cancelBtn.addEventListener('click', closeModal);
        confirmBtn && confirmBtn.addEventListener('click', () => {
            const action = pending;
            closeModal();
            if (typeof action === 'function') action();
        });
        modalOverlay.addEventListener('click', e => { if (e.target === modalOverlay) closeModal(); });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && !annModalOpen) closeModal(); });

        // data-confirm-form="formId" triggers
        document.querySelectorAll('[data-confirm-form]').forEach(trigger => {
            trigger.addEventListener('click', e => {
                e.preventDefault();
                const form = document.getElementById(trigger.dataset.confirmForm);
                openModal(
                    trigger.dataset.confirmTitle   || 'Are you sure?',
                    trigger.dataset.confirmMessage || 'This action cannot be undone.',
                    () => form && form.submit()
                );
            });
        });

        // data-confirm-href="url" triggers
        document.querySelectorAll('[data-confirm-href]').forEach(trigger => {
            trigger.addEventListener('click', e => {
                e.preventDefault();
                const href = trigger.dataset.confirmHref;
                openModal(
                    trigger.dataset.confirmTitle   || 'Are you sure?',
                    trigger.dataset.confirmMessage || '',
                    () => { window.location.href = href; }
                );
            });
        });

        // Logout intercept — inside this block so openModal is always available
        document.querySelectorAll('a[href*="logout.php"]').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                openModal(
                    'Log Out?',
                    'Are you sure you want to log out?',
                    () => { window.location.href = link.href; }
                );
            });
        });
    }

    // ── Employee Announcement Modal ───────────────────────────────────────────
    var annModalOpen = false;
    var annQueue = (typeof window.__announcements !== 'undefined') ? window.__announcements : [];

    if (annQueue.length > 0) {
        var overlay      = document.getElementById('ann-modal-overlay');
        var titleEl2     = document.getElementById('ann-modal-title');
        var contentEl    = document.getElementById('ann-modal-content');
        var counterEl    = document.getElementById('ann-counter');
        var dismissBtn   = document.getElementById('ann-ack-btn');
        var eyebrowEl    = document.getElementById('ann-modal-eyebrow');

        if (overlay) {
            var currentIdx = 0;
            annModalOpen = true;

            var priorityMeta = {
                normal:    { label: 'Normal',    cls: 'pill-gray' },
                important: { label: 'Important', cls: 'pill-yellow' },
                urgent:    { label: 'Urgent',    cls: 'pill-red' }
            };

            var renderAnn = function (idx) {
                var ann = annQueue[idx];
                if (!ann) return;

                // Reset priority class on overlay
                overlay.className = 'ann-modal-overlay';
                if (ann.priority === 'urgent')    overlay.classList.add('ann-modal-urgent');
                if (ann.priority === 'important') overlay.classList.add('ann-modal-important');

                var pm = priorityMeta[ann.priority] || priorityMeta.normal;
                var pinHtml = ann.pinned == 1 ? ' <span class="ann-pin-label">📌 Pinned</span>' : '';
                eyebrowEl.innerHTML = '<span class="pill ' + pm.cls + '">' + pm.label + '</span>' + pinHtml;

                titleEl2.textContent  = ann.title;
                contentEl.textContent = ann.content;

                var total = annQueue.length;
                counterEl.textContent = total > 1 ? (idx + 1) + ' of ' + total : '';

                var isLast = idx >= total - 1;
                dismissBtn.textContent = isLast ? 'Acknowledge' : 'Acknowledge & Next';

                overlay.style.display = 'flex';
            };

            var markRead = function (ann, cb) {
                var fd = new FormData();
                fd.append('csrf_token', window.__annCsrf || '');
                fd.append('announcement_id', ann.id);
                fd.append('dismissed', '1');
                fetch(window.__annReadUrl || 'announcement_read.php', { method: 'POST', body: fd })
                    .then(function () { if (cb) cb(); })
                    .catch(function () { if (cb) cb(); });
            };

            dismissBtn && dismissBtn.addEventListener('click', function () {
                markRead(annQueue[currentIdx], function () {
                    currentIdx++;
                    if (currentIdx < annQueue.length) {
                        renderAnn(currentIdx);
                    } else {
                        overlay.style.display = 'none';
                        annModalOpen = false;
                    }
                });
            });

            renderAnn(0);
        }
    }
});
