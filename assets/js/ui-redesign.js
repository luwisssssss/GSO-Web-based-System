document.addEventListener("DOMContentLoaded", function () {
    function updateLiveDateTime() {
        const now = new Date();
        const date = now.toLocaleDateString(undefined, {
            weekday: "short",
            year: "numeric",
            month: "short",
            day: "numeric"
        });
        const time = now.toLocaleTimeString(undefined, {
            hour: "2-digit",
            minute: "2-digit",
            second: "2-digit"
        });

        document.querySelectorAll("[data-live-datetime]").forEach(function (target) {
            const textTarget = target.querySelector("span") || target;
            textTarget.textContent = date + " - " + time;
        });
    }

    if (document.querySelector("[data-live-datetime]")) {
        updateLiveDateTime();
        setInterval(updateLiveDateTime, 1000);
    }

    const iconMap = [
        { match: /approve return|approve|enable|restore/i, icon: "fa-circle-check" },
        { match: /reject|delete|disable|cancel/i, icon: "fa-circle-xmark" },
        { match: /release/i, icon: "fa-box-open" },
        { match: /review return|review/i, icon: "fa-clipboard-check" },
        { match: /view details|view id|view/i, icon: "fa-eye" },
        { match: /edit/i, icon: "fa-pen" },
        { match: /maintenance/i, icon: "fa-screwdriver-wrench" },
        { match: /archive/i, icon: "fa-box-archive" },
        { match: /create admin|add resource|add|create/i, icon: "fa-plus" },
        { match: /export excel/i, icon: "fa-file-excel" },
        { match: /export pdf|print/i, icon: "fa-file-pdf" },
        { match: /filter|search|generate report/i, icon: "fa-filter" },
        { match: /return|resubmit/i, icon: "fa-rotate-left" },
        { match: /submit request|submit return|submit/i, icon: "fa-paper-plane" },
        { match: /back|previous/i, icon: "fa-arrow-left" },
        { match: /next/i, icon: "fa-arrow-right" }
    ];

    document.querySelectorAll(".admin-btn, .request-btn, .small-btn, .profile-link-btn, .return-btn").forEach(function (button) {
        if (button.querySelector("i")) {
            return;
        }

        const label = button.textContent.trim();
        const match = iconMap.find(function (item) {
            return item.match.test(label);
        });

        if (!match) {
            return;
        }

        const icon = document.createElement("i");
        icon.className = "fa-solid " + match.icon;
        icon.setAttribute("aria-hidden", "true");
        button.prepend(icon);
    });

    function getActionNodes(source) {
        return Array.from(source.childNodes).filter(function (node) {
            if (node.nodeType === Node.TEXT_NODE) {
                return node.textContent.trim() !== "";
            }

            return node.nodeType === Node.ELEMENT_NODE;
        });
    }

    function normalizeMenuItem(node) {
        if (!(node instanceof HTMLElement)) {
            return;
        }

        if (node.matches("a, button, .admin-btn, .request-btn, .small-btn, .profile-link-btn, .return-btn")) {
            node.classList.add("row-action-item");
            node.setAttribute("role", "menuitem");
            return;
        }

        if (node.matches("form")) {
            node.classList.add("inline-form");
            const button = node.querySelector("button");
            if (button) {
                button.setAttribute("role", "menuitem");
            }
        }
    }

    function enhanceActionCell(cell, row) {
        if (!cell || cell.querySelector(".row-actions")) {
            return false;
        }

        const actionable = cell.querySelector("a, button, form");
        if (!actionable) {
            return false;
        }

        const source = cell.querySelector(":scope > .action-group") || cell;
        const actionNodes = getActionNodes(source);

        if (!actionNodes.length) {
            return false;
        }

        const wrapper = document.createElement("div");
        wrapper.className = "row-actions row-actions-enhanced";

        const trigger = document.createElement("button");
        trigger.type = "button";
        trigger.className = "row-actions-trigger";
        trigger.setAttribute("data-row-actions-toggle", "");
        trigger.setAttribute("aria-expanded", "false");
        trigger.setAttribute("aria-label", "Open row actions");
        trigger.innerHTML = '<i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>';

        const menu = document.createElement("div");
        menu.className = "row-actions-menu";
        menu.setAttribute("role", "menu");

        actionNodes.forEach(function (node) {
            normalizeMenuItem(node);
            menu.appendChild(node);
        });

        wrapper.appendChild(trigger);
        wrapper.appendChild(menu);

        cell.replaceChildren(wrapper);
        row.classList.add("has-row-actions-menu");
        return true;
    }

    document.querySelectorAll(".request-table, .dashboard-table").forEach(function (table) {
        const headers = Array.from(table.querySelectorAll("thead th")).map(function (header) {
            return header.textContent.trim();
        });

        const actionIndex = headers.findIndex(function (header, index) {
            return /^(action|actions|return|return proof)$/i.test(header) && index === headers.length - 1;
        });

        if (actionIndex !== -1) {
            table.classList.add("table-has-actions");
        }

        let enhancedAnyRow = false;

        table.querySelectorAll("tbody tr").forEach(function (row) {
            Array.from(row.children).forEach(function (cell, index) {
                if (headers[index] && !cell.hasAttribute("data-label")) {
                    cell.setAttribute("data-label", headers[index]);
                }
            });

            if (actionIndex !== -1) {
                const actionCell = row.children[actionIndex];

                if (actionCell && actionCell.querySelector(".row-actions")) {
                    enhancedAnyRow = true;
                } else {
                    enhancedAnyRow = enhanceActionCell(actionCell, row) || enhancedAnyRow;
                }
            }
        });

        if (enhancedAnyRow) {
            table.classList.add("table-actions-menu-enhanced");
        }
    });

    document.querySelectorAll("form").forEach(function (form) {
        form.addEventListener("submit", function () {
            const submitter = form.querySelector("button[type='submit']");

            if (!submitter || submitter.dataset.noLoading === "1") {
                return;
            }

            submitter.classList.add("is-loading");
            submitter.setAttribute("aria-busy", "true");
        });
    });

    document.addEventListener("click", function (event) {
        const quickButton = event.target.closest("[data-toggle-quick-actions]");
        const quickMenu = document.getElementById("quickActionsDropdown");
        const rowActionButton = event.target.closest("[data-row-actions-toggle]");

        if (quickButton && quickMenu) {
            event.stopPropagation();
            quickMenu.classList.toggle("show");
            return;
        }

        if (rowActionButton) {
            event.stopPropagation();
            const currentMenu = rowActionButton.closest(".row-actions");
            const isOpen = currentMenu && currentMenu.classList.contains("show");

            document.querySelectorAll(".row-actions.show").forEach(function (menu) {
                if (menu !== currentMenu) {
                    menu.classList.remove("show");
                    const row = menu.closest("tr");
                    if (row) {
                        row.classList.remove("row-action-open");
                    }
                    const trigger = menu.querySelector("[data-row-actions-toggle]");
                    if (trigger) {
                        trigger.setAttribute("aria-expanded", "false");
                    }
                }
            });

            if (currentMenu) {
                currentMenu.classList.toggle("show", !isOpen);
                const row = currentMenu.closest("tr");
                if (row) {
                    row.classList.toggle("row-action-open", !isOpen);
                }
                rowActionButton.setAttribute("aria-expanded", String(!isOpen));
            }

            return;
        }

        if (quickMenu && !event.target.closest(".quick-actions-wrapper")) {
            quickMenu.classList.remove("show");
        }

        if (!event.target.closest(".row-actions")) {
            document.querySelectorAll(".row-actions.show").forEach(function (menu) {
                menu.classList.remove("show");
                const row = menu.closest("tr");
                if (row) {
                    row.classList.remove("row-action-open");
                }
                const trigger = menu.querySelector("[data-row-actions-toggle]");
                if (trigger) {
                    trigger.setAttribute("aria-expanded", "false");
                }
            });
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") {
            return;
        }

        document.querySelectorAll(".row-actions.show").forEach(function (menu) {
            menu.classList.remove("show");
            const row = menu.closest("tr");
            if (row) {
                row.classList.remove("row-action-open");
            }
            const trigger = menu.querySelector("[data-row-actions-toggle]");
            if (trigger) {
                trigger.setAttribute("aria-expanded", "false");
                trigger.focus();
            }
        });
    });
});
