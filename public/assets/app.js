document.querySelectorAll(".toggle-list").forEach((button) => {
    const targetId = button.getAttribute("data-target");
    const target = targetId ? document.getElementById(targetId) : null;
    const expandLabel = button.textContent.trim();
    const collapseLabel = "Mostrar menos";

    if (!target) {
        return;
    }

    button.addEventListener("click", () => {
        const expanded = target.classList.toggle("is-expanded");
        button.textContent = expanded ? collapseLabel : expandLabel;
    });
});

const filterForm = document.querySelector("#filters");

if (filterForm) {
    filterForm.querySelectorAll("select").forEach((select) => {
        select.addEventListener("change", () => {
            filterForm.requestSubmit();
        });
    });
}

const tooltipTargets = document.querySelectorAll(".history-bar[data-tooltip], .chart-bar[data-tooltip]");

if (tooltipTargets.length > 0) {
    const tooltip = document.createElement("div");
    tooltip.className = "bar-tooltip";
    tooltip.setAttribute("role", "status");
    document.body.appendChild(tooltip);

    const moveTooltip = (target) => {
        const text = target.getAttribute("data-tooltip") || "";
        if (text === "") {
            return;
        }

        tooltip.textContent = text;
        tooltip.classList.add("is-visible");

        const rect = target.getBoundingClientRect();
        const tooltipRect = tooltip.getBoundingClientRect();
        const margin = 10;
        const top = Math.max(margin, rect.top - tooltipRect.height - 8);
        const centered = rect.left + rect.width / 2 - tooltipRect.width / 2;
        const left = Math.min(
            window.innerWidth - tooltipRect.width - margin,
            Math.max(margin, centered)
        );

        tooltip.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
    };

    const hideTooltip = () => {
        tooltip.classList.remove("is-visible");
    };

    tooltipTargets.forEach((target) => {
        target.addEventListener("mouseenter", () => moveTooltip(target));
        target.addEventListener("focus", () => moveTooltip(target));
        target.addEventListener("mouseleave", hideTooltip);
        target.addEventListener("blur", hideTooltip);
        target.addEventListener("touchstart", () => {
            moveTooltip(target);
            window.setTimeout(hideTooltip, 1200);
        }, { passive: true });
    });
}

/* ==========================================================================
   Pop-up notifications: watches /api/stats and toasts when a monitor or
   aggregated group flips between up/down while the page stays open.
   ========================================================================== */
(function setupPopupNotifications() {
    const body = document.body;
    const popupStack = document.getElementById("popup-stack");
    const statsUrl = body.dataset.statsUrl;

    if (body.dataset.popupEnabled !== "1" || !popupStack || !statsUrl) {
        return;
    }

    const popupDuration = Math.max(2000, parseInt(body.dataset.popupDuration || "6000", 10));
    const storageKey = "kuma-public-last-status";
    let lastStatuses = null;

    try {
        const stored = sessionStorage.getItem(storageKey);
        lastStatuses = stored ? JSON.parse(stored) : null;
    } catch (error) {
        lastStatuses = null;
    }

    function showToast(name, status) {
        const toast = document.createElement("div");
        toast.className = "popup-toast status-" + status;

        const dot = document.createElement("span");
        dot.className = "dot";
        dot.setAttribute("aria-hidden", "true");

        const textWrap = document.createElement("div");
        textWrap.className = "popup-toast-body";

        const title = document.createElement("span");
        title.className = "popup-toast-title";
        title.textContent = name;

        const meta = document.createElement("span");
        meta.className = "popup-toast-meta";
        meta.textContent = status === "down" ? "Ficou indisponivel" : "Voltou ao normal";

        textWrap.appendChild(title);
        textWrap.appendChild(meta);
        toast.appendChild(dot);
        toast.appendChild(textWrap);
        popupStack.appendChild(toast);

        window.setTimeout(() => {
            toast.classList.add("is-leaving");
            window.setTimeout(() => toast.remove(), 220);
        }, popupDuration);
    }

    function collectStatuses(data) {
        const statuses = {};
        (data.monitorGroups || []).forEach((group) => {
            (group.monitors || []).forEach((monitor) => {
                const key = monitor.isAggregate ? "group:" + group.id : "monitor:" + monitor.id;
                statuses[key] = { name: monitor.name, status: monitor.status };
            });
        });

        return statuses;
    }

    async function poll() {
        try {
            const response = await fetch(statsUrl, { headers: { Accept: "application/json" } });
            const payload = await response.json();

            if (!payload || payload.ok !== true) {
                return;
            }

            const current = collectStatuses(payload.data || {});

            if (lastStatuses) {
                Object.keys(current).forEach((key) => {
                    const previous = lastStatuses[key];
                    const now = current[key];
                    if (
                        previous
                        && previous.status !== now.status
                        && (now.status === "down" || now.status === "up")
                    ) {
                        showToast(now.name, now.status);
                    }
                });
            }

            lastStatuses = current;
            try {
                sessionStorage.setItem(storageKey, JSON.stringify(current));
            } catch (error) {
                // Storage unavailable (private mode, quota) - notifications still work for this tab load.
            }
        } catch (error) {
            // Network hiccup: silently retry on the next tick.
        }
    }

    window.setInterval(poll, 30000);
    poll();
})();

/* ==========================================================================
   Monitor detail modal - opened by clicking a server card.
   ========================================================================== */
(function setupMonitorModal() {
    const modal = document.getElementById("monitor-modal");
    const cards = document.querySelectorAll(".server-card[data-payload]");

    if (!modal || cards.length === 0) {
        return;
    }

    const title = document.getElementById("modal-title");
    const subtitle = document.getElementById("modal-subtitle");
    const statusPill = document.getElementById("modal-status-pill");
    const statusLabel = document.getElementById("modal-status-label");
    const periodsWrap = document.getElementById("modal-periods");
    const dayBars = document.getElementById("modal-day-bars");
    const hourBars = document.getElementById("modal-hour-bars");
    const incidentsSection = document.getElementById("modal-incidents-section");
    const incidentsList = document.getElementById("modal-incident-list");
    const closeBtn = document.getElementById("modal-close");

    const periodLabels = { today: "Hoje", "7d": "7 dias", "30d": "30 dias" };
    let lastFocused = null;

    function statusClass(status) {
        return ["up", "down", "partial", "pending", "maintenance"].includes(status)
            ? "status-" + status
            : "status-unknown";
    }

    function buildBar(bar) {
        const span = document.createElement("span");
        span.className = "history-bar " + statusClass(bar.status);
        span.setAttribute("data-tooltip", bar.title || "");
        span.setAttribute("aria-label", bar.title || "");
        return span;
    }

    function renderBars(container, bars) {
        container.innerHTML = "";
        (bars || []).forEach((bar) => container.appendChild(buildBar(bar)));
    }

    function renderPeriods(periods) {
        periodsWrap.innerHTML = "";
        if (!periods) {
            return;
        }

        Object.keys(periodLabels).forEach((key) => {
            const entry = periods[key];
            if (!entry) {
                return;
            }

            const box = document.createElement("div");
            box.className = "modal-period";

            const label = document.createElement("span");
            label.className = "modal-period-label";
            label.textContent = periodLabels[key];

            const value = document.createElement("span");
            value.className = "modal-period-value";
            value.textContent = entry.uptime;

            const sub = document.createElement("span");
            sub.className = "modal-period-sub";
            sub.textContent = entry.incidents + " incid. - " + entry.downtimeLabel + " off";

            box.appendChild(label);
            box.appendChild(value);
            box.appendChild(sub);
            periodsWrap.appendChild(box);
        });
    }

    function renderIncidents(incidents) {
        incidentsList.innerHTML = "";

        if (!incidents || incidents.length === 0) {
            incidentsSection.hidden = true;
            return;
        }

        incidentsSection.hidden = false;

        incidents.forEach((incident) => {
            const row = document.createElement("div");
            row.className = "modal-incident-row" + (incident.ongoing ? " is-ongoing" : "");

            const times = document.createElement("span");
            times.className = "modal-incident-times";
            times.innerHTML =
                "Caiu <strong>" + escapeHtml(incident.downAtLabel) + "</strong> &rarr; voltou <strong>"
                + escapeHtml(incident.upAtLabel) + "</strong>";

            const duration = document.createElement("span");
            duration.className = "pill sm " + (incident.ongoing ? "status-down" : "status-up");
            duration.textContent = incident.durationLabel;

            row.appendChild(times);
            row.appendChild(duration);
            incidentsList.appendChild(row);
        });
    }

    function escapeHtml(value) {
        const div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    }

    function openModal(payload) {
        title.textContent = payload.name || "";
        statusLabel.textContent = payload.statusLabel || "";
        statusPill.className = "pill sm " + statusClass(payload.status);
        subtitle.textContent = payload.isAggregate
            ? "Status agregado: " + (payload.cardStatusLabel || "")
            : (payload.cardStatusLabel || "");

        renderPeriods(payload.periods);
        renderBars(dayBars, payload.historyDayBars);
        renderBars(hourBars, payload.historyHourBars);
        renderIncidents(payload.incidents);

        lastFocused = document.activeElement;
        modal.hidden = false;
        document.documentElement.classList.add("no-scroll");
        closeBtn.focus();
    }

    function closeModal() {
        modal.hidden = true;
        document.documentElement.classList.remove("no-scroll");
        if (lastFocused && typeof lastFocused.focus === "function") {
            lastFocused.focus();
        }
    }

    cards.forEach((card) => {
        const open = () => {
            try {
                const payload = JSON.parse(card.getAttribute("data-payload") || "{}");
                openModal(payload);
            } catch (error) {
                // Malformed payload: ignore the click rather than breaking the page.
            }
        };

        card.addEventListener("click", open);
        card.addEventListener("keydown", (event) => {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                open();
            }
        });
    });

    closeBtn.addEventListener("click", closeModal);
    modal.addEventListener("click", (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !modal.hidden) {
            closeModal();
        }
    });
})();

/* ==========================================================================
   Full-screen announcement pop-up, configurable from the admin panel.
   ========================================================================== */
(function setupAnnouncement() {
    const overlay = document.getElementById("announcement-overlay");
    const body = document.body;

    if (!overlay || body.dataset.announcementEnabled !== "1") {
        return;
    }

    const duration = Math.max(2000, parseInt(body.dataset.announcementDuration || "8000", 10));
    const mode = body.dataset.announcementMode === "always" ? "always" : "once_per_session";
    const version = body.dataset.announcementVersion || "0";
    const storageKey = "kuma-public-announcement-seen";

    if (mode === "once_per_session") {
        try {
            if (sessionStorage.getItem(storageKey) === version) {
                overlay.hidden = true;
                return;
            }
        } catch (error) {
            // Storage unavailable: fall through and show the announcement anyway.
        }
    }

    document.documentElement.classList.add("no-scroll");

    const progressBar = document.getElementById("announcement-progress-bar");
    const skipBtn = document.getElementById("announcement-skip");
    let dismissed = false;

    function dismiss() {
        if (dismissed) {
            return;
        }
        dismissed = true;
        overlay.hidden = true;
        document.documentElement.classList.remove("no-scroll");
        try {
            sessionStorage.setItem(storageKey, version);
        } catch (error) {
            // Ignore storage errors - the announcement will simply show again next time.
        }
    }

    if (progressBar) {
        progressBar.classList.add("is-running");
        progressBar.style.transitionDuration = duration + "ms";
        window.requestAnimationFrame(() => {
            progressBar.style.transform = "scaleX(0)";
        });
    }

    window.setTimeout(dismiss, duration);
    if (skipBtn) {
        skipBtn.addEventListener("click", dismiss);
    }
})();
