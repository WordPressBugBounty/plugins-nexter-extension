/**
 * Bulk Image Optimisation - Admin Script
 */
(function ($) {
  "use strict";

  var NxtBulkImages = {
    queue: [],
    stats: null,
    hasMore: false,
    queueLimit: 6,
    loadMoreXhr: null,
    isProcessing: false,
    stopRequested: false,
    currentXhr: null,
    processedCount: 0,
    skippedCount: 0,
    failedCount: 0,
    totalSavings: 0,
    sessionProcessed: 0,
    externalRunInProgress: false,
    bulkStatusPollTimer: null,
    bulkLockHeartbeat: null,
    bulkTabId: null,

    init: function () {
      this.bindEvents();
      this.initTabId();
      this.bindLockEvents();
      var dataEl = document.getElementById("nxt-bulk-initial-data");
      if (dataEl && dataEl.textContent) {
        try {
          var data = JSON.parse(dataEl.textContent);
          if (
            data &&
            (Array.isArray(data.queue) ||
              (data.stats && typeof data.stats === "object"))
          ) {
            this.queue = data.queue || [];
            this.stats = data.stats || {};
            this.hasMore = !!data.has_more;
            this.processedCount = 0;
            this.failedCount = 0;
            this.totalSavings = 0;
            this.skippedCount = (data.stats && (data.stats.skipped !== undefined && data.stats.skipped !== null)) ? data.stats.skipped : 0;
            this.renderUI();
            this.updateStats();
            this.updateButtonState();
            this.updateLoadMoreButton();
            this.checkBulkStatusAndMaybePoll();
            return;
          }
        } catch (e) {}
      }
      this.loadQueue();
    },

    checkBulkStatusAndMaybePoll: function () {
      var self = this;
      var lock = self.readBulkLock();
      if (lock && self.isLockStale(lock)) {
        // Stale lock cleanup (tab was closed/crashed).
        try { localStorage.removeItem(self.getBulkLockKey()); } catch (e) {}
        lock = null;
      }
      if (lock && !self.isLockOwnedByThisTab(lock) && !self.isProcessing) {
        self.externalRunInProgress = true;
        self.updateButtonState();
        self.startBulkStatusPolling();
      } else {
        self.externalRunInProgress = false;
        self.stopBulkStatusPolling();
      }
    },


    startBulkStatusPolling: function () {
      var self = this;
      this.stopBulkStatusPolling();
      this.bulkStatusPollTimer = setInterval(function () {
        if (self.isProcessing) return;
        self.checkBulkStatusAndMaybePoll();
        if (!self.externalRunInProgress) {
          self.stopBulkStatusPolling();
          self.loadQueue();
          self.updateButtonState();
        }
      }, 5000);
    },

    stopBulkStatusPolling: function () {
      if (this.bulkStatusPollTimer) {
        clearInterval(this.bulkStatusPollTimer);
        this.bulkStatusPollTimer = null;
      }
    },

    initTabId: function () {
      try {
        var existing = sessionStorage.getItem("nxt_bulk_images_tab_id");
        if (existing) {
          this.bulkTabId = existing;
          return;
        }
        // After reload, sessionStorage may be empty but lock still exists (we don't release on beforeunload).
        // Adopt the lock's tabId so this tab keeps the same id and still "owns" the run (progress doesn't stop).
        var lock = this.readBulkLock();
        if (lock && lock.tabId) {
          this.bulkTabId = lock.tabId;
          try {
            sessionStorage.setItem("nxt_bulk_images_tab_id", lock.tabId);
          } catch (e) {}
          // Refresh lock so it isn't treated as stale (we are the same logical tab after reload).
          try {
            localStorage.setItem(this.getBulkLockKey(), JSON.stringify({ tabId: this.bulkTabId, ts: Date.now() }));
          } catch (e) {}
          return;
        }
        var id = "tab_" + String(Date.now()) + "_" + String(Math.random()).slice(2);
        sessionStorage.setItem("nxt_bulk_images_tab_id", id);
        this.bulkTabId = id;
      } catch (e) {
        this.bulkTabId = "tab_" + String(Date.now()) + "_" + String(Math.random()).slice(2);
      }
    },

    getBulkLockKey: function () {
      return "nxt_bulk_images_lock";
    },

    readBulkLock: function () {
      try {
        var raw = localStorage.getItem(this.getBulkLockKey());
        if (!raw) return null;
        var parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== "object") return null;
        return parsed;
      } catch (e) {
        return null;
      }
    },

    isLockStale: function (lock) {
      if (!lock || !lock.ts) return true;
      return (Date.now() - Number(lock.ts)) > 20000;
    },

    isLockOwnedByThisTab: function (lock) {
      return !!(lock && lock.tabId && this.bulkTabId && lock.tabId === this.bulkTabId);
    },

    acquireLocalBulkLock: function () {
      var lock = this.readBulkLock();
      if (lock && !this.isLockStale(lock) && !this.isLockOwnedByThisTab(lock)) {
        return false;
      }
      try {
        localStorage.setItem(this.getBulkLockKey(), JSON.stringify({ tabId: this.bulkTabId, ts: Date.now() }));
        return true;
      } catch (e) {
        return false;
      }
    },

    releaseLocalBulkLock: function () {
      var lock = this.readBulkLock();
      if (lock && this.isLockOwnedByThisTab(lock)) {
        try { localStorage.removeItem(this.getBulkLockKey()); } catch (e) {}
      }
    },

    startLockHeartbeat: function () {
      var self = this;
      this.stopLockHeartbeat();
      this.bulkLockHeartbeat = setInterval(function () {
        if (!self.isProcessing) return;
        var lock = self.readBulkLock();
        if (!lock || !self.isLockOwnedByThisTab(lock)) return;
        try {
          localStorage.setItem(self.getBulkLockKey(), JSON.stringify({ tabId: self.bulkTabId, ts: Date.now() }));
        } catch (e) {}
      }, 5000);
    },

    stopLockHeartbeat: function () {
      if (this.bulkLockHeartbeat) {
        clearInterval(this.bulkLockHeartbeat);
        this.bulkLockHeartbeat = null;
      }
    },

    bindLockEvents: function () {
      var self = this;
      window.addEventListener("storage", function (ev) {
        if (!ev || ev.key !== self.getBulkLockKey()) return;
        if (self.isProcessing) return;
        self.checkBulkStatusAndMaybePoll();
      });
      window.addEventListener("beforeunload", function () {
        self.stopLockHeartbeat();
        // Do not release the lock on unload (reload/close). Keeps lock so after reload we can
        // adopt the same tabId and avoid generating a new id (progress/other-tab state does not stop).
        // Lock expires by staleness (20s) if tab is closed.
      });
    },

    bindEvents: function () {
      var self = this;
      $(document).on("click", "#nxt-bulk-start-btn", function () {
        self.startBulk();
      });
      $(document).on("click", "#nxt-bulk-stop-btn", function () {
        self.stopBulk();
      });
      $(document).on("click", "#nxt-bulk-retry-failed-btn", function () {
        self.retryFailed();
      });
      $(document).on("click", "#nxt-bulk-load-more-btn", function () {
        self.loadMoreQueue();
      });
    },

    loadQueue: function (fromFinish) {
      var self = this;
      var hadNoFailures = this.failedCount === 0;
      $.post(nxtBulkImages.ajaxUrl, {
        action: "nxt_bulk_images_get_queue",
        nonce: nxtBulkImages.nonce,
        limit: self.queueLimit,
        exclude_ids: JSON.stringify([]),
      })
        .done(function (r) {
          if (r && r.success && r.data) {
            self.queue = r.data.queue || [];
            self.stats = r.data.stats || {};
            self.hasMore = !!r.data.has_more;
            self.processedCount = 0;
            self.failedCount = 0;
            self.totalSavings = 0;
            self.renderUI();
            self.updateStats();
            self.updateButtonState();
            self.updateLoadMoreButton();
            self.checkBulkStatusAndMaybePoll();
            if (fromFinish && hadNoFailures && self.queue.length === 0) {
              $("#nxt-bulk-queue-list").hide();
              $("#nxt-bulk-queue-empty").hide();
              $("#nxt-bulk-queue-complete").show();
              self.showToast(
                nxtBulkImages.i18n.toastAllComplete ||
                  "All images have been optimised successfully!",
                "success",
              );
            }
          } else {
            self.showEmpty(
              nxtBulkImages.i18n.noImages || "No images to optimise.",
            );
          }
        })
        .fail(function () {
          self.showEmpty(nxtBulkImages.i18n.failedLoadQueue || "Failed to load queue.");
        });
    },

    loadMoreQueue: function (autoLoad) {
      var self = this;
      if (this.loadMoreXhr) return;
      var $btn = $("#nxt-bulk-load-more-btn");
      var excludeIds = this.queue.map(function (q) {
        return q.id;
      });
      if (!autoLoad) {
        $btn
          .prop("disabled", true)
          .text(nxtBulkImages.i18n.loadingMore || "Loading...");
      }
      this.loadMoreXhr = $.post(nxtBulkImages.ajaxUrl, {
        action: "nxt_bulk_images_get_queue",
        nonce: nxtBulkImages.nonce,
        limit: self.queueLimit,
        exclude_ids: JSON.stringify(excludeIds),
      })
        .done(function (r) {
          if (
            r &&
            r.success &&
            r.data &&
            r.data.queue &&
            r.data.queue.length > 0
          ) {
            self.queue = self.queue.concat(r.data.queue);
            self.hasMore = !!r.data.has_more;
            self.stats = r.data.stats || self.stats;
            self.appendQueueItems(r.data.queue);
            self.updateStats();
            self.updateButtonState();
            self.updateLoadMoreButton();
          }
        })
        .fail(function () {
          $btn
            .prop("disabled", false)
            .text(nxtBulkImages.i18n.loadMore || "Load More");
        })
        .always(function () {
          self.loadMoreXhr = null;
          if (!autoLoad) {
            $btn
              .prop("disabled", false)
              .text(nxtBulkImages.i18n.loadMore || "Load More");
          }
        });
    },

    updateLoadMoreButton: function () {
      if (this.hasMore && !this.isProcessing) {
        $("#nxt-bulk-queue-load-more").show();
        $("#nxt-bulk-load-more-btn")
          .prop("disabled", false)
          .text(nxtBulkImages.i18n.loadMore || "Load More");
      } else {
        $("#nxt-bulk-queue-load-more").hide();
      }
    },

    /**
     * Remove completed (data-status="done") items from the list DOM to keep scroll light with many images.
     * Counts and progress still use this.queue; only the visible list is trimmed.
     */
    removeDoneItemsFromList: function () {
      $("#nxt-bulk-queue-list .nxt-bulk-queue-item[data-status=\"done\"]").remove();
    },

    appendQueueItems: function (items) {
      var $list = $("#nxt-bulk-queue-list");
      var i18n = nxtBulkImages.i18n || {};
      items.forEach(function (item) {
        var status = item.status || "pending";
        var $item = $(
          '<div class="nxt-bulk-queue-item" data-id="' +
            item.id +
            '" data-status="' +
            status +
            '">',
        );
        var iconHtml = NxtBulkImages.getItemIconHtml(item, status);
        $item.append(iconHtml);
        var details = item.original_size
          ? (i18n.originalSize || "Original Size") +
            ": <span>" +
            NxtBulkImages.formatSize(item.original_size) +
            "</span>"
          : "";
        var statusHtml =
          '<div class="nxt-bulk-item-status-wrap"><span class="nxt-bulk-item-status status-' +
          status +
          '">' +
          (i18n.needToOptimise || "Need to Optimise") +
          "</span></div>";
        var infoHtml =
          '<div class="nxt-bulk-item-info"><div class="nxt-bulk-item-filename">' +
          NxtBulkImages.escapeHtml(item.filename || "") +
          '</div><div class="nxt-bulk-item-details">' +
          details +
          "</div></div>";
        $item.append(infoHtml + statusHtml);
        $list.append($item);
      });
     
      this.updateProgress();
      this.updateRetryButton();
    },

    renderUI: function () {
      var $list = $("#nxt-bulk-queue-list");
      var $empty = $("#nxt-bulk-queue-empty");
      var $complete = $("#nxt-bulk-queue-complete");

      $list.empty().show();
      $empty.hide();
      $complete.hide();

      if (this.queue.length === 0) {
        $list.hide();
        $("#nxt-bulk-queue-items").text("");
        if (
          this.stats &&
          this.stats.optimized > 0 &&
          this.stats.unoptimized === 0
        ) {
          $complete.show();
        } else {
          $empty
            .show()
            .find("p")
            .text(nxtBulkImages.i18n.noImages || "No images to optimise.");
        }
        return;
      }

      var i18n = nxtBulkImages.i18n || {};
      this.queue.forEach(function (item) {
        var status = item.status || "pending";
        var $item = $(
          '<div class="nxt-bulk-queue-item" data-id="' +
            item.id +
            '" data-status="' +
            status +
            '">',
        );
        var iconClass = "icon-" + status;
        var iconHtml = NxtBulkImages.getItemIconHtml(item, status);
        $item.append(iconHtml);

        var details = "";
        if (
          item.status === "done" &&
          item.original_size &&
          item.optimized_size !== undefined
        ) {
          details =
            (i18n.before || "Before") +
            ": <span>" +
            NxtBulkImages.formatSize(item.original_size) +
            "</span> " +
            (i18n.after || "After") +
            ": <span>" +
            NxtBulkImages.formatSize(item.optimized_size || 0) +
            "</span>";
        } else if (item.original_size) {
          details =
            (i18n.originalSize || "Original Size") +
            ": <span>" +
            NxtBulkImages.formatSize(item.original_size) +
            "</span>";
        }
        details += "";

        var statusHtml = '<div class="nxt-bulk-item-status-wrap">';
        if (status === "processing") {
          statusHtml +=
            '<div class="nxt-bulk-item-progress-circle" data-pct="0"><svg viewBox="0 0 36 36"><path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/><path class="circle-fill" stroke-dasharray="0, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/></svg><span class="pct">0%</span></div>';
        } else {
          var statusText =
            status === "done"
              ? i18n.done || "Done"
              : status === "failed"
                ? i18n.failed || "Failed"
                : i18n.needToOptimise || "Need to Optimise";
          if (status === "done") {
            statusHtml +=
              '<span class="nxt-bulk-item-status status-done"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> ' +
              statusText +
              "</span>";
          } else if (status === "failed") {
            statusHtml +=
              '<span class="nxt-bulk-item-status status-failed"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg> ' +
              statusText +
              "</span>";
          } else {
            statusHtml +=
              '<span class="nxt-bulk-item-status status-' +
              status +
              '">' +
              statusText +
              "</span>";
          }
          if (status === "done" && item.saved_pct !== undefined) {
            statusHtml +=
              ' <span class="nxt-bulk-item-saved"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8 L8 12 L12 8 L16 12 L20 8"/></svg> ' +
              (i18n.saved || "%s%% saved").replace("%s", item.saved_pct) +
              "</span>";
          }
        }
        statusHtml += "</div>";

        var infoHtml =
          '<div class="nxt-bulk-item-info">' +
          '<div class="nxt-bulk-item-filename">' +
          NxtBulkImages.escapeHtml(item.filename || "") +
          "</div>";
        if (status === "done") {
          infoHtml +=
            '<div class="nxt-bulk-item-progress-row"><div class="nxt-bulk-item-progress-bar-full nxt-bulk-item-progress-complete"><div class="nxt-bulk-item-progress-fill" style="width:100%"></div></div></div>';
        }
        infoHtml +=
          '<div class="nxt-bulk-item-details">' + details + "</div></div>";
        $item.append(infoHtml + statusHtml);
        $list.append($item);
      });

      this.updateProgress();
      this.updateRetryButton();
      this.updateLoadMoreButton();
    },

    updateStats: function () {
      var s = this.stats || {};
      var totalSaved = (s.storage_saved || 0) + this.totalSavings;
      $("#nxt-bulk-stat-saved").text(
        totalSaved > 0 ? NxtBulkImages.formatSize(totalSaved) : "-",
      );
      $("#nxt-bulk-stat-compression").text(
        s.avg_compression > 0 ? s.avg_compression + "%" : "-",
      );
      $("#nxt-bulk-stat-count").text((s.optimized || 0) + "/" + (s.total || 0));
      $("#nxt-bulk-stat-remaining").text(s.unoptimized || 0);
      $("#nxt-bulk-stat-skipped").text(
        (s.skipped !== undefined && s.skipped !== null) ? s.skipped : (this.skippedCount || "0"),
      );
      var totalMb = totalSaved > 0 ? (totalSaved / 1024 / 1024).toFixed(2) : 0;
      $("#nxt-bulk-stat-total-savings").text(
        totalSaved > 0 ? totalMb + " MB" : "-",
      );

      var monthlyCount = s.monthly_count || 0;
      var monthlyLimit = s.monthly_limit || 500;
      var isPro = !!s.is_pro;
      var usageText = isPro ? (nxtBulkImages.i18n.unlimited || "Unlimited") : monthlyCount + " / " + monthlyLimit;
      var $usageEl = $("#nxt-bulk-stat-monthly-usage");
      
      // Update the text portion robustly
      $usageEl.contents().filter(function() {
          return this.nodeType === 3;
      }).remove();
      $usageEl.prepend(document.createTextNode(usageText));
 
      if (isPro) {
        $(".nxt-bulk-limit-notice").remove();
      } else {
        var remaining = monthlyLimit - monthlyCount;
        if (remaining <= 0) {
          if ($(".nxt-bulk-limit-notice").length === 0) {
            var noticeTitle = nxtBulkImages.i18n.monthlyLimitReached || "Monthly Limit Reached";
            var noticeDesc = (nxtBulkImages.i18n.monthlyLimitNotice || "You have reached your monthly limit of %d images. Upgrade to Pro for unlimited optimisation or wait until next month.").replace('%d', monthlyLimit);
            var noticeHtml =
              '<div class="nxt-bulk-limit-notice">' +
              '<div class="nxt-bulk-limit-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24"><path stroke="#ff1400" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg></div>' +
              '<div class="nxt-bulk-limit-text">' +
              "<h4>" + noticeTitle + "</h4>" +
              "<p>" + noticeDesc + "</p>" +
              "</div>" +
              '<a href="https://nexterwp.com/pricing/" target="_blank" class="nxt-bulk-upgrade-btn">' + (nxtBulkImages.i18n.upgradeToPro || "Upgrade to Pro") + '</a>' +
              "</div>";
            $(".nxt-bulk-stats-row").first().after(noticeHtml);
          }
        } else {
          $(".nxt-bulk-limit-notice").remove();
        }
      }

      var statusText =
        nxtBulkImages.i18n.readyToOptimise || "Ready to Optimise";
      var iconClass = "icon-ready";
      var queueFailed = this.queue.filter(function (q) {
        return q.status === "failed";
      }).length;
      var queueDone = this.queue.filter(function (q) {
        return q.status === "done";
      }).length;
      var hasFailed = queueFailed > 0;
      var allOptimized =
        (s.optimized > 0 &&
          (s.unoptimized === 0 || s.unoptimized === undefined)) ||
        (this.queue.length > 0 && queueDone === this.queue.length);
      if (this.isProcessing) {
        statusText = nxtBulkImages.i18n.optimising || "Optimising...";
        iconClass = "icon-optimising";
      } else if (hasFailed) {
        statusText = nxtBulkImages.i18n.errorsFound || "Errors Found";
        iconClass = "icon-errors";
      } else if (allOptimized) {
        statusText = nxtBulkImages.i18n.completed || "Completed";
        iconClass = "icon-completed";
      }
      $("#nxt-bulk-stat-status").text(statusText);
      $("#nxt-bulk-stat-status-icon")
        .removeClass("icon-ready icon-optimising icon-errors icon-completed")
        .addClass(iconClass)
        .html(NxtBulkImages.getStatusIconHtml(iconClass));

    },

    updateProgress: function (onprogress = false) {
      var total = this.queue.length;
      var doneCount = this.queue.filter(function (q) {
        return q.status === "done";
      }).length;
      var failedCount = this.queue.filter(function (q) {
        return q.status === "failed";
      }).length;
      var done = doneCount + failedCount;
      var pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
      var $bar = $("#nxt-bulk-progress-bar");
      var $wrap = $bar.closest(".nxt-bulk-progress-bar-wrap");
      $bar.css("width", pct + "%");
      $wrap.toggleClass("nxt-bulk-progress-complete", pct >= 100);
      $("#nxt-progress-percentage").text(pct + "%");
      if(onprogress) {
        var progText = (
        "Keep this tab open to continue image optimisation, or enable Background Optimisation to run automatically." || nxtBulkImages.i18n.imagesOptimized || "%d of %d images optimised"
        )
          .replace(/%d/, done)
          .replace(/%d/, total);
        $("#nxt-progress-text").text(progText);
      } else {
        var progText = (
          nxtBulkImages.i18n.imagesOptimized || "%d of %d images optimised"
        )
          .replace(/%d/, done)
          .replace(/%d/, total);
        $("#nxt-progress-text").text(progText);
      }
      var itemsLeft = this.queue.filter(function (q) {
        return q.status === "pending";
      }).length;
      $("#nxt-bulk-queue-items").text(
        (nxtBulkImages.i18n.itemsLeft || "%d items left").replace(
          "%d",
          itemsLeft,
        ),
      );
    },

    updateRetryButton: function () {
      var failedCount = this.queue.filter(function (q) {
        return q.status === "failed";
      }).length;
      if (failedCount > 0) {
        $("#nxt-bulk-retry-failed-btn")
          .show()
          .find("#nxt-bulk-retry-failed-text")
          .text(
            (
              nxtBulkImages.i18n.retryFailedCount || "Retry Failed (%d)"
            ).replace("%d", failedCount),
          );
      } else {
        $("#nxt-bulk-retry-failed-btn").hide();
      }
    },

    updateQueueItemToProcessing: function ($el, item) {
      var i18n = nxtBulkImages.i18n || {};
      var hasProgress = $el.find(".nxt-bulk-item-progress-row").length;
      if (!hasProgress) {
        $el
          .find(".nxt-bulk-item-icon")
          .replaceWith(NxtBulkImages.getItemIconHtml(item, "processing"));
        $el
          .find(".nxt-bulk-item-details")
          .before(
            '<div class="nxt-bulk-item-progress-row"><div class="nxt-bulk-item-progress-bar-full"><div class="nxt-bulk-item-progress-fill" style="width:0%"></div></div></div>',
          );
        $el
          .find(".nxt-bulk-item-status-wrap")
          .html(
            '<div class="nxt-bulk-item-progress-circle" data-pct="0"><svg viewBox="0 0 36 36"><path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/><path class="circle-fill" stroke-dasharray="0, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/></svg><span class="pct">0%</span></div>',
          );
      }
      if (item && item.original_size) {
        var details =
          (i18n.before || "Before") +
          ": <span>" +
          NxtBulkImages.formatSize(item.original_size) +
          "</span> " +
          (i18n.after || "After") +
          ": <span>-</span>";
        $el.find(".nxt-bulk-item-details").html(details);
      }
    },

    simulateProgress: function ($el) {
      var self = this;
      var pct = 0;
      var iv = setInterval(function () {
        if (self.stopRequested || $el.attr("data-status") !== "processing") {
          clearInterval(iv);
          return;
        }
        pct = Math.min(pct + 8, 85);
        $el.find(".nxt-bulk-item-progress-fill").css("width", pct + "%");
        var circ = $el.find(".circle-fill")[0];
        if (circ) {
          circ.setAttribute("stroke-dasharray", pct + ", 100");
        }
        $el.find(".nxt-bulk-item-progress-circle .pct").text(pct + "%");
        if (pct >= 85) clearInterval(iv);
      }, 80);
    },

    updateQueueItemToDone: function ($el, item) {
      var i18n = nxtBulkImages.i18n || {};
      $el
        .attr("data-status", "done")
        .removeClass("status-processing")
        .addClass("status-done");
      $el
        .find(".nxt-bulk-item-icon")
        .replaceWith(NxtBulkImages.getItemIconHtml(item, "done"));
      var details =
        (i18n.before || "Before") +
        ": <span>" +
        NxtBulkImages.formatSize(item.original_size) +
        "</span> " +
        (i18n.after || "After") +
        ": <span>" +
        NxtBulkImages.formatSize(item.optimized_size || 0) +
        "</span>";
      $el.find(".nxt-bulk-item-details").html(details);
      if ($el.find(".nxt-bulk-item-progress-row").length === 0) {
        $el
          .find(".nxt-bulk-item-details")
          .before(
            '<div class="nxt-bulk-item-progress-row"><div class="nxt-bulk-item-progress-bar-full nxt-bulk-item-progress-complete"><div class="nxt-bulk-item-progress-fill" style="width:100%"></div></div></div>',
          );
      } else {
        $el
          .find(".nxt-bulk-item-progress-fill")
          .css("width", "100%")
          .end()
          .find(".nxt-bulk-item-progress-bar-full")
          .addClass("nxt-bulk-item-progress-complete");
      }
      var statusHtml =
        '<span class="nxt-bulk-item-status status-done"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16"><path stroke="#058645" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.333" d="M13.334 4 6 11.333 2.667 8"/></svg> ' +
        (i18n.done || "Done") +
        "</span>";
      if (item.saved_pct !== undefined) {
        statusHtml +=
          ' <span class="nxt-bulk-item-saved"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16"><path stroke="#058645" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.333" d="M14.667 11.333 9.001 5.666 5.667 8.999 1.334 4.666"/><path stroke="#058645" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.333" d="M10.666 11.334h4v-4"/></svg> ' +
          (i18n.saved || "%s% saved").replace("%s", item.saved_pct) +
          "</span>";
      }
      $el.find(".nxt-bulk-item-status-wrap").html(statusHtml);
    },

    updateQueueItemToFailed: function ($el, item) {
      var i18n = nxtBulkImages.i18n || {};
      $el
        .attr("data-status", "failed")
        .removeClass("status-processing")
        .addClass("status-failed");
      $el
        .find(".nxt-bulk-item-icon")
        .replaceWith(NxtBulkImages.getItemIconHtml(item, "failed"));
      $el.find(".nxt-bulk-item-progress-row").remove();
      if (item && item.original_size) {
        $el
          .find(".nxt-bulk-item-details")
          .text(
            (i18n.originalSize || "Original Size") +
              ": " +
              NxtBulkImages.formatSize(item.original_size),
          );
      }
      var statusHtml =
        '<span class="nxt-bulk-item-status status-failed"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 16 16"><g stroke="#ff1400" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.333" clip-path="url(#adfg)"><path d="M8 14.667A6.667 6.667 0 1 0 8 1.334a6.667 6.667 0 0 0 0 13.333M10 6l-4 4M6 6l4 4"/></g><defs><clipPath id="adfg"><path fill="#fff" d="M0 0h16v16H0z"/></clipPath></defs></svg> ' +
        (i18n.failed || "Failed") +
        "</span>";
      if (item.fail_reason && item.fail_reason.trim()) {
        statusHtml += '<div class="nxt-bulk-item-fail-reason">' + $("<div>").text(item.fail_reason).html() + "</div>";
      }
      $el.find(".nxt-bulk-item-status-wrap").html(statusHtml);
    },

    startBulk: function () {
      if (this.isProcessing || this.queue.length === 0) return;
      var self = this;
      var fallbackMsg = nxtBulkImages.i18n.bulkAlreadyRunning || "Optimisation is already running in another tab or window. Please wait for it to complete.";
      self.checkBulkStatusAndMaybePoll();
      if (self.externalRunInProgress) {
        self.showToast(fallbackMsg, "error");
        return;
      }
      if (!self.acquireLocalBulkLock()) {
        self.checkBulkStatusAndMaybePoll();
        self.showToast(fallbackMsg, "error");
        return;
      }
      self.isProcessing = true;
      self.stopRequested = false;
      self.sessionProcessed = 0;
      self.startLockHeartbeat();
      self.checkBulkStatusAndMaybePoll();
      self.updateButtonState();
      self.processNext();
    },

    stopBulk: function () {
      var self = this;
      this.stopRequested = true;
      if (this.currentXhr && this.currentXhr.abort) {
        this.currentXhr.abort();
        this.currentXhr = null;
      }
      this.queue.forEach(function (q) {
        if (q.status === "processing") q.status = "pending";
      });
      this.isProcessing = false;
      $("#nxt-bulk-stop-btn").removeClass("nxt-bulk-btn-stop").hide();
      this.stopLockHeartbeat();
      this.releaseLocalBulkLock();
      this.checkBulkStatusAndMaybePoll();
      this.renderUI();
      this.updateButtonState();
    },

    processNext: function () {
      var self = this;
      if (this.stopRequested) {
        this.isProcessing = false;
        this.stopLockHeartbeat();
        this.releaseLocalBulkLock();
        this.checkBulkStatusAndMaybePoll();
        this.updateButtonState();
        return;
      }
      // Only pick pending items. Failed items are retried only when user clicks "Retry Failed" (they become pending).
      // This prevents infinite AJAX loops on images that repeatedly fail.
      var next = this.queue.find(function (q) {
        return q.status === "pending";
      });
      if (!next) {
        this.finishBulk();
        return;
      }
      if (this.hasMore && !this.loadMoreXhr) {
        var pending = this.queue.filter(function (q) {
          return q.status === "pending";
        }).length;
        if (pending <= 2) {
          this.loadMoreQueue(true);
          this.removeDoneItemsFromList();
        }
      }
      var $el = $('.nxt-bulk-queue-item[data-id="' + next.id + '"]');
      $el
        .attr("data-status", "processing")
        .removeClass("status-done status-failed status-pending")
        .addClass("status-processing");
      self.updateQueueItemToProcessing($el, next);
      self.simulateProgress($el);

      self.currentXhr = $.post(nxtBulkImages.ajaxUrl, {
        action: "nxt_ext_image_convert_attachment",
        nonce: nxtBulkImages.convertNonce,
        attachment_id: next.id,
      })
        .done(function (r) {
          if (r && r.success) {
            next.status = "done";
            next.optimized_size = r.data && r.data.optimized_size;
            next.saved_pct = r.data && r.data.saved_percent;
            self.updateQueueItemToDone($el, next);
            self.processedCount++;
            self.sessionProcessed++;
            if (r.data && r.data.stats) {
              self.stats = r.data.stats;
              self.totalSavings = 0;
            } else {
              self.totalSavings +=
                (next.original_size || 0) -
                ((r.data && r.data.optimized_size) || 0);
            }
            self.updateStats();
          } else {
            next.status = "failed";
            next.fail_reason = (r.data && r.data.message) || r.message || "";
            self.updateQueueItemToFailed($el, next);
            self.failedCount++;
          }
        })
        .fail(function (xhr) {
          next.status = "failed";
          var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || (xhr.responseJSON && xhr.responseJSON.message) || (xhr.statusText || "Request failed");
          next.fail_reason = typeof msg === "string" ? msg : "Request failed";
          self.updateQueueItemToFailed($el, next);
          self.failedCount++;
        })
        .always(function () {
          self.currentXhr = null;
          self.updateProgress(true);
          self.updateStats();
          self.updateRetryButton();
          setTimeout(function () {
            self.processNext();
          }, 100);
        });
    },

    finishBulk: function () {
      this.isProcessing = false;
      this.stopLockHeartbeat();
      this.releaseLocalBulkLock();
      this.checkBulkStatusAndMaybePoll();
      this.updateButtonState();
      this.loadQueue(true);
    },

    updateButtonState: function () {
      var $start = $("#nxt-bulk-start-btn");
      var $stop = $("#nxt-bulk-stop-btn");
      var total = this.queue.length;
      var queueDone = this.queue.filter(function (q) {
        return q.status === "done";
      }).length;
      var queueFailed = this.queue.filter(function (q) {
        return q.status === "failed";
      }).length;
      var done = queueDone + queueFailed;
      var isDone = total > 0 && done >= total;

      if (this.externalRunInProgress) {
        $start.prop("disabled", true).addClass("nxt-bulk-btn-optimizing").show();
        $start.find(".nxt-bulk-btn-icon-play").show();
        $start.find(".nxt-bulk-btn-icon-spinner").hide();
        $start.find(".nxt-bulk-btn-text").text(nxtBulkImages.i18n.optimising || "Optimising...");
        $stop.hide();
        return;
      }

      if (this.isProcessing) {
        $start
          .find(".nxt-bulk-btn-text")
          .text(nxtBulkImages.i18n.optimizing || "Optimising Images");
        $start.find(".nxt-bulk-btn-icon-play").hide();
        $start.find(".nxt-bulk-btn-icon-spinner").show();
        $start.prop("disabled", true).addClass("nxt-bulk-btn-optimizing");
        $stop.show().addClass("nxt-bulk-btn-stop");
      } else {
        $start.find(".nxt-bulk-btn-icon-play").show();
        $start.find(".nxt-bulk-btn-icon-spinner").hide();
        $start.prop("disabled", false).removeClass("nxt-bulk-btn-optimizing");
        $stop.removeClass("nxt-bulk-btn-stop").hide();
        var allOptimized =
          queueFailed === 0 &&
          ((this.stats &&
            this.stats.optimized > 0 &&
            this.stats.unoptimized === 0) ||
            (this.queue.length > 0 && queueDone === this.queue.length));
        if (allOptimized) {
          $start.hide();
        } else {
          $start.show();
          if (queueFailed > 0) {
            $start
              .find(".nxt-bulk-btn-text")
              .text(nxtBulkImages.i18n.reoptimise || "Reoptimise Images");
          } else {
            $start
              .find(".nxt-bulk-btn-text")
              .text(nxtBulkImages.i18n.startBulk || "Start Bulk Optimisation");
            var monthlyCount = (this.stats && this.stats.monthly_count) || 0;
            var monthlyLimit = (this.stats && this.stats.monthly_limit) || 500;
            var isPro = this.stats && this.stats.is_pro;
            if (!isPro && monthlyCount >= monthlyLimit) {
              $start.prop("disabled", true).css("opacity", "0.5");
            } else {
              $start
                .prop("disabled", this.queue.length === 0)
                .css("opacity", "");
            }
          }
        }
      }
    },

    retryFailed: function () {
      this.queue.forEach(function (q) {
        if (q.status === "failed" || q.status === "processing")
          q.status = "pending";
      });
      this.renderUI();
      this.startBulk();
    },

    showToast: function (msg, type) {
      var $t = $("#nxt-bulk-toast");
      var iconHtml =
        type === "error"
          ? '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'
          : '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24"><path fill="#058645" d="M12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12 6.477 2 12 2m4.498 6.44a.75.75 0 0 0-1.059.062l-4.773 5.37-2.105-2.37a.75.75 0 1 0-1.122.996l2.667 3a.75.75 0 0 0 1.121 0l5.334-6a.75.75 0 0 0-.063-1.059"/></svg>';
      var closeHtml =
        '<button type="button" class="nxt-bulk-toast-close" aria-label="Close"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24"><path fill="#058645" d="M17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12 19 6.41z"/></svg></button>';
      $t.html(
        '<span class="nxt-bulk-toast-icon">' +
          iconHtml +
          '</span><span class="nxt-bulk-toast-msg">' +
          NxtBulkImages.escapeHtml(msg) +
          "</span>" +
          closeHtml,
      )
        .removeClass("success error")
        .addClass(type || "success")
        .addClass("visible");
      clearTimeout($t.data("toast-timer"));
      var timer = setTimeout(function () {
        $t.removeClass("visible");
      }, 4000);
      $t.data("toast-timer", timer);
      $t.off("click", ".nxt-bulk-toast-close").on(
        "click",
        ".nxt-bulk-toast-close",
        function (e) {
          e.preventDefault();
          e.stopPropagation();
          clearTimeout($t.data("toast-timer"));
          $t.removeClass("visible");
        },
      );
    },

    showEmpty: function (msg) {
      $("#nxt-bulk-queue-list").hide();
      $("#nxt-bulk-queue-empty").show().find("p").text(msg);
      $("#nxt-bulk-queue-complete").hide();
    },

    formatSize: function (bytes) {
      if (!bytes) return "0 B";
      if (bytes < 1024) return bytes + " B";
      if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + " KB";
      return (bytes / 1024 / 1024).toFixed(2) + " MB";
    },

    escapeHtml: function (s) {
      if (!s) return "";
      var d = document.createElement("div");
      d.textContent = s;
      return d.innerHTML;
    },

    getStatusIconHtml: function (state) {
      var icons = {
        "icon-ready":
          '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><rect width="48" height="48" fill="#f5f7fe" rx="10"/><g clip-path="url(#arty)"><path stroke="#1717cc" stroke-width="1.501" d="M24 34c5.523 0 10-4.477 10-10s-4.477-10-10-10-10 4.477-10 10 4.477 10 10 10Z"/></g><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.602" d="M24 19.996V24l3.203 1.602"/><defs><clipPath id="arty"><path fill="#fff" d="M13.242 13.242h21.516v21.516H13.242z"/></clipPath></defs></svg>',
        "icon-optimising":
          '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><rect width="48" height="48" fill="#f5f7fe" rx="10"/><path stroke="#1717cc" stroke-width="1.501" d="M24 33.007a9.007 9.007 0 1 0 0-18.013 9.007 9.007 0 0 0 0 18.013Z"/><path stroke="#1717cc" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.676" d="m22 20 5.606 4.006L22 28.012z"/></svg>',
        "icon-errors":
          '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><rect width="48" height="48" fill="#ff1400" fill-opacity=".1" rx="10"/><path stroke="#dc2626" stroke-width="1.501" d="M24 34c5.523 0 10-4.477 10-10s-4.477-10-10-10-10 4.477-10 10 4.477 10 10 10Z"/><path fill="#dc2626" d="M24.012 28.49a.859.859 0 1 1 0 1.717H24a.858.858 0 1 1 0-1.717zM24 17.707c.474 0 .858.384.858.858v7.52a.859.859 0 1 1-1.716 0v-7.52c0-.474.384-.858.858-.858"/></svg>',
        "icon-completed":
          '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" fill="none" viewBox="0 0 48 48"><rect width="48" height="48" fill="#058645" fill-opacity=".1" rx="10"/><path stroke="#00a63e" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M24 34c5.523 0 10-4.477 10-10s-4.477-10-10-10-10 4.477-10 10 4.477 10 10 10"/><path stroke="#00a63e" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 24 2 2 4-4"/></svg>',
      };
      return icons[state] || icons["icon-ready"];
    },

    getItemIconHtml: function (item, status) {
      var iconClass = "icon-" + (status || "pending");
      var url = item && item.thumbnail_url;
      var svg =
        '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 20 20"><path stroke="#666" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.667" d="M12.5 1.666H5a1.667 1.667 0 0 0-1.666 1.667v13.333A1.667 1.667 0 0 0 5 18.333h10a1.667 1.667 0 0 0 1.667-1.667V5.833z"/><path stroke="#666" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.667" d="M11.667 1.666v3.333a1.667 1.667 0 0 0 1.666 1.667h3.333M8.333 11.667a1.667 1.667 0 1 0 0-3.333 1.667 1.667 0 0 0 0 3.333M16.667 14.166l-1.08-1.08a2.01 2.01 0 0 0-2.84 0L7.5 18.333"/></svg>';
      if (url) {
        return (
          '<div class="nxt-bulk-item-icon ' +
          iconClass +
          ' nxt-bulk-item-icon-thumb"><img src="' +
          this.escapeHtml(url) +
          '" alt="" class="nxt-bulk-item-thumb-img"/></div>'
        );
      }
      return (
        '<div class="nxt-bulk-item-icon ' + iconClass + '">' + svg + "</div>"
      );
    },
  };

  $(function () {
    if (typeof nxtBulkImages !== "undefined") {
      NxtBulkImages.init();
    }
  });
})(jQuery);
