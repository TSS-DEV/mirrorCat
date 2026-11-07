/* MARC UI helpers: details modal, chunked WorldCat search, table selection. */
(function () {
  'use strict';

  var csrfMeta = document.querySelector('meta[name="csrf-token"]');
  var csrf = csrfMeta ? csrfMeta.getAttribute('content') : '';

  function goToLogin() {
    var page = window.location.pathname.split('/').pop() + window.location.search;
    window.location.href = 'login.php?next=' + encodeURIComponent(page);
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  }

  function flatten(value, prefix, out) {
    if (value === null || value === undefined) {
      out.push([prefix || '(value)', '']);
      return;
    }
    if (typeof value !== 'object') {
      out.push([prefix || '(value)', String(value)]);
      return;
    }
    if (Array.isArray(value)) {
      var values = value.map(function (item) {
        return typeof item === 'object' ? JSON.stringify(item) : String(item);
      });
      out.push([prefix || '(list)', values.join(', ')]);
      return;
    }
    Object.keys(value).forEach(function (key) {
      var name = prefix ? prefix + ' / ' + key : key;
      flatten(value[key], name, out);
    });
  }

  function kvTable(pairs) {
    var table = el('table', 'table table-sm table-striped mb-0');
    var tbody = el('tbody');
    if (!pairs.length) {
      var tr = el('tr');
      var td = el('td', 'text-muted', 'No values.');
      td.colSpan = 2;
      tr.appendChild(td);
      tbody.appendChild(tr);
    }
    pairs.forEach(function (pair) {
      var tr = el('tr');
      var th = el('th', 'text-nowrap align-top', pair[0]);
      th.style.width = '32%';
      var td = el('td', 'text-break', pair[1]);
      tr.appendChild(th);
      tr.appendChild(td);
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    return table;
  }

  function section(title, pairs, open) {
    var wrapper = el('details', 'mb-3');
    if (open) {
      wrapper.open = true;
    }
    var summary = el('summary', 'fw-semibold', title + ' (' + pairs.length + ')');
    wrapper.appendChild(summary);
    wrapper.appendChild(kvTable(pairs));
    return wrapper;
  }

  function ensureModal() {
    var existing = document.getElementById('rowDetailsModal');
    if (existing) {
      return existing;
    }

    var modal = el('div', 'modal fade');
    modal.id = 'rowDetailsModal';
    modal.tabIndex = -1;
    modal.setAttribute('aria-hidden', 'true');

    var dialog = el('div', 'modal-dialog modal-xl modal-dialog-scrollable');
    var content = el('div', 'modal-content');
    var header = el('div', 'modal-header');
    var title = el('h5', 'modal-title', 'Row details');
    title.id = 'rowDetailsTitle';
    var close = el('button', 'btn-close');
    close.type = 'button';
    close.setAttribute('data-bs-dismiss', 'modal');
    close.setAttribute('aria-label', 'Close');
    header.appendChild(title);
    header.appendChild(close);

    var body = el('div', 'modal-body');
    body.id = 'rowDetailsBody';

    var footer = el('div', 'modal-footer');
    var dismiss = el('button', 'btn btn-secondary', 'Close');
    dismiss.type = 'button';
    dismiss.setAttribute('data-bs-dismiss', 'modal');
    footer.appendChild(dismiss);

    content.appendChild(header);
    content.appendChild(body);
    content.appendChild(footer);
    dialog.appendChild(content);
    modal.appendChild(dialog);
    document.body.appendChild(modal);

    return modal;
  }

  function showDetails(rowId, button) {
    var modalEl = ensureModal();
    var body = document.getElementById('rowDetailsBody');
    var title = document.getElementById('rowDetailsTitle');
    body.textContent = '';
    body.appendChild(el('div', 'text-muted', 'Loading…'));

    if (button) {
      button.disabled = true;
    }

    fetch('api.php?action=details&id=' + encodeURIComponent(rowId), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (response) {
        if (response.status === 401) {
          goToLogin();
          throw new Error('Authentication required');
        }
        return response.json();
      })
      .then(function (data) {
        body.textContent = '';
        if (!data.success) {
          body.appendChild(el('div', 'alert alert-danger mb-0', data.error || 'Could not load the row details.'));
          return;
        }

        var row = data.row;
        title.textContent = 'Row ' + row.row_num + (row.oclc_number ? ' — OCLC ' + row.oclc_number : '');
        if (row.search_query) {
          body.appendChild(el('p', 'mb-2', 'Search query: ' + row.search_query));
        }
        if (row.error) {
          body.appendChild(el('div', 'alert alert-warning', row.error));
        }

        var meta = [];
        if (row.status) { meta.push(['Status', row.status]); }
        if (row.processed) { meta.push(['Processed', 'Yes']); }
        if (row.updated) { meta.push(['Updated', row.updated]); }
        body.appendChild(section('Record information', meta, true));

        var dataPairs = [];
        flatten(row.data || {}, '', dataPairs);
        body.appendChild(section('Uploaded row values', dataPairs, true));

        var fieldPairs = [];
        flatten(row.fields || {}, '', fieldPairs);
        body.appendChild(section('Retrieved WorldCat fields', fieldPairs, true));

        var rawPairs = [];
        flatten(row.raw || {}, '', rawPairs);
        body.appendChild(section('Raw API record', rawPairs, false));

        var instance = bootstrap.Modal.getOrCreateInstance(modalEl);
        instance.show();
      })
      .catch(function (error) {
        if (error && error.message === 'Authentication required') {
          return;
        }
        body.textContent = '';
        body.appendChild(el('div', 'alert alert-danger mb-0', 'Could not load the row details.'));
      })
      .finally(function () {
        if (button) {
          button.disabled = false;
        }
      });
  }

  function initDetails() {
    document.addEventListener('click', function (event) {
      var button = event.target.closest('.js-row-details');
      if (!button) {
        return;
      }
      event.preventDefault();
      showDetails(button.getAttribute('data-row-id'), button);
    });
  }

  function initSelection() {
    document.querySelectorAll('[data-select-all]').forEach(function (master) {
      var target = master.getAttribute('data-select-all');
      var boxes = function () {
        return Array.prototype.slice.call(document.querySelectorAll(target));
      };
      master.addEventListener('change', function () {
        boxes().forEach(function (box) { box.checked = master.checked; });
      });
      boxes().forEach(function (box) {
        box.addEventListener('change', function () {
          var all = boxes();
          master.checked = all.length > 0 && all.every(function (b) { return b.checked; });
          master.indeterminate = !master.checked && all.some(function (b) { return b.checked; });
        });
      });
    });
  }

  function initConfirm() {
    document.addEventListener('submit', function (event) {
      var form = event.target;
      var message = form.getAttribute('data-confirm');
      if (event.submitter) {
        var buttonMessage = event.submitter.getAttribute('data-confirm');
        if (buttonMessage) {
          message = buttonMessage;
        }
      }
      if (message && !window.confirm(message)) {
        event.preventDefault();
      }
    });
    document.addEventListener('click', function (event) {
      var link = event.target.closest('a[data-confirm]');
      if (link && !window.confirm(link.getAttribute('data-confirm'))) {
        event.preventDefault();
      }
    });
  }

  function setSearchState(running) {
    document.querySelectorAll('[data-search-scope]').forEach(function (button) {
      button.disabled = running;
    });
    var stop = document.getElementById('search-stop');
    if (stop) {
      stop.disabled = !running;
    }
    var spinner = document.getElementById('search-spinner');
    if (spinner) {
      spinner.classList.toggle('d-none', !running);
    }
  }

  function searchAlert(message, type) {
    var alert = document.getElementById('search-alert');
    if (!alert) {
      return;
    }
    alert.textContent = '';
    if (!message) {
      alert.classList.add('d-none');
      return;
    }
    alert.className = 'alert alert-' + (type || 'info');
    alert.textContent = message;
  }

  function initSearch() {
    var trigger = document.querySelector('[data-search-scope]');
    if (!trigger) {
      return;
    }

    var batchId = trigger.getAttribute('data-batch');
    var chunk = parseInt(trigger.getAttribute('data-chunk') || '10', 10) || 10;
    var progress = document.getElementById('search-progress');
    var status = document.getElementById('search-status');
    var stopRequested = false;

    document.querySelectorAll('[data-search-scope]').forEach(function (button) {
      button.addEventListener('click', function () {
        runSearch(button.getAttribute('data-search-scope'));
      });
    });

    var stop = document.getElementById('search-stop');
    if (stop) {
      stop.addEventListener('click', function () {
        stopRequested = true;
        searchAlert('Stopping after the current batch…', 'warning');
      });
    }

    function updateProgress(counts) {
      if (!progress) {
        return;
      }
      var total = counts.total || 0;
      var done = (counts.success || 0) + (counts.errors || 0);
      var remaining = (counts.pending || 0) + (counts.errors || 0) + (counts.success || 0);
      var percent = total > 0 ? Math.round((done / total) * 100) : 0;
      progress.style.width = percent + '%';
      progress.setAttribute('aria-valuenow', String(percent));
      progress.textContent = percent + '%';
      if (status) {
        status.textContent = done + ' of ' + total + ' rows resolved · '
          + (counts.success || 0) + ' found · '
          + (counts.errors || 0) + ' errors · '
          + (counts.pending || 0) + ' not searched';
      }
      return remaining;
    }

    function runSearch(scope) {
      stopRequested = false;
      setSearchState(true);
      searchAlert('Starting the WorldCat search…', 'info');

      var loop = function () {
        if (stopRequested) {
          setSearchState(false);
          searchAlert('Search stopped. The results are shown below.', 'warning');
          setTimeout(function () { window.location.reload(); }, 1200);
          return;
        }

        fetch('api.php?action=search', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrf,
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: JSON.stringify({ batch_id: batchId, scope: scope, limit: chunk })
        })
          .then(function (response) {
            if (response.status === 401) {
              goToLogin();
              throw new Error('Authentication required');
            }
            return response.json().then(function (data) {
              return { ok: response.ok, data: data };
            });
          })
          .then(function (result) {
            var data = result.data || {};
            if (!result.ok || !data.success) {
              setSearchState(false);
              searchAlert(data.error || 'The search request failed.', 'danger');
              return;
            }

            updateProgress(data.counts || {});
            var searched = (data.rows || []).length;

            if (data.message) {
              searchAlert(data.message, data.halt ? 'danger' : 'info');
            }

            if (data.halt) {
              setSearchState(false);
              return;
            }

            if (searched === 0 || (data.pending_remaining || 0) <= 0) {
              setSearchState(false);
              searchAlert('Search complete.', 'success');
              setTimeout(function () { window.location.reload(); }, 900);
              return;
            }

            searchAlert('Searching… ' + ((data.counts && data.counts.success) || 0) + ' matches so far.', 'info');
            setTimeout(loop, 150);
          })
          .catch(function (error) {
            if (error && error.message === 'Authentication required') {
              return;
            }
            setSearchState(false);
            searchAlert('The browser could not reach the server. Check that Apache is running and try again.', 'danger');
          });
      };

      loop();
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    initDetails();
    initSelection();
    initConfirm();
    initSearch();
  });
})();
