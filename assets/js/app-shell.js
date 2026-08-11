(function(){
    var doc = document;
    var body = doc.body;

    function qs(selector, root){ return (root || doc).querySelector(selector); }
    function qsa(selector, root){ return Array.prototype.slice.call((root || doc).querySelectorAll(selector)); }

    function closeDrawer(){
        body.classList.remove("qrb-drawer-open");
        var button = qs("[data-qrb-drawer-toggle]");
        if (button) { button.setAttribute("aria-expanded", "false"); }
    }

    function openDrawer(){
        body.classList.add("qrb-drawer-open");
        var button = qs("[data-qrb-drawer-toggle]");
        if (button) { button.setAttribute("aria-expanded", "true"); }
    }

    function handleDismiss(event){
        var dismiss = event.target.closest("[data-qrb-dismiss], [data-qrb-toast-close], .qrb-toast-close, .notice-dismiss, [aria-label='Dismiss notification'], [aria-label='Dismiss tip']");
        if (!dismiss) { return false; }
        event.preventDefault();
        event.stopPropagation();
        var target = dismiss.closest("[data-qrb-dismissible]");
        if (target) {
            var key = target.getAttribute("data-qrb-dismissible");
            target.remove();
            try { window.localStorage.setItem("qrb-dismissed-" + key, "1"); } catch (e) {}
            return true;
        }
        target = dismiss.closest("[data-qrb-toast], .qrb-toast, .qrb-tip, .qrb-alert, .notice");
        if (target) { target.remove(); }
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            ["saved", "created", "welcome", "deleted", "error", "verify"].forEach(function(key){ url.searchParams.delete(key); });
            window.history.replaceState({}, "", url.pathname + url.search + url.hash);
        }
        return true;
    }

    doc.addEventListener("click", function(event){
        if (handleDismiss(event)) { return; }

        var drawerButton = event.target.closest("[data-qrb-drawer-toggle]");
        if (drawerButton) {
            event.preventDefault();
            body.classList.contains("qrb-drawer-open") ? closeDrawer() : openDrawer();
            return;
        }

        if (event.target.closest("[data-qrb-drawer-close]")) {
            closeDrawer();
            return;
        }

        var confirmAction = event.target.closest("[data-confirm]");
        if (confirmAction && !window.confirm(confirmAction.getAttribute("data-confirm"))) {
            event.preventDefault();
        }

        var viewButton = event.target.closest("[data-qrb-view]");
        if (viewButton) {
            event.preventDefault();
            var view = viewButton.getAttribute("data-qrb-view");
            qsa("[data-qrb-library-view]").forEach(function(panel){ panel.hidden = panel.getAttribute("data-qrb-library-view") !== view; });
            qsa("[data-qrb-view]").forEach(function(button){ button.classList.toggle("qrb-button-primary", button === viewButton); });
            try { window.localStorage.setItem("qrb-library-view", view); } catch (e) {}
        }
    }, true);

    doc.addEventListener("keydown", function(event){
        if (event.key === "Escape") { closeDrawer(); }
    });

    doc.addEventListener("DOMContentLoaded", function(){
        var preferred = "table";
        try { preferred = window.localStorage.getItem("qrb-library-view") || preferred; } catch (e) {}
        var button = qs("[data-qrb-view=\"" + preferred + "\"]");
        if (button) { button.click(); }

        qsa("[data-qrb-dismissible]").forEach(function(tip){
            var key = tip.getAttribute("data-qrb-dismissible");
            try { if (window.localStorage.getItem("qrb-dismissed-" + key) === "1") { tip.hidden = true; } } catch (e) {}
        });
    });

    window.QRBuzzUI = { qs: qs, qsa: qsa, closeDrawer: closeDrawer };
})();
