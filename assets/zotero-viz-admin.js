(function ($) {
    'use strict';

    var running = false;

    function setProgress(done, total) {
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;
        $('#zotero-viz-progress-bar').css('width', pct + '%');
        $('#zotero-viz-progressbar').attr('aria-valuenow', pct);
        $('#zotero-viz-refresh-count').text(done + ' of ' + total + ' libraries complete');
    }

    function setLibraryState(index, state, message) {
        var $item = $('#zotero-viz-refresh-libraries li[data-index="' + index + '"]');
        $item.removeClass('waiting running success error').addClass(state);
        $item.find('.zotero-viz-refresh-state').text(message);
    }

    function updateCacheRow(result) {
        if (!result || !result.success) {
            return;
        }
        var $row = $('#zotero-viz-cache-rows tr').filter(function () {
            return $(this).attr('data-display-name') === result.display_name;
        });
        if (!$row.length) {
            return;
        }
        $row.html(
            '<td><strong>' + $('<div>').text(result.display_name).html() + '</strong></td>' +
            '<td>' + $('<div>').text(result.library_name || '').html() + '</td>' +
            '<td>' + $('<div>').text(result.type || '').html() + '</td>' +
            '<td>' + $('<div>').text(String(result.item_count)).html() + '</td>' +
            '<td>' + $('<div>').text(String(result.countries)).html() + '</td>' +
            '<td>' + $('<div>').text(result.year_range || 'N/A').html() + '</td>' +
            '<td>' + $('<div>').text(result.updated || 'just now').html() + '</td>'
        );
    }

    function finishRefresh(ok, summary) {
        running = false;
        $('.zotero-viz-refresh-cache').prop('disabled', false);
        $('#zotero-viz-refresh-progress .spinner').removeClass('is-active');
        $('#zotero-viz-refresh-label').text(summary);
        if (ok) {
            $('#zotero-viz-refresh-progress').addClass('is-complete');
        }
    }

    function refreshOne(libraries, index) {
        var lib = libraries[index];
        var total = libraries.length;
        $('#zotero-viz-refresh-label').text('Refreshing ' + lib.display_name + '…');
        setLibraryState(index, 'running', 'Fetching from Zotero…');

        $.ajax({
            url: zoteroVizAdmin.ajaxUrl,
            method: 'POST',
            timeout: 360000,
            data: {
                action: 'zotero_viz_refresh_one',
                nonce: zoteroVizAdmin.nonce,
                index: lib.index
            }
        }).done(function (response) {
            if (!response || !response.success || !response.data || !response.data.result) {
                var err = (response && response.data) ? response.data : 'Refresh failed';
                if (typeof err !== 'string') {
                    err = 'Refresh failed';
                }
                setLibraryState(index, 'error', err);
                setProgress(index + 1, total);
                if (index + 1 < total) {
                    refreshOne(libraries, index + 1);
                } else {
                    finishRefresh(false, 'Cache refresh finished with errors.');
                }
                return;
            }

            var result = response.data.result;
            if (result.success) {
                setLibraryState(index, 'success', result.message);
                updateCacheRow(result);
            } else {
                setLibraryState(index, 'error', result.message || 'Failed');
            }
            setProgress(index + 1, total);

            if (index + 1 < total) {
                refreshOne(libraries, index + 1);
            } else {
                var failed = $('#zotero-viz-refresh-libraries li.error').length;
                if (failed) {
                    finishRefresh(false, 'Cache refresh finished with ' + failed + ' error' + (failed === 1 ? '' : 's') + '.');
                } else {
                    finishRefresh(true, 'Cache refresh complete.');
                }
            }
        }).fail(function (xhr) {
            setLibraryState(index, 'error', 'Request failed' + (xhr.status ? ' (HTTP ' + xhr.status + ')' : ''));
            setProgress(index + 1, total);
            if (index + 1 < total) {
                refreshOne(libraries, index + 1);
            } else {
                finishRefresh(false, 'Cache refresh finished with errors.');
            }
        });
    }

    function startRefresh() {
        if (running || typeof zoteroVizAdmin === 'undefined') {
            return;
        }
        running = true;
        $('.zotero-viz-refresh-cache').prop('disabled', true);
        $('#zotero-viz-refresh-progress')
            .removeAttr('hidden')
            .removeClass('is-complete')
            .get(0).scrollIntoView({ behavior: 'smooth', block: 'start' });
        $('#zotero-viz-refresh-progress .spinner').addClass('is-active');
        $('#zotero-viz-refresh-label').text('Preparing cache refresh…');
        $('#zotero-viz-refresh-libraries').empty();
        setProgress(0, 1);

        $.post(zoteroVizAdmin.ajaxUrl, {
            action: 'zotero_viz_refresh_list',
            nonce: zoteroVizAdmin.nonce
        }).done(function (response) {
            if (!response || !response.success || !response.data || !response.data.libraries) {
                var err = (response && response.data) ? response.data : 'Could not start cache refresh.';
                if (typeof err !== 'string') {
                    err = 'Could not start cache refresh.';
                }
                finishRefresh(false, err);
                return;
            }

            var libraries = response.data.libraries;
            var $list = $('#zotero-viz-refresh-libraries');
            libraries.forEach(function (lib) {
                $list.append(
                    $('<li/>', { 'data-index': lib.index, 'class': 'waiting' })
                        .append($('<strong/>').text(lib.display_name))
                        .append(document.createTextNode(' '))
                        .append($('<span/>', { 'class': 'zotero-viz-refresh-state' }).text('Waiting'))
                );
            });
            setProgress(0, libraries.length);
            refreshOne(libraries, 0);
        }).fail(function () {
            finishRefresh(false, 'Could not start cache refresh.');
        });
    }

    $(document).on('click', '.zotero-viz-refresh-cache', function (event) {
        event.preventDefault();
        startRefresh();
    });
})(jQuery);
