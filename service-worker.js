// SocialFlow — Web Push service worker
// Handles incoming push events (works even when the app is closed) and
// notification clicks (focuses an open tab or opens a new one).

self.addEventListener("push", (event) => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; } catch(e) { data = {title:"SocialFlow", body: event.data ? event.data.text() : ""}; }
  const title = data.title || "SocialFlow";
  const options = {
    body: data.body || "",
    icon: "/icon-192.png",
    badge: "/icon-192.png",
    data: { url: data.url || "/" },
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const url = event.notification.data?.url || "/";
  event.waitUntil(
    self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clientsList) => {
      for(const client of clientsList) {
        if("focus" in client) { client.navigate(url); return client.focus(); }
      }
      if(self.clients.openWindow) return self.clients.openWindow(url);
    })
  );
});
