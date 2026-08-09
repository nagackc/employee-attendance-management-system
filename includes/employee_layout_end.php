            </main>
        </div>
    </div>

    <div id="notification-modal" class="modal-overlay notification-modal" role="dialog" aria-modal="true" aria-labelledby="notification-modal-title" aria-hidden="true">
        <div class="modal-box notification-modal-box" tabindex="-1">
            <div class="notification-modal-header">
                <div>
                    <span class="employee-eyebrow">Updates</span>
                    <h2 id="notification-modal-title">Notifications</h2>
                </div>
                <button type="button" class="icon-btn notification-modal-close" data-notification-close aria-label="Close notifications">
                    <svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <?php if ($layoutUnreadNotificationCount > 0): ?>
                <div class="notification-modal-toolbar" id="notification-modal-toolbar">
                    <span><strong id="notification-unread-text"><?= $layoutUnreadNotificationCount ?></strong> unread</span>
                    <button type="button" class="link-button" data-notification-action="mark_all_read">Mark all read</button>
                </div>
            <?php else: ?>
                <div class="notification-modal-toolbar" id="notification-modal-toolbar" hidden>
                    <span><strong id="notification-unread-text">0</strong> unread</span>
                    <button type="button" class="link-button" data-notification-action="mark_all_read">Mark all read</button>
                </div>
            <?php endif; ?>

            <div class="notification-list" id="notification-list">
                <?php if ($layoutNotifications): ?>
                    <?php foreach ($layoutNotifications as $notification): ?>
                        <?php $isUnread = (int)$notification['is_read'] === 0; ?>
                        <article class="notification-item<?= $isUnread ? ' notification-unread' : '' ?>" data-notification-id="<?= (int)$notification['id'] ?>" data-notification-unread="<?= $isUnread ? '1' : '0' ?>">
                            <div class="notification-item-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
                            </div>
                            <div class="notification-item-body">
                                <div class="notification-item-title">
                                    <strong><?= h($notification['title']) ?></strong>
                                    <?php if ($isUnread): ?><span class="notification-new-label">New</span><?php endif; ?>
                                </div>
                                <p><?= h($notification['message']) ?></p>
                                <time><?= h(formatEmployeeDate(substr((string)$notification['created_at'], 0, 10))) ?> · <?= h(formatEmployeeTime((string)$notification['created_at'], date_default_timezone_get())) ?></time>
                                <?php if ($isUnread): ?>
                                    <button type="button" class="link-button notification-mark-read" data-notification-action="mark_read" data-notification-id="<?= (int)$notification['id'] ?>">Mark as read</button>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No notifications yet.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/modal.php'; ?>
    <script>
        window.__notificationUrl = 'notification_action.php';
        window.__notificationCsrf = '<?= h(generateCsrfToken()) ?>';
    </script>
    <script src="../assets/js/app.js?v=<?= (int)filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
