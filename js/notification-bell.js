// Notification bell: fetch unread count and update badge in header
(function(){
  'use strict';

  // Support both admin and janitor bell badges. If janitor badge exists, use janitor API.
  const BADGE_ID = document.getElementById('janitorNotificationCount') ? 'janitorNotificationCount' : 'notificationCount';
  // For janitor badge, poll the janitor alerts endpoint which returns alerts array.
  const FETCH_URL = (BADGE_ID === 'janitorNotificationCount') ? 'janitor-alerts.php?action=get_alerts' : 'ajax/get-unread-count.php';
  const POLL_INTERVAL = 5000; // 5s to match existing auto-refresh

  function setBadge(count) {
    const el = document.getElementById(BADGE_ID);
    if (!el) return;
    count = parseInt(count, 10) || 0;
    if (count <= 0) {
      el.style.display = 'none';
      el.textContent = '0';
    } else {
      el.style.display = '';
      el.textContent = count > 99 ? '99+' : String(count);
    }
  }

  // Fetch notifications list and update unread count only
  function fetchAndUpdateNotificationCount() {
    return fetch(FETCH_URL, { credentials: 'same-origin' })
      .then(resp => resp.json())
      .then(data => {
        if (!data) return setBadge(0);
        let unread = 0;
        // janitor endpoint returns { success:true, alerts: [...] }
        if (data.alerts && Array.isArray(data.alerts)) {
          unread = data.alerts.reduce((acc, a) => acc + ((parseInt(a.is_read || 0, 10) === 0) ? 1 : 0), 0);
        } else if (typeof data.unread_count !== 'undefined') {
          unread = parseInt(data.unread_count || 0, 10) || 0;
        } else if (Array.isArray(data.notifications)) {
          unread = data.notifications.reduce((acc, n) => acc + ((parseInt(n.is_read || 0, 10) === 0) ? 1 : 0), 0);
        }
        setBadge(unread);
        // dispatch event for other pages to react immediately
        try { document.dispatchEvent(new CustomEvent('notifications:updated', { detail: { unread } })); } catch(e){}
        return unread;
      }).catch(err => {
        // silently fail
        return 0;
      });
  }

  // Expose globally for other scripts to call after mutating notifications
  window.fetchAndUpdateNotificationCount = fetchAndUpdateNotificationCount;

  // Listen for external updates (other scripts can dispatch with detail.unread)
  document.addEventListener('notifications:updated', function(e){
    try {
      const unread = e && e.detail && typeof e.detail.unread !== 'undefined' ? parseInt(e.detail.unread, 10) : null;
      if (unread !== null && !isNaN(unread)) setBadge(unread);
    } catch(err){}
  });

  // Initialize on DOM ready
  function init() {
    // initial fetch
    fetchAndUpdateNotificationCount();

    // periodic poll
    setInterval(fetchAndUpdateNotificationCount, POLL_INTERVAL);

    // allow click on bell to open notifications page (fallback)
    document.addEventListener('click', function(e){
      const btn = e.target.closest && (e.target.closest('#notificationsBtn') || e.target.closest('#janitorNotificationsBtn'));
      if (!btn) return;
      e.preventDefault();
      // prefer existing handler; fallback to navigate
      if (typeof openNotificationsModal === 'function') return openNotificationsModal(e);
      if (typeof showModalById === 'function') return showModalById('notificationsModal');
      // fallback navigation based on which button was clicked
      if (btn.id === 'janitorNotificationsBtn') return window.location.href = 'janitor-alerts.php';
      return window.location.href = 'notifications.php';
    }, true);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
