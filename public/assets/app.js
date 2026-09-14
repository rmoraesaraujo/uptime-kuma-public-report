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
