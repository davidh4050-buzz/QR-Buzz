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

    doc.addEventListener("click", function(event){
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

        var viewButton = event.target.closest("[data-qrb-view]");
        if (viewButton) {
            event.preventDefault();
            var view = viewButton.getAttribute("data-qrb-view");
            qsa("[data-qrb-library-view]").forEach(function(panel){ panel.hidden = panel.getAttribute("data-qrb-library-view") !== view; });
            qsa("[data-qrb-view]").forEach(function(button){ button.classList.toggle("qrb-button-primary", button === viewButton); });
            try { window.localStorage.setItem("qrb-library-view", view); } catch (e) {}
        }
    });

    doc.addEventListener("keydown", function(event){
        if (event.key === "Escape") { closeDrawer(); }
    });

    doc.addEventListener("DOMContentLoaded", function(){
        var preferred = "table";
        try { preferred = window.localStorage.getItem("qrb-library-view") || preferred; } catch (e) {}
        var button = qs("[data-qrb-view=\"" + preferred + "\"]");
        if (button) { button.click(); }
    });

    window.QRBuzzUI = { qs: qs, qsa: qsa, closeDrawer: closeDrawer };
})();
