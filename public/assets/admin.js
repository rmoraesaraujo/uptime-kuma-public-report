const tabsRoot = document.querySelector("[data-admin-tabs]");

if (tabsRoot) {
    const tabs = Array.from(tabsRoot.querySelectorAll(".admin-tab"));

    tabs.forEach((tab) => {
        tab.addEventListener("click", () => {
            const targetId = tab.getAttribute("data-tab-target");
            if (!targetId) {
                return;
            }

            tabs.forEach((other) => other.classList.remove("is-active"));
            document.querySelectorAll(".admin-panel").forEach((panel) => panel.classList.remove("is-active"));

            tab.classList.add("is-active");
            const panel = document.getElementById(targetId);
            if (panel) {
                panel.classList.add("is-active");
            }

            window.location.hash = targetId;
        });
    });

    const initialTarget = window.location.hash.replace("#", "");
    const initialTab = tabs.find((tab) => tab.getAttribute("data-tab-target") === initialTarget);
    if (initialTab) {
        initialTab.click();
    }
}
