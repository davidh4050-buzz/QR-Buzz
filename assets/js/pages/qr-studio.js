(function(){
    function qs(selector, root){ return (root || document).querySelector(selector); }
    function qsa(selector, root){ return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }
    var dirty = false;
    var activeLogoWrap = null;

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

    function ajaxData(action){
        var data = new FormData();
        data.set("action", action);
        data.set("nonce", window.QRBuzzApp.assetsNonce);
        return data;
    }

    function fetchAssets(){
        var data = ajaxData("qrbuzz_assets_list");
        return fetch(window.QRBuzzApp.ajaxUrl, { method: "POST", credentials: "same-origin", body: data })
            .then(function(response){ return response.json(); })
            .then(function(result){ return result.success && result.data ? result.data.assets || [] : []; });
    }

    function uploadAsset(file, statusNode){
        if (!file) { return Promise.resolve(null); }
        if (window.QRBuzzApp.maxAssetBytes && file.size > window.QRBuzzApp.maxAssetBytes) {
            if (statusNode) { statusNode.textContent = "Images must be 5 MB or smaller."; }
            return Promise.resolve(null);
        }
        var data = ajaxData("qrbuzz_assets_upload");
        data.set("asset", file);
        if (statusNode) { statusNode.textContent = "Uploading..."; }
        return fetch(window.QRBuzzApp.ajaxUrl, { method: "POST", credentials: "same-origin", body: data })
            .then(function(response){ return response.json(); })
            .then(function(result){
                if (!result.success) { throw new Error(result.data && result.data.message ? result.data.message : "Upload failed."); }
                if (statusNode) { statusNode.textContent = result.data.message || "Uploaded."; }
                return result.data.asset;
            })
            .catch(function(error){ if (statusNode) { statusNode.textContent = error.message; } return null; });
    }

    function assetCard(asset, picker){
        var article = document.createElement("article");
        article.className = picker ? "qrb-asset-picker-card" : "qrb-asset-card";
        article.dataset.qrbAssetId = asset.id;
        article.dataset.qrbAssetUrl = asset.url;
        article.dataset.qrbAssetName = asset.display_name;
        article.dataset.qrbAssetUsage = asset.usage_count || 0;
        var meta = asset.width && asset.height ? asset.width + " x " + asset.height + " px" : "Image asset";
        article.innerHTML = "<div class=\"qrb-asset-thumb\"><img src=\"" + asset.url + "\" alt=\"\"></div><div><h3>" + escapeHtml(asset.display_name) + "</h3><p>" + escapeHtml(meta) + "</p></div>";
        if (picker) {
            article.innerHTML += "<button type=\"button\" class=\"qrb-button qrb-button-primary\" data-qrb-asset-pick>Use logo</button>";
        } else {
            article.innerHTML += "<div class=\"qrb-asset-actions\"><button type=\"button\" class=\"qrb-button qrb-icon-button\" data-qrb-asset-rename title=\"Rename\" aria-label=\"Rename\">" + icon("edit") + "</button><button type=\"button\" class=\"qrb-button qrb-icon-button\" data-qrb-asset-delete title=\"Delete\" aria-label=\"Delete\">" + icon("trash") + "</button></div>";
        }
        return article;
    }

    function escapeHtml(value){
        return String(value || "").replace(/[&<>"']/g, function(char){
            return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#039;"}[char];
        });
    }

    function icon(name){
        if (name === "trash") {
            return "<svg class=\"qrb-icon\" aria-hidden=\"true\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M4 7h16\"/><path d=\"M9 7V4h6v3\"/><path d=\"m6 7 1 13h10l1-13\"/><path d=\"M10 11v5\"/><path d=\"M14 11v5\"/></svg>";
        }
        return "<svg class=\"qrb-icon\" aria-hidden=\"true\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" stroke-width=\"1.8\" stroke-linecap=\"round\" stroke-linejoin=\"round\"><path d=\"M12 20h9\"/><path d=\"M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z\"/></svg>";
    }

    function renderAssetGrid(assets){
        var grid = qs("[data-qrb-asset-grid]");
        if (!grid) { return; }
        grid.innerHTML = "";
        if (!assets.length) {
            grid.innerHTML = "<div class=\"qrb-empty\" data-qrb-assets-empty><h2>No logo assets yet</h2><p>Upload reusable logo artwork here, then select it from QR Studio when designing a QR code.</p></div>";
            return;
        }
        assets.forEach(function(asset){ grid.appendChild(assetCard(asset, false)); });
    }

    function renderPickerAssets(assets){
        var grid = qs("[data-qrb-asset-picker-grid]");
        if (!grid) { return; }
        grid.innerHTML = "";
        if (!assets.length) {
            grid.innerHTML = "<p class=\"qrb-empty\">No assets yet. Upload a logo to use it here.</p>";
            return;
        }
        assets.forEach(function(asset){ grid.appendChild(assetCard(asset, true)); });
    }

    function refreshAssets(){
        return fetchAssets().then(function(assets){
            renderAssetGrid(assets);
            renderPickerAssets(assets);
            return assets;
        });
    }

    function openAssetPicker(button){
        activeLogoWrap = button.closest("label");
        var modal = qs("[data-qrb-asset-modal]");
        if (!modal) { return; }
        modal.hidden = false;
        refreshAssets();
    }

    function closeAssetPicker(){
        var modal = qs("[data-qrb-asset-modal]");
        if (modal) { modal.hidden = true; }
    }

    function chooseAsset(card){
        if (!activeLogoWrap) { return; }
        var assetField = qs(".qrb-logo-asset-id", activeLogoWrap);
        var legacyField = qs(".qrb-logo-id", activeLogoWrap);
        var preview = qs(".qrb-logo-preview", activeLogoWrap);
        var remove = qs(".qrb-remove-logo", activeLogoWrap);
        if (assetField) { assetField.value = card.dataset.qrbAssetId || "0"; }
        if (legacyField) { legacyField.value = "0"; }
        if (preview) { preview.innerHTML = "<img src=\"" + card.dataset.qrbAssetUrl + "\" alt=\"\">"; }
        if (remove) { remove.disabled = false; }
        closeAssetPicker();
        setDirty(true);
        schedulePreview();
    }

    function renameAsset(card){
        var current = card.dataset.qrbAssetName || "";
        var name = window.prompt("Rename asset", current);
        if (name === null || !name.trim()) { return; }
        var data = ajaxData("qrbuzz_assets_rename");
        data.set("asset_id", card.dataset.qrbAssetId || "0");
        data.set("display_name", name.trim());
        fetch(window.QRBuzzApp.ajaxUrl, { method: "POST", credentials: "same-origin", body: data }).then(refreshAssets);
    }

    function deleteAsset(card){
        var used = parseInt(card.dataset.qrbAssetUsage || "0", 10);
        var message = used > 0 ? "This asset is used by " + used + " QR code(s). Remove it from those QR codes before deleting." : "Delete this asset?";
        if (!window.confirm(message) || used > 0) { return; }
        var data = ajaxData("qrbuzz_assets_delete");
        data.set("asset_id", card.dataset.qrbAssetId || "0");
        fetch(window.QRBuzzApp.ajaxUrl, { method: "POST", credentials: "same-origin", body: data }).then(refreshAssets);
    }

    function handleAssetUpload(input, statusSelector){
        var status = qs(statusSelector);
        uploadAsset(input.files && input.files[0], status).then(function(asset){
            input.value = "";
            if (!asset) { return; }
            refreshAssets().then(function(){
                if (statusSelector === "[data-qrb-picker-status]") {
                    var card = qs("[data-qrb-asset-picker-grid] [data-qrb-asset-id=\"" + asset.id + "\"]");
                    if (card) { chooseAsset(card); }
                }
            });
        });
    }

    document.addEventListener("click", function(event){
        var refresh = event.target.closest(".qrb-refresh-preview");
        if (refresh) { event.preventDefault(); refreshPreview(); }

        var selectLogo = event.target.closest(".qrb-select-logo");
        if (selectLogo) {
            event.preventDefault();
            openAssetPicker(selectLogo);
        }

        var closeModal = event.target.closest("[data-qrb-asset-modal-close]");
        if (closeModal) {
            event.preventDefault();
            closeAssetPicker();
        }

        var pick = event.target.closest("[data-qrb-asset-pick]");
        if (pick) {
            event.preventDefault();
            chooseAsset(pick.closest("[data-qrb-asset-id]"));
        }

        var rename = event.target.closest("[data-qrb-asset-rename]");
        if (rename) {
            event.preventDefault();
            renameAsset(rename.closest("[data-qrb-asset-id]"));
        }

        var removeAsset = event.target.closest("[data-qrb-asset-delete]");
        if (removeAsset) {
            event.preventDefault();
            deleteAsset(removeAsset.closest("[data-qrb-asset-id]"));
        }

        var assetsRefresh = event.target.closest("[data-qrb-assets-refresh]");
        if (assetsRefresh) {
            event.preventDefault();
            refreshAssets();
        }

        var removeLogo = event.target.closest(".qrb-remove-logo");
        if (removeLogo) {
            event.preventDefault();
            var logoWrap = removeLogo.closest("label");
            var assetField = qs(".qrb-logo-asset-id", logoWrap);
            var legacyField = qs(".qrb-logo-id", logoWrap);
            if (assetField) { assetField.value = "0"; }
            if (legacyField) { legacyField.value = "0"; }
            qs(".qrb-logo-preview", logoWrap).innerHTML = "";
            removeLogo.disabled = true;
            setDirty(true);
            schedulePreview();
        }
    });

    document.addEventListener("change", function(event){
        if (event.target.matches("[data-qrb-asset-file]")) {
            handleAssetUpload(event.target, "[data-qrb-asset-status]");
            return;
        }
        if (event.target.matches("[data-qrb-picker-upload]")) {
            handleAssetUpload(event.target, "[data-qrb-picker-status]");
            return;
        }
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
                qsa("[data-qrb-color-input]", form).forEach(syncColorText);
            }
        }
        schedulePreview();
    });

    document.addEventListener("dragover", function(event){
        var dropzone = event.target.closest(".qrb-dropzone");
        if (!dropzone) { return; }
        event.preventDefault();
        dropzone.classList.add("is-dragging");
    });

    document.addEventListener("dragleave", function(event){
        var dropzone = event.target.closest(".qrb-dropzone");
        if (dropzone) { dropzone.classList.remove("is-dragging"); }
    });

    document.addEventListener("drop", function(event){
        var dropzone = event.target.closest(".qrb-dropzone");
        if (!dropzone) { return; }
        event.preventDefault();
        dropzone.classList.remove("is-dragging");
        var input = qs("input[type=file]", dropzone);
        var file = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files[0] : null;
        if (!input || !file) { return; }
        var picker = input.matches("[data-qrb-picker-upload]");
        uploadAsset(file, qs(picker ? "[data-qrb-picker-status]" : "[data-qrb-asset-status]")).then(function(asset){
            if (!asset) { return; }
            refreshAssets().then(function(){
                if (picker) {
                    var card = qs("[data-qrb-asset-picker-grid] [data-qrb-asset-id=\"" + asset.id + "\"]");
                    if (card) { chooseAsset(card); }
                }
            });
        });
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
        qsa("[data-qrb-color-input]").forEach(syncColorText);
        qsa("[data-qrb-accordion]").forEach(function(details){
            try {
                var value = window.localStorage.getItem("qrb-studio-section-" + details.getAttribute("data-qrb-accordion"));
                if (value === "1") { details.open = true; }
                if (value === "0") { details.open = false; }
            } catch (e) {}
        });
        if (qs("[data-qrb-asset-grid]")) { refreshAssets(); }
    });

    window.qrbuzzRefreshPreview = refreshPreview;
})();
