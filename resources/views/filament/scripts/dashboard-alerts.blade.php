{{--
    Shared SweetAlert2 helpers and the cross-module alert handlers.

    Extracted from AdminPanelProvider, which had grown past 14 KB because every
    one of these scripts was a string literal inside the panel definition. As a
    Blade view the JavaScript stays syntax-highlighted, diffable, and free of
    PHP string escaping.

    Every visible string comes from lang/*/ui.php through the `t` object below,
    so the dialogs follow the panel's language instead of being Arabic-only.

    @param string $primaryColor    The panel's primary colour, for dialog buttons.
    @param string $keepAliveUrl    Endpoint that pushes the session expiry forward.
    @param int    $keepAliveSeconds How often to ping it.
    @param array  $muacThresholds  SAM/MAM cut-offs, so the referral prompt can
                                   classify a reading without a round trip.
--}}
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script>
    // ---------------------------------------------------------------
    // Centralized, theme-aware SweetAlert2 helpers, reused by every
    // destructive action across the dashboard (Trash, Backups, ...).
    // Defined once here so pages never duplicate SweetAlert config.
    // ---------------------------------------------------------------
    const dashboardPrimaryColor = @js($primaryColor);

    // Translated strings and the reading direction, resolved server-side for
    // the current locale. The dialogs read from these rather than holding
    // Arabic literals, which is what makes the English panel fully English.
    const dashboardText = @js(__('ui.alerts'));
    const dashboardReferralText = @js(__('ui.referral'));
    const dashboardRtl = @js(app()->getLocale() === 'ar');
    const dashboardDir = dashboardRtl ? "rtl" : "ltr";
    const dashboardAlign = dashboardRtl ? "right" : "left";

    // The border that marks the emphasised line in a dialog sits on the side
    // the text starts from, whichever way round that is.
    const dashboardStartBorder = dashboardRtl ? "border-right" : "border-left";

    /**
     * The body of a dialog: a direction-aware block of "label: value" rows.
     *
     * @param {string} rows  Pre-rendered <p> markup.
     */
    window.dashboardDialogBody = function (rows) {
        return `<div style="text-align: ${dashboardAlign}; direction: ${dashboardDir}; font-family: Tajawal, sans-serif; font-size: 16px; line-height: 1.8;">${rows}</div>`;
    };

    /** One "label: value" row, with the value picked out in colour. */
    window.dashboardDialogRow = function (label, value, color) {
        return `<p style="margin-bottom: 8px; color: #374151;"><strong>${label}:</strong> <span style="color: ${color}; font-weight: bold;">${value}</span></p>`;
    };

    // ---------------------------------------------------------------
    // Session keep-alive and a readable expiry message.
    //
    // A long form left open past SESSION_LIFETIME came back from its next
    // Livewire round trip as Livewire's own English browser prompt, and
    // reloading threw away everything typed. Two parts to that:
    //
    //   1. while a tab is open and visible, ping the session so it does not
    //      expire under a form somebody is still working on;
    //   2. if it expires anyway - laptop asleep, machine off overnight - say
    //      so in the panel's own language, and let the user copy their work
    //      out before reloading rather than reloading from under them.
    // ---------------------------------------------------------------
    (function () {
        const keepAliveUrl = @js($keepAliveUrl);
        const keepAliveMs = @js($keepAliveSeconds) * 1000;

        if (keepAliveMs > 0) {
            setInterval(() => {
                // A hidden tab is nobody working; letting it lapse is correct.
                if (document.visibilityState !== "visible") {
                    return;
                }

                fetch(keepAliveUrl, {
                    method: "GET",
                    credentials: "same-origin",
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                    cache: "no-store",
                }).catch(() => {
                    // Offline or the server is down. The next tick retries;
                    // there is nothing useful to tell the user here.
                });
            }, keepAliveMs);
        }

        document.addEventListener("livewire:init", () => {
            Livewire.hook("request", ({ fail }) => {
                fail(({ status, preventDefault }) => {
                    if (status !== 419) {
                        return;
                    }

                    // Replaces Livewire's own confirm() dialog.
                    preventDefault();

                    const expired = dashboardText.session_expired;

                    Swal.fire({
                        title: expired.title,
                        html: window.dashboardDialogBody(`
                            <p style="color: #374151;">${expired.line_1}</p>
                            <p style="color: #374151;">${expired.line_2}</p>
                        `),
                        icon: "warning",
                        showCancelButton: true,
                        confirmButtonText: expired.reload,
                        cancelButtonText: expired.stay,
                        confirmButtonColor: dashboardPrimaryColor,
                        cancelButtonColor: "#6b7280",
                        reverseButtons: true,
                        allowOutsideClick: false,
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.reload();
                        }
                    });
                });
            });
        });
    })();

    window.dashboardIsDark = function () {
        return document.documentElement.classList.contains("dark");
    };

    // Styled confirmation dialog. Returns the SweetAlert2 promise.
    window.dashboardConfirm = function (options) {
        options = options || {};
        return Swal.fire({
            title: options.title || dashboardText.confirm_title,
            html: options.text || "",
            icon: options.icon || "question",
            showCancelButton: true,
            confirmButtonText: options.confirmText || dashboardText.yes,
            cancelButtonText: options.cancelText || dashboardText.cancel,
            confirmButtonColor: options.danger ? "#dc2626" : dashboardPrimaryColor,
            cancelButtonColor: "#6b7280",
            reverseButtons: true,
            focusCancel: options.danger === true,
            background: window.dashboardIsDark() ? "#1f2937" : "#ffffff",
            color: window.dashboardIsDark() ? "#f9fafb" : "#111827",
        });
    };

    // Lightweight success/error toast.
    window.dashboardToast = function (icon, title) {
        Swal.fire({
            toast: true,
            position: "top-start",
            icon: icon || "success",
            title: title || "",
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: window.dashboardIsDark() ? "#1f2937" : "#ffffff",
            color: window.dashboardIsDark() ? "#f9fafb" : "#111827",
        });
    };

    // Confirm, then run a Livewire method. The $wire reference is
    // captured synchronously as an argument, so the call still works
    // inside the async .then() (Alpine magics are not reliable there).
    window.confirmAction = function ($wire, method, params, options) {
        options = options || {};
        return window.dashboardConfirm(options).then((result) => {
            if (! result.isConfirmed) {
                return;
            }

            return Promise.resolve($wire.call(method, ...(params || []))).then((ok) => {
                if (ok === false) {
                    window.dashboardToast("error", options.errorText || dashboardText.failed);
                } else if (options.successText) {
                    window.dashboardToast("success", options.successText);
                }
            });
        });
    };

    // ---------------------------------------------------------------
    // Children: a SAM or MAM reading opens a follow-up episode, and the
    // screener is asked on the way in.
    //
    // The question is raised when Save is pressed rather than when the
    // measurement field is left: at that point the record is complete, the
    // child has a name to put in the dialog, and the screener is not
    // interrupted mid-form by a question about a number they may still be
    // correcting.
    //
    // The whole exchange happens in the browser - the reading is in the input,
    // the thresholds are two numbers, the name is in the Livewire snapshot -
    // so the dialog opens on the same tick the button is pressed, with no
    // round trip to wait for.
    //
    // Create and Edit ask the same question and differ in two ways, both of
    // them deliberate:
    //
    //   * Create asks about any SAM or MAM reading, because every one of them
    //     is new information. Edit asks only when the measurement has actually
    //     changed from the one already stored - otherwise re-saving a child
    //     who is already SAM, or correcting their phone number, would raise
    //     the same dialog again every time and train people to dismiss it.
    //     The stored reading travels in data-muac-original, which is present
    //     on the edit form and absent on the create form.
    //
    //   * Declining on Create still saves the screening to Children and only
    //     skips the referral - the visit happened and belongs in the record.
    //     Declining on Edit saves nothing at all: the change was not confirmed,
    //     so the reading goes back to what it was and the form stays open for
    //     the value to be corrected.
    // ---------------------------------------------------------------
    (function () {
        const thresholds = @js($muacThresholds);

        // Set while the form is being submitted again after the question has
        // been answered, so the replay is not intercepted a second time.
        let replaying = false;

        function classify(value) {
            if (value === null || value === undefined || String(value).trim() === "") {
                return null;
            }

            const mm = Number.parseFloat(value);

            if (Number.isNaN(mm)) {
                return null;
            }

            if (mm <= thresholds.sam_max) {
                return "SAM";
            }

            return mm < thresholds.mam_max ? "MAM" : "Normal";
        }

        function componentFor(element) {
            const host = element.closest("[wire\\:id]");

            if (! host || typeof Livewire === "undefined") {
                return null;
            }

            return Livewire.find(host.getAttribute("wire:id"));
        }

        function childName(form, component) {
            const fromComponent = component.get("data.name");

            if (fromComponent) {
                return fromComponent;
            }

            const input = form.querySelector('[wire\\:model="data.name"], #form\\.name');

            return (input && input.value) || dashboardReferralText.unnamed_child;
        }

        /** Two readings are the same measurement, whatever they look like. */
        function sameReading(a, b) {
            const left = Number.parseFloat(a);
            const right = Number.parseFloat(b);

            if (Number.isNaN(left) || Number.isNaN(right)) {
                return String(a ?? "").trim() === String(b ?? "").trim();
            }

            return left === right;
        }

        function ask(form, component, input, fi) {
            const t = dashboardReferralText;
            const accent = fi === "SAM" ? "#dc2626" : "#d97706";

            return Swal.fire({
                title: fi === "SAM" ? t.title_sam : t.title_mam,
                html: window.dashboardDialogBody(
                    window.dashboardDialogRow(t.child, childName(form, component), "#2563eb")
                    + window.dashboardDialogRow(t.muac, `${input.value} ${t.mm}`, accent)
                    + `<p style="margin-top: 12px; padding: 10px; background-color: #fef2f2; ${dashboardStartBorder}: 4px solid #ef4444; color: #991b1b; font-weight: bold; border-radius: 4px; font-size: 14px;">${t.question}</p>`
                ),
                icon: "warning",
                showCancelButton: true,
                confirmButtonText: t.confirm,
                cancelButtonText: t.cancel,
                confirmButtonColor: "#dc2626",
                cancelButtonColor: "#6b7280",
                reverseButtons: true,
                background: window.dashboardIsDark() ? "#1f2937" : "#ffffff",
                color: window.dashboardIsDark() ? "#f9fafb" : "#111827",
            });
        }

        function resubmit(form) {
            replaying = true;
            form.requestSubmit();
        }

        // Capture phase, so this runs before the form's own wire:submit
        // listener and can stop it. Delegated from the document, so it keeps
        // working across every Livewire re-render of the form.
        document.addEventListener("submit", (event) => {
            if (replaying) {
                replaying = false;

                return;
            }

            const form = event.target;

            if (! form || typeof form.querySelector !== "function") {
                return;
            }

            const input = form.querySelector("[data-muac-referral]");

            if (! input) {
                return;
            }

            const component = componentFor(form);

            if (! component) {
                return;
            }

            const original = input.dataset.muacOriginal;
            const editing = original !== undefined;
            const fi = classify(input.value);

            if (editing) {
                // Nothing was changed about the measurement, so there is
                // nothing to ask about - this save is about something else.
                if (sameReading(input.value, original)) {
                    return;
                }

                if (fi !== "SAM" && fi !== "MAM") {
                    return;
                }

                event.preventDefault();
                event.stopImmediatePropagation();

                ask(form, component, input, fi).then((result) => {
                    if (! result.isConfirmed) {
                        // Not confirmed, so not saved: the reading goes back to
                        // the one on file and the form stays open. A live set
                        // (no third argument) so the derived FI indicator
                        // returns to what it was showing too.
                        component.set("data.muac_mm", original);

                        return;
                    }

                    component.set("referFollowUpOnSave", true, false);
                    resubmit(form);
                });

                return;
            }

            // Creating. Only a page carrying somewhere to record the answer
            // asks the question.
            if (typeof component.get("declineFollowUpReferral") === "undefined") {
                return;
            }

            if (fi !== "SAM" && fi !== "MAM") {
                // A normal or blank reading refers nobody, so any earlier
                // refusal on this form is no longer about anything.
                component.set("declineFollowUpReferral", false, false);

                return;
            }

            event.preventDefault();
            event.stopImmediatePropagation();

            ask(form, component, input, fi).then((result) => {
                // Deferred (third argument false): no request of its own, the
                // answer travels with the save that follows immediately.
                component.set("declineFollowUpReferral", ! result.isConfirmed, false);

                resubmit(form);
            });
        }, true);
    })();

    window.addEventListener("show-duplicate-visit-alert", event => {
        const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
        const t = dashboardText.duplicate_visit;
        // Only the pregnant/lactating module sends a previous
        // status; the row is skipped everywhere else.
        let statusHtml = "";
        if (detail.last_status_type) {
            statusHtml = window.dashboardDialogRow(t.last_status_type, detail.last_status_type, "#7c3aed");
        }
        let warningHtml = "";
        if (detail.visit_type_warning) {
            warningHtml = `<p style="margin-top: 12px; padding: 10px; background-color: #fef2f2; ${dashboardStartBorder}: 4px solid #ef4444; color: #991b1b; font-weight: bold; border-radius: 4px; font-size: 14px;">${detail.visit_type_warning}</p>`;
        }
        Swal.fire({
            title: detail.title || t.title,
            html: window.dashboardDialogBody(
                window.dashboardDialogRow(t.last_visit_date, detail.last_visit_date, "#2563eb")
                + window.dashboardDialogRow(t.last_visit_type, detail.last_visit_type, "#059669")
                + statusHtml
                + warningHtml
            ),
            icon: detail.visit_type_warning ? "warning" : "info",
            showCancelButton: true,
            confirmButtonText: detail.confirm_button_text || t.confirm,
            cancelButtonText: t.skip,
            confirmButtonColor: "#2563eb",
            cancelButtonColor: "#6b7280",
            reverseButtons: true,
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then((result) => {
            if (result.isConfirmed) {
                if (detail.action_type === "fill_child") {
                    Livewire.dispatch("fillChildDataFromAlert", { data: detail.record_data });
                } else if (detail.action_type === "fill_mother") {
                    Livewire.dispatch("fillMotherDataFromAlert", { data: detail.record_data });
                }
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                window.location.href = detail.index_url;
            }
        });
    });

    // Group sessions: the ID number is already registered on an
    // active session. Same visual style as the alert above, with
    // the session subject added and only two outcomes - prefill
    // the participant data, or leave for the listing.
    window.addEventListener("show-group-session-duplicate-alert", event => {
        const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
        const t = dashboardText.group_session_duplicate;
        Swal.fire({
            title: detail.title || t.title,
            html: window.dashboardDialogBody(
                window.dashboardDialogRow(t.last_session_date, detail.last_session_date, "#2563eb")
                + window.dashboardDialogRow(t.last_visit_type, detail.last_visit_type, "#059669")
                + window.dashboardDialogRow(t.last_session_subject, detail.last_session_subject, "#7c3aed")
            ),
            icon: "info",
            showCancelButton: true,
            confirmButtonText: detail.confirm_button_text || t.confirm,
            cancelButtonText: detail.close_button_text || t.close,
            confirmButtonColor: "#2563eb",
            cancelButtonColor: "#6b7280",
            reverseButtons: true,
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then((result) => {
            if (result.isConfirmed) {
                Livewire.dispatch("fillGroupSessionDataFromAlert", { data: detail.record_data });
            } else {
                window.location.href = detail.index_url;
            }
        });
    });

    // Follow Up Children: the latest recorded visit came back Normal, so the
    // child may be discharged as cured. Recovery is a clinical judgement, not
    // an arithmetic one, so the decision is put to the user: discharging
    // closes the episode and hands the child back to the Children module,
    // declining leaves the episode open and writes nothing.
    window.addEventListener("show-follow-up-discharge-alert", event => {
        const detail = Array.isArray(event.detail) ? event.detail[0] : event.detail;
        const t = dashboardText.follow_up_discharge;

        Swal.fire({
            title: t.title,
            html: window.dashboardDialogBody(
                window.dashboardDialogRow(t.child, detail.child_name ?? "", "#2563eb")
                + window.dashboardDialogRow(t.last_muac, `${detail.muac} ${t.mm}`, "#059669")
                + `<p style="margin-top: 12px; color: #374151;">${t.question}</p>`
            ),
            icon: "success",
            showCancelButton: true,
            confirmButtonText: t.confirm,
            cancelButtonText: t.keep,
            confirmButtonColor: dashboardPrimaryColor,
            cancelButtonColor: "#6b7280",
            reverseButtons: true,
            allowOutsideClick: false,
            allowEscapeKey: false
        }).then((result) => {
            if (result.isConfirmed) {
                Livewire.dispatch("confirmFollowUpDischarge");
            } else if (result.dismiss === Swal.DismissReason.cancel) {
                Livewire.dispatch("keepUnderFollowUp");
            }
        });
    });
</script>
