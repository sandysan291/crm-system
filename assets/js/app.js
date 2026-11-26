const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');

if (menuToggle && sidebar) {
  menuToggle.addEventListener('click', () => {
    const isOpen = sidebar.classList.toggle('open');
    menuToggle.setAttribute('aria-expanded', String(isOpen));
  });

  document.addEventListener('click', (event) => {
    if (window.innerWidth <= 820 && sidebar.classList.contains('open')
      && !sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
      sidebar.classList.remove('open');
      menuToggle.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar.classList.contains('open')) {
      sidebar.classList.remove('open');
      menuToggle.setAttribute('aria-expanded', 'false');
      menuToggle.focus();
    }
  });
}

document.querySelectorAll('form.record-form').forEach((form) => {
  form.addEventListener('submit', () => {
    if (!form.reportValidity()) return;

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.classList.add('is-loading');
      submitButton.setAttribute('aria-busy', 'true');
      submitButton.disabled = true;
    }
  });
});

const notificationToggle = document.getElementById('notificationToggle');
const notificationPanel = document.getElementById('notificationPanel');
const notificationCount = document.getElementById('notificationCount');
const notificationList = document.getElementById('notificationList');
const notificationSummary = document.getElementById('notificationSummary');
const notificationError = document.getElementById('notificationError');
const markAllRead = document.getElementById('markAllRead');
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

const targetPages = new Set(['dashboard', 'customers', 'leads', 'deals', 'tasks', 'activities']);

function notificationUrl(item) {
  if (!targetPages.has(item.target)) return '?page=dashboard';
  return item.target_id
    ? `?page=${encodeURIComponent(item.target)}&edit=${encodeURIComponent(item.target_id)}`
    : `?page=${encodeURIComponent(item.target)}`;
}

function renderNotifications(data) {
  const unread = Number(data.unread) || 0;
  notificationCount.hidden = unread === 0;
  notificationCount.textContent = unread > 99 ? '99+' : String(unread);
  notificationToggle.setAttribute('aria-label', unread ? `Notifications, ${unread} unread` : 'Notifications');
  notificationSummary.textContent = unread ? `${unread} unread` : 'You’re all caught up';
  markAllRead.disabled = unread === 0;

  notificationList.replaceChildren();
  if (!data.items.length) {
    const empty = document.createElement('p');
    empty.className = 'notification-empty';
    empty.textContent = 'New customer, lead, and task updates will appear here.';
    notificationList.append(empty);
    return;
  }

  data.items.forEach((item) => {
    const row = document.createElement('div');
    row.className = `notification-item${item.read_at ? '' : ' unread'}`;
    const link = document.createElement('a');
    link.className = 'notification-content';
    link.href = notificationUrl(item);
    const title = document.createElement('strong');
    title.textContent = item.title;
    const message = document.createElement('span');
    message.textContent = item.message;
    const time = document.createElement('time');
    time.dateTime = item.created_at;
    const createdAt = new Date(`${item.created_at.replace(' ', 'T')}${/Z|[+-]\d\d:\d\d$/.test(item.created_at) ? '' : 'Z'}`);
    time.textContent = Number.isNaN(createdAt.getTime()) ? item.created_at : createdAt.toLocaleString();
    link.append(title, message, time);
    row.append(link);

    if (!item.read_at) {
      const readButton = document.createElement('button');
      readButton.className = 'notification-read';
      readButton.type = 'button';
      readButton.textContent = 'Mark read';
      readButton.addEventListener('click', () => updateNotificationRead(item.id));
      row.append(readButton);
    }
    notificationList.append(row);
  });
}

async function loadNotifications() {
  if (!notificationList) return;
  try {
    const response = await fetch('?page=api&action=notifications', {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Could not load notifications.');
    renderNotifications(data);
    notificationError.hidden = true;
  } catch (error) {
    notificationError.textContent = error.message || 'Could not load notifications.';
    notificationError.hidden = false;
  }
}

async function updateNotificationRead(id = null) {
  const form = new URLSearchParams({ csrf: csrfToken || '' });
  const action = id ? 'mark_notification_read' : 'mark_notifications_read';
  if (id) form.set('id', String(id));

  try {
    const response = await fetch(`?page=api&action=${action}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
      body: form,
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Could not update notifications.');
    await loadNotifications();
  } catch (error) {
    notificationError.textContent = error.message || 'Could not update notifications.';
    notificationError.hidden = false;
  }
}

if (notificationToggle && notificationPanel) {
  notificationToggle.addEventListener('click', () => {
    const open = notificationPanel.hidden;
    notificationPanel.hidden = !open;
    notificationToggle.setAttribute('aria-expanded', String(open));
    if (open) loadNotifications();
  });

  document.addEventListener('click', (event) => {
    if (!notificationPanel.hidden && !notificationPanel.contains(event.target)
      && !notificationToggle.contains(event.target)) {
      notificationPanel.hidden = true;
      notificationToggle.setAttribute('aria-expanded', 'false');
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !notificationPanel.hidden) {
      notificationPanel.hidden = true;
      notificationToggle.setAttribute('aria-expanded', 'false');
      notificationToggle.focus();
    }
  });

  markAllRead.addEventListener('click', () => updateNotificationRead());
  loadNotifications();
  window.setInterval(loadNotifications, 20000);
}

const liveStats = document.querySelectorAll('[data-live-stat], [data-live-money]');
if (liveStats.length) {
  async function refreshDashboard() {
    try {
      const response = await fetch('?page=api&action=dashboard', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      const snapshot = await response.json();
      if (!response.ok) throw new Error(snapshot.error || 'Dashboard refresh failed.');
      document.querySelectorAll('[data-live-stat]').forEach((element) => {
        if (Object.hasOwn(snapshot, element.dataset.liveStat)) {
          element.textContent = String(snapshot[element.dataset.liveStat]);
        }
      });
      document.querySelectorAll('[data-live-money]').forEach((element) => {
        if (Object.hasOwn(snapshot, element.dataset.liveMoney)) {
          element.textContent = new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: 'USD',
          }).format(snapshot[element.dataset.liveMoney]);
        }
      });
    } catch (error) {
      console.error(error);
    }
  }

  window.setInterval(refreshDashboard, 30000);
}
