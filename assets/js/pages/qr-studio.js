(function(){
    function qs(selector, root){ return (root || document).querySelector(selector); }
    var dirty = false;

    function setStatus(message){
        var status = qs(".qrb-preview-status");
        if (status) { status.textContent = message; }
    }

    function refreshPreview(){
        var form = qs(".qrb-studio-form");
        var target = qs(".qrb-live-preview");
        if (!form || !target || !window.QRBuzzApp) { return; }
        var data = new FormData(form);
        data.set("action", "qrbuzz_preview");
        data.set("nonce", window.QRBuzzApp.previewNonce);
        target.setAttribute("aria-busy", "true");
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
                dirty = true;
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
            dirty = true;
            schedulePreview();
        }
    });

    document.addEventListener("change", function(event){
        if (!event.target.closest(".qrb-studio-form")) { return; }
        dirty = true;
        if (event.target.classList.contains("qrb-theme-select")) {
            var option = event.target.selectedOptions[0];
            var form = event.target.closest("form");
            if (option && form) {
                qs("[name=foreground_color]", form).value = option.dataset.foreground || "#000000";
                qs("[name=background_color]", form).value = option.dataset.background || "#ffffff";
                qs("[name=margin]", form).value = option.dataset.margin || "12";
                qs("[name=error_correction]", form).value = option.dataset.error || "H";
            }
        }
        schedulePreview();
    });

    document.addEventListener("input", function(event){
        if (event.target.closest(".qrb-studio-form")) {
            dirty = true;
            schedulePreview();
        }
    });

    document.addEventListener("submit", function(event){
        var form = event.target.closest(".qrb-studio-form");
        if (!form) { return; }
        dirty = false;
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

    window.qrbuzzRefreshPreview = refreshPreview;
})();
