const filterForm = document.querySelector("#filters");

if (filterForm) {
    filterForm.querySelectorAll("select").forEach((select) => {
        select.addEventListener("change", () => {
            filterForm.requestSubmit();
        });
    });
}
