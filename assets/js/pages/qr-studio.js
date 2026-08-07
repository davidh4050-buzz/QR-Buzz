(function(){
    function qs(selector, root){ return (root || document).querySelector(selector); }
    var dirty = false;

    function setStatus(message){
        var status = qs(".qrb-preview-status");
        if (status) { status.textContent = message; }
    }

    function setDirty(value){
        dirty = value;
        var indicator = qs("[data-qrb-unsaved]");
        if (indicator) { indicator.hidden = !value; }
    }

    function refreshPreview(){
        var form = qs(".qrb-studio-form");
        var target = qs(".qrb-live-preview");
        if (!form || !target || !window.QRBuzzApp) { return; }
        var data = new FormData(form);
        data.set("action", "qrbuzz_preview");
        data.set("nonce", window.QRBuzzApp.previewNonce);
        target.setAttribute("aria-busy", "true");
        if (!target.querySelector("img")) {
            target.innerHTML = "<span class=\"qrb-loading\">Generating preview</span>";
        }
        setStatus("Refreshing preview...");
        fetch(window.QRBuzzApp.ajaxUrl, { method: "POST", credentials: "same-origin", body: data })
            .then(function(response){ return response.json(); })
            .then(function(result){
                if (result.success && result.data && result.data.data_uri) {
                    target.innerHTML = "<img class=\"qrb-preview\" src=\"" + result.data.data_uri + "\" alt=\"QR code preview\">";
                    setStatus(result.data.message || "Preview refreshed.");
                    return;
                }
                target.innerHTML = "<p>" + (result.data && result.data.message ? result.data.message : "Preview unavailable.") + "</p>";
                setStatus("Preview could not be generated.");
            })
            .catch(function(){ setStatus("Preview could not be generated."); })
            .finally(function(){ target.removeAttribute("aria-busy"); });
    }

    function schedulePreview(){
        clearTimeout(window.qrbuzzHostedPreviewTimer);
        window.qrbuzzHostedPreviewTimer = setTimeout(refreshPreview, 450);
    }

    function syncColorText(input){
        var wrap = input.closest(".qrb-color-control");
        var text = wrap ? qs("[data-qrb-color-text]", wrap) : null;
        if (text) { text.value = input.value.toLowerCase(); }
    }

    function syncColorInput(text){
        var value = text.value.trim();
        if (!/^#[0-9a-fA-F]{6}$/.test(value)) { return false; }
        var wrap = text.closest(".qrb-color-control");
        var input = wrap ? qs("[data-qrb-color-input]", wrap) : null;
        if (input) { input.value = value.toLowerCase(); }
        text.value = value.toLowerCase();
        return true;
    }

    document.addEventListener("click", function(event){
        var refresh = event.target.closest(".qrb-refresh-preview");
        if (refresh) { event.preventDefault(); refreshPreview(); }

        var selectLogo = event.target.closest(".qrb-select-logo");
        if (selectLogo) {
            event.preventDefault();
            if (!window.wp || !wp.media) { return; }
            var wrap = selectLogo.closest("label");
            var field = qs(".qrb-logo-id", wrap);
            var preview = qs(".qrb-logo-preview", wrap);
            var remove = qs(".qrb-remove-logo", wrap);
            var frame = wp.media({ title: "Choose QR logo", button: { text: "Use this logo" }, multiple: false, library: { type: "image" } });
            frame.on("select", function(){
                var attachment = frame.state().get("selection").first().toJSON();
                field.value = attachment.id;
                preview.innerHTML = "<img src=\"" + ((attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url) + "\" alt=\"\">";
                if (remove) { remove.disabled = false; }
                setDirty(true);
                schedulePreview();
            });
            frame.open();
        }

        var removeLogo = event.target.closest(".qrb-remove-logo");
        if (removeLogo) {
            event.preventDefault();
            var logoWrap = removeLogo.closest("label");
            qs(".qrb-logo-id", logoWrap).value = "0";
            qs(".qrb-logo-preview", logoWrap).innerHTML = "";
            removeLogo.disabled = true;
            setDirty(true);
            schedulePreview();
        }
    });

    document.addEventListener("change", function(event){
        if (!event.target.closest(".qrb-studio-form")) { return; }
        setDirty(true);
        if (event.target.classList.contains("qrb-theme-select")) {
            var option = event.target.selectedOptions[0];
            var form = event.target.closest("form");
            if (option && form) {
                qs("[name=foreground_color]", form).value = option.dataset.foreground || "#000000";
                qs("[name=background_color]", form).value = option.dataset.background || "#ffffff";
                qs("[name=margin]", form).value = option.dataset.margin || "12";
                qs("[name=error_correction]", form).value = option.dataset.error || "H";
                if (qs("[name=finder_color]", form)) {
                    qs("[name=finder_color]", form).value = option.dataset.foreground || "#000000";
                }
                Array.prototype.slice.call(form.querySelectorAll("[data-qrb-color-input]")).forEach(syncColorText);
            }
        }
        schedulePreview();
    });

    document.addEventListener("input", function(event){
        if (event.target.matches("[data-qrb-color-input]")) {
            syncColorText(event.target);
        }
        if (event.target.matches("[data-qrb-color-text]") && !syncColorInput(event.target)) {
            return;
        }
        if (event.target.closest(".qrb-studio-form")) {
            setDirty(true);
            schedulePreview();
        }
    });

    document.addEventListener("toggle", function(event){
        var details = event.target.closest && event.target.closest("[data-qrb-accordion]");
        if (!details) { return; }
        try { window.localStorage.setItem("qrb-studio-section-" + details.getAttribute("data-qrb-accordion"), details.open ? "1" : "0"); } catch (e) {}
    }, true);

    document.addEventListener("submit", function(event){
        var form = event.target.closest(".qrb-studio-form");
        if (!form) { return; }
        setDirty(false);
        var button = qs("button[type=submit], .qrb-submit", form);
        if (button) {
            button.classList.add("is-loading");
            button.disabled = true;
            button.textContent = button.getAttribute("data-loading-label") || "Saving...";
        }
    });

    window.addEventListener("beforeunload", function(event){
        if (!dirty) { return; }
        event.preventDefault();
        event.returnValue = "";
    });

    document.addEventListener("DOMContentLoaded", function(){
        Array.prototype.slice.call(document.querySelectorAll("[data-qrb-color-input]")).forEach(syncColorText);
        Array.prototype.slice.call(document.querySelectorAll("[data-qrb-accordion]")).forEach(function(details){
            try {
                var value = window.localStorage.getItem("qrb-studio-section-" + details.getAttribute("data-qrb-accordion"));
                if (value === "1") { details.open = true; }
                if (value === "0") { details.open = false; }
            } catch (e) {}
        });
    });

    window.qrbuzzRefreshPreview = refreshPreview;
})();
