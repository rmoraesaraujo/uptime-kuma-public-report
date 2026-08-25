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
