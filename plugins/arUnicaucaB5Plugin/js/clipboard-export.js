const initializeClipboardExportPolling = () => {
  const $ = window.jQuery;
  const menu = document.getElementById("clipboard-menu");

  if (!$ || !menu) {
    return;
  }

  const exportCheckUrl = menu.dataset.exportCheckUrl;
  if (!exportCheckUrl) {
    return;
  }

  let polling = null;

  const checkExports = () => {
    let tokens = [];
    try {
      tokens = JSON.parse(localStorage.getItem("exportTokens")) || [];
    } catch (_error) {
      tokens = [];
    }

    if (0 === tokens.length) {
      if (polling) {
        window.clearInterval(polling);
        polling = null;
      }
      return;
    }

    $.ajax({
      url: exportCheckUrl,
      type: "POST",
      cache: false,
      data: { tokens },
      success: (data) => {
        const completed = new Set();

        (data.alerts || []).forEach((alert) => {
          if ("alert-success" === `alert-${alert.type}`) {
            completed.add(alert.token);
          const existing = Array.from(document.querySelectorAll("body > #wrapper > .alert-success"))
            .some((element) => element.textContent.includes("Download"));
          if (existing) {
            return;
          }
            const $alert = $(
              '<div class="alert alert-success alert-dismissible fade show" role="alert"></div>'
            ).append(alert.message);
            $alert.append(
              '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>'
            );
            $alert.prependTo($("body > #wrapper"));
            window.scrollTo({ top: 0 });
          }
        });

        if (completed.size) {
          const remaining = tokens.filter((token) => !completed.has(token));
          localStorage.setItem("exportTokens", JSON.stringify(remaining));
          if (0 === remaining.length && polling) {
            window.clearInterval(polling);
            polling = null;
          }
        }
      },
    });
  };

  polling = window.setInterval(checkExports, 5000);
  checkExports();
};

if ("loading" === document.readyState) {
  document.addEventListener("DOMContentLoaded", initializeClipboardExportPolling);
} else {
  initializeClipboardExportPolling();
}
