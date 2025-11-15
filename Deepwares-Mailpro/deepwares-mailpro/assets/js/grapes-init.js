// Deepwares MailPro - GrapesJS initialization (drag & drop + click insert)
(function () {
    if (typeof grapesjs === "undefined") {
        console.error("GrapesJS not found. Make sure grapes.min.js is enqueued.");
        return;
    }

    const BOOTSTRAP = window.DWMP_BUILDER_BOOTSTRAP || {};
    const $  = (sel) => document.querySelector(sel);
    const $$ = (sel) => Array.prototype.slice.call(document.querySelectorAll(sel));

    const gjsContainer = $("#gjs");
    if (!gjsContainer) return; // Not on builder page

    // -------------------------------------------------------------------------
    // GrapesJS Init
    // -------------------------------------------------------------------------
    const editor = grapesjs.init({
        container: "#gjs",
        fromElement: false,
        height: "100%",
        width: "auto",
        storageManager: false,
        allowScripts: false,
        canvas: {
            styles: [],
            scripts: []
        },
        blockManager: {
            appendTo: "#dwmp_blocks_container"
        }
        // No external plugins – we manage our own blocks/templates
    });

    const bm = editor.BlockManager;

    // -------------------------------------------------------------------------
    // Helper: full HTML (HTML + CSS)
    // -------------------------------------------------------------------------
    function getFullHtml() {
        const html = editor.getHtml();
        const css  = editor.getCss();
        return (
            "<!DOCTYPE html>" +
            "<html><head><meta charset=\"utf-8\">" +
            "<style>" + css + "</style>" +
            "</head><body>" + html + "</body></html>"
        );
    }

    // -------------------------------------------------------------------------
    // Load existing content / fallback
    // -------------------------------------------------------------------------
    if (BOOTSTRAP.html) {
        editor.setComponents(BOOTSTRAP.html);
    } else {
        editor.setComponents(
            '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:24px 0;">' +
            '<tr><td align="center">' +
            '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:4px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">' +
            '<tr><td style="padding:24px;text-align:center;">' +
            '<h1 style="margin:0 0 8px;font-size:24px;color:#111827;">Welcome!</h1>' +
            '<p style="margin:0;color:#4b5563;font-size:14px;">Start designing your email in the builder.</p>' +
            "</td></tr></table></td></tr></table>"
        );
    }

    // Subject / preview inputs
    const subjectInput = $("#dwmp_subject_line");
    const previewInput = $("#dwmp_preview_text");

    if (subjectInput) subjectInput.value = BOOTSTRAP.subject || subjectInput.value || "";
    if (previewInput) previewInput.value = BOOTSTRAP.preview || previewInput.value || "";

    // -------------------------------------------------------------------------
    // Editor Mode Tabs (Visual / HTML)
    // -------------------------------------------------------------------------
    const visualPanel = $("#dwmp_visual_editor_panel");
    const htmlPanel   = $("#dwmp_html_editor_panel");
    const htmlSource  = $("#dwmp_html_source");

    function setEditorMode(mode) {
        if (mode === "visual") {
            if (htmlSource) htmlSource.value = getFullHtml();
            visualPanel.style.display = "";
            htmlPanel.style.display   = "none";
        } else {
            if (htmlSource) htmlSource.value = getFullHtml();
            visualPanel.style.display = "none";
            htmlPanel.style.display   = "";
        }
        $$(".dwmp-editor-tab").forEach(btn =>
            btn.classList.toggle("tab-active", btn.dataset.mode === mode)
        );
    }

    $$(".dwmp-editor-tab").forEach(btn => {
        btn.addEventListener("click", () => setEditorMode(btn.dataset.mode || "visual"));
    });

    if (htmlSource) {
        htmlSource.addEventListener("blur", () => {
            const raw = htmlSource.value || "";
            if (!raw.trim()) return;
            editor.setComponents(raw);
            updatePreview();
        });
    }

    setEditorMode("visual");

    // -------------------------------------------------------------------------
    // Templates (left column)
    // -------------------------------------------------------------------------
    const TEMPLATES = {
        "simple-newsletter": {
            subject: "Your Weekly Newsletter",
            preview: "Catch up on this week’s updates.",
            html:
                '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:24px 0;">' +
                '<tr><td align="center">' +
                '<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:4px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">' +
                '<tr><td style="padding:24px;text-align:center;background:#111827;color:#ffffff;">' +
                '<h1 style="margin:0;font-size:26px;">Weekly Newsletter</h1>' +
                '<p style="margin:4px 0 0;font-size:14px;color:#e5e7eb;">Your company tagline goes here</p>' +
                "</td></tr>" +
                '<tr><td style="padding:24px;color:#111827;font-size:14px;line-height:1.6;">' +
                "<h2 style=\"margin-top:0;\">Hey there 👋</h2>" +
                "<p>Use this layout to share updates, news and curated content with your audience.</p>" +
                "<p>Add hero images, call-to-action buttons and more using the content blocks.</p>" +
                "</td></tr>" +
                '<tr><td style="padding:16px 24px 24px;text-align:center;font-size:12px;color:#6b7280;background:#f9fafb;">' +
                "Your Company Inc · 123 Street · City · Country" +
                "</td></tr>" +
                "</table></td></tr></table>"
        },
        "product-launch": {
            subject: "Introducing Our New Product 🚀",
            preview: "A quick look at what’s new.",
            html:
                '<table width="100%" cellpadding="0" cellspacing="0" style="background:#0f172a;padding:24px 0;">' +
                '<tr><td align="center">' +
                '<table width="640" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">' +
                '<tr><td style="padding:28px 28px 18px;background:linear-gradient(135deg,#0f172a,#1d4ed8);color:#ffffff;">' +
                '<h1 style="margin:0 0 8px;font-size:26px;">Cyber Monday Sale</h1>' +
                '<p style="margin:0;font-size:14px;color:#e5e7eb;">Save up to 50% on selected items.</p>' +
                "</td></tr>" +
                '<tr><td style="padding:24px;color:#111827;font-size:14px;line-height:1.6;">' +
                "<h2 style=\"margin-top:0;\">Headline for your offer</h2>" +
                "<p>Describe your product, collection or promotional offer here. Use short paragraphs and clear benefits.</p>" +
                "</td></tr>" +
                '<tr><td align="center" style="padding:0 24px 24px;">' +
                '<a href="#" style="display:inline-block;padding:12px 28px;border-radius:999px;background:#111827;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">Shop the sale</a>' +
                "</td></tr>" +
                "</table></td></tr></table>"
        },
        "event-invite": {
            subject: "You’re Invited 🎉",
            preview: "Join us for our upcoming event.",
            html:
                '<table width="100%" cellpadding="0" cellspacing="0" style="background:#fef3c7;padding:24px 0;">' +
                '<tr><td align="center">' +
                '<table width="620" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">' +
                '<tr><td style="padding:26px 26px 18px;background:#1d4ed8;color:#ffffff;">' +
                '<h1 style="margin:0;font-size:24px;">Event Invitation</h1>' +
                "<p style=\"margin:4px 0 0;font-size:13px;color:#e5e7eb;\">A short subtitle or tagline</p>" +
                "</td></tr>" +
                '<tr><td style="padding:22px 26px;color:#111827;font-size:14px;line-height:1.6;">' +
                "<p>We’re excited to invite you to our upcoming event. Use this section to describe the highlights and why it matters.</p>" +
                "<p><strong>Date:</strong> Saturday, 5 October<br><strong>Time:</strong> 6:00pm–9:00pm<br><strong>Location:</strong> Your venue name</p>" +
                "</td></tr>" +
                '<tr><td align="center" style="padding:0 26px 24px;">' +
                '<a href="#" style="display:inline-block;padding:10px 24px;border-radius:999px;background:#1d4ed8;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;">RSVP now</a>' +
                "</td></tr>" +
                "</table></td></tr></table>"
        }
    };

    function loadTemplateByKey(key) {
        const tpl = TEMPLATES[key];
        if (!tpl) return;
        editor.setComponents(tpl.html);
        if (subjectInput && tpl.subject) subjectInput.value = tpl.subject;
        if (previewInput && tpl.preview) previewInput.value = tpl.preview;
        updatePreview(true);
    }

    $$(".dwmp-template-card").forEach(btn => {
        btn.addEventListener("click", () => {
            const key = btn.getAttribute("data-template-key");
            loadTemplateByKey(key);
        });
    });

    // -------------------------------------------------------------------------
    // CONTENT BLOCKS as GrapesJS blocks (drag & drop + click insert)
    // -------------------------------------------------------------------------
    const BLOCKS = {
        heading:
            '<h2 style="margin:0 0 12px;font-size:22px;color:#111827;font-family:Arial,Helvetica,sans-serif;">Section heading</h2>',
        paragraph:
            '<p style="margin:0 0 12px;color:#4b5563;font-size:14px;line-height:1.6;font-family:Arial,Helvetica,sans-serif;">Add your paragraph text here. Keep it short and focused for best readability.</p>',
        button:
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:12px 0;"><tr><td>' +
            '<a href="#" style="display:inline-block;padding:10px 24px;border-radius:999px;background:#111827;color:#ffffff;text-decoration:none;font-size:14px;font-family:Arial,Helvetica,sans-serif;">Call to action</a>' +
            "</td></tr></table>",
        image:
            '<img src="https://via.placeholder.com/600x240/111827/FFFFFF?text=Hero+Image" alt="Image" style="display:block;width:100%;max-width:600px;border-radius:6px;margin:12px 0;">',
        divider:
            '<hr style="border:none;border-top:1px solid #e5e7eb;margin:16px 0;">'
    };

    const BLOCK_MAP = {
        "dwmp-heading":   BLOCKS.heading,
        "dwmp-paragraph": BLOCKS.paragraph,
        "dwmp-button":    BLOCKS.button,
        "dwmp-image":     BLOCKS.image,
        "dwmp-divider":   BLOCKS.divider
    };

    bm.add("dwmp-heading", {
        label:
            '<span class="dashicons dashicons-editor-textcolor"></span>' +
            '<span class="dwmp-block-label">Heading</span>',
        category: "Content",
        content: BLOCKS.heading
    });

    bm.add("dwmp-paragraph", {
        label:
            '<span class="dashicons dashicons-editor-alignleft"></span>' +
            '<span class="dwmp-block-label">Paragraph</span>',
        category: "Content",
        content: BLOCKS.paragraph
    });

    bm.add("dwmp-button", {
        label:
            '<span class="dashicons dashicons-editor-bold"></span>' +
            '<span class="dwmp-block-label">Button</span>',
        category: "Content",
        content: BLOCKS.button
    });

    bm.add("dwmp-image", {
        label:
            '<span class="dashicons dashicons-format-image"></span>' +
            '<span class="dwmp-block-label">Image</span>',
        category: "Content",
        content: BLOCKS.image
    });

    bm.add("dwmp-divider", {
        label:
            '<span class="dashicons dashicons-minus"></span>' +
            '<span class="dwmp-block-label">Divider</span>',
        category: "Content",
        content: BLOCKS.divider
    });

    // Click-to-insert support (in addition to drag & drop)
    function bindBlockClickInsert() {
        const blockEls = document.querySelectorAll("#dwmp_blocks_container .gjs-block");
        blockEls.forEach(el => {
            if (el.__dwmpBound) return;
            el.__dwmpBound = true;

            el.addEventListener("click", () => {
                const id   = el.getAttribute("data-id");
                const html = BLOCK_MAP[id];
                if (!html) return;
                editor.addComponents(html);
                updatePreview(true);
            });
        });
    }

    // Run once after blocks render
    setTimeout(bindBlockClickInsert, 200);

    // -------------------------------------------------------------------------
    // PREVIEW (desktop + mobile) + thumbnail
    // -------------------------------------------------------------------------
    const previewFrameDesktop = $("#dwmp_builder_preview_frame");
    const previewFrameMobile  = $("#dwmp_builder_preview_frame_mobile");

    function writeIntoFrame(frame, html) {
        if (!frame || !frame.contentWindow) return;
        const doc = frame.contentWindow.document;
        doc.open();
        doc.write(html);
        doc.close();
    }

    function updatePreview(force) {
        const fullHtml = getFullHtml();
        writeIntoFrame(previewFrameDesktop, fullHtml);
        writeIntoFrame(previewFrameMobile, fullHtml);

        if (!force && typeof html2canvas === "undefined") return;

        const wrapper     = document.getElementById("dwmp_preview_desktop_wrapper");
        const hiddenThumb = document.getElementById("dwmp_builder_thumbnail");

        if (wrapper && hiddenThumb && window.html2canvas) {
            setTimeout(() => {
                html2canvas(wrapper, {
                    useCORS: true,
                    backgroundColor: "#f3f4f6",
                    scale: 0.6
                }).then(canvas => {
                    try { hiddenThumb.value = canvas.toDataURL("image/png"); } catch (e) {}
                }).catch(() => {});
            }, 500);
        }
    }

    let previewTimer = null;
    function schedulePreview() {
        if (previewTimer) clearTimeout(previewTimer);
        previewTimer = setTimeout(() => updatePreview(false), 600);
    }

    editor.on("update", schedulePreview);
    editor.on("component:add", schedulePreview);
    editor.on("component:remove", schedulePreview);

    const manualPreviewBtn = $("#dwmp_builder_preview_btn");
    if (manualPreviewBtn) manualPreviewBtn.addEventListener("click", () => updatePreview(true));

    // Preview mode toggle
    $$(".dwmp-preview-mode-btn").forEach(btn => {
        btn.addEventListener("click", () => {
            const mode = btn.getAttribute("data-mode");
            $$(".dwmp-preview-mode-btn").forEach(b =>
                b.classList.toggle("preview-active", b === btn)
            );
            const desktopWrap = $("#dwmp_preview_desktop_wrapper");
            const mobileWrap  = $("#dwmp_preview_mobile_wrapper");
            if (mode === "mobile") {
                if (desktopWrap) desktopWrap.style.display = "none";
                if (mobileWrap)  mobileWrap.style.display  = "";
            } else {
                if (desktopWrap) desktopWrap.style.display = "";
                if (mobileWrap)  mobileWrap.style.display  = "none";
            }
        });
    });

    // Initial preview
    updatePreview(true);

    // -------------------------------------------------------------------------
    // Form submit: attach full HTML + thumbnail
    // -------------------------------------------------------------------------
    const form = document.getElementById("dwmp-builder-form");
    if (form) {
        form.addEventListener("submit", function () {
            const hiddenHtml  = document.getElementById("dwmp_builder_html");
            const hiddenThumb = document.getElementById("dwmp_builder_thumbnail");
            if (hiddenHtml) hiddenHtml.value = getFullHtml();

            if (typeof html2canvas !== "undefined") {
                const wrapper = document.getElementById("dwmp_preview_desktop_wrapper");
                if (wrapper && hiddenThumb) {
                    html2canvas(wrapper, {
                        useCORS: true,
                        backgroundColor: "#f3f4f6",
                        scale: 0.6
                    }).then(canvas => {
                        try { hiddenThumb.value = canvas.toDataURL("image/png"); } catch (e) {}
                    }).catch(() => {});
                }
            }
        });
    }
})();
