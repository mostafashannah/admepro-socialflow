/* admepro.com visitor counter → SocialFlow (no cookies, no personal data). Add once to every page:
   <script defer src="https://socialflow.admepro.com/site-track.js"></script> */
(function () {
  var URL_ = "https://socialflow.admepro.com/site-track.php";
  if (navigator.doNotTrack === "1" || /^(localhost|127\.)/.test(location.hostname)) return;
  var q = new URLSearchParams(location.search), u = {};
  ["source", "medium", "campaign"].forEach(function (k) { var v = q.get("utm_" + k); if (v) u[k] = v; });
  function send(e, extra) {
    var d = { e: e, p: location.pathname, t: document.title, r: document.referrer, u: u, l: navigator.language, z: (function(){ try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ""; } catch (x) { return ""; } })() };
    for (var k in extra) d[k] = extra[k];
    var b = JSON.stringify(d);
    if (navigator.sendBeacon) navigator.sendBeacon(URL_, new Blob([b], { type: "text/plain" }));
    else fetch(URL_, { method: "POST", body: b, keepalive: true, mode: "cors" });
  }
  var start = Date.now(), left = false;
  send("pageview", {});
  function leave() { if (left) return; left = true; send("leave", { s: Math.round((Date.now() - start) / 1000) }); }
  addEventListener("pagehide", leave);
  document.addEventListener("visibilitychange", function () { if (document.visibilityState === "hidden") leave(); });
  // Count contact / WhatsApp / phone clicks as conversions.
  document.addEventListener("click", function (ev) {
    var a = ev.target.closest && ev.target.closest("a[href]"); if (!a) return;
    var h = a.getAttribute("href") || "";
    if (/^(tel:|mailto:)|wa\.me|whatsapp/i.test(h)) send("click", { t: h.slice(0, 120) });
  }, true);
  window.sfTrackLead = function () { send("lead", {}); };
})();
