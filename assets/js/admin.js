/* GBP Sync Admin JS */
(function ($) {
    'use strict';

    // Escape a value for safe interpolation into concatenated HTML.
    function esc( value ) {
        return $( '<div>' ).text( value == null ? '' : value ).html();
    }

    // Tab navigation.
    $('.gbp-tab-nav a').on('click', function (e) {
        e.preventDefault();
        var target = $(this).attr('href');
        $('.gbp-tab-nav a').removeClass('active');
        $('.gbp-tab').removeClass('active');
        $(this).addClass('active');
        $(target).addClass('active');
    });

    // Sync all locations.
    $('#gbp-sync-all-btn').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#gbp-sync-spinner');
        var $result  = $('#gbp-sync-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.post(gbpSync.ajaxUrl, {
            action: 'gbp_sync_all',
            nonce:  gbpSync.nonce,
        })
        .done(function (res) {
            if (res.success) {
                var d = res.data;
                var errHtml = '';
                if (d.errors && d.errors.length) {
                    errHtml = '<ul style="margin:4px 0 0 16px">' +
                        d.errors.map(function(e){ return '<li>' + e + '</li>'; }).join('') +
                        '</ul>';
                }
                $result.html(
                    '<div class="notice notice-' + (d.errors.length ? 'warning' : 'success') + ' inline"><p>' +
                    'Synced: <strong>' + d.synced + '</strong> | Created: <strong>' + d.created + '</strong>' +
                    (d.errors.length ? ' | Errors: <strong>' + d.errors.length + '</strong>' : '') +
                    '</p>' + errHtml + '</div>'
                );
                // Reload page after short delay to refresh status badges.
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                $result.html('<div class="notice notice-error inline"><p>Sync failed: ' + (res.data || 'Unknown error') + '</p></div>');
            }
        })
        .fail(function () {
            $result.html('<div class="notice notice-error inline"><p>Request failed. Check server logs.</p></div>');
        })
        .always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // Render the per-location breakdown returned by the hours sync.
    function renderHoursResult(d) {
        var counts = [
            'Checked: <strong>' + d.checked + '</strong>',
            'Updated from Google: <strong>' + d.written + '</strong>',
            'First populated: <strong>' + d.populated + '</strong>',
            'Manual entries kept: <strong>' + (d.adopted + d.unchanged) + '</strong>'
        ];
        if (d.skipped) {
            counts.push('No hours: <strong>' + d.skipped + '</strong>');
        }

        var detail = '';
        var problems = (d.locations || []).filter(function (l) {
            return l.hours === 'error' || l.hours === 'skip';
        });

        if (problems.length) {
            detail = '<p style="margin:6px 0 0">Locations with no hours:</p><ul style="margin:4px 0 0 16px">' +
                problems.map(function (l) {
                    return '<li><strong>' + esc(l.title) + '</strong> — ' + esc(l.error || 'no usable hours returned') + '</li>';
                }).join('') + '</ul>';
        }

        return '<div class="notice notice-' + (problems.length ? 'warning' : 'success') + ' inline"><p>' +
            counts.join(' | ') + '</p>' + detail + '</div>';
    }

    // Sync hours for every location — Places API only.
    $('#gbp-sync-hours-btn').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#gbp-sync-spinner');
        var $result  = $('#gbp-sync-result');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('<p>Reading hours from the Places API…</p>');

        $.post(gbpSync.ajaxUrl, {
            action: 'gbp_sync_hours_all',
            nonce:  gbpSync.nonce,
        })
        .done(function (res) {
            if (res.success) {
                $result.html(renderHoursResult(res.data));
                setTimeout(function () { location.reload(); }, 2500);
            } else {
                $result.html('<div class="notice notice-error inline"><p>Hours sync failed: ' + (res.data || 'Unknown error') + '</p></div>');
            }
        })
        .fail(function () {
            $result.html('<div class="notice notice-error inline"><p>Request failed. Check server logs.</p></div>');
        })
        .always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // Sync single location.
    $(document).on('click', '.gbp-sync-one-btn', function () {
        var $btn         = $(this);
        var locationName = $btn.data('location');
        var postId       = $btn.data('post-id');

        $btn.prop('disabled', true).text('Syncing…');

        $.post(gbpSync.ajaxUrl, {
            action:        'gbp_sync_one',
            nonce:         gbpSync.nonce,
            location_name: locationName,
            post_id:       postId,
        })
        .done(function (res) {
            if (res.success) {
                $btn.text('Done ✓').addClass('gbp-done');
                if (res.data && res.data.debug_hours) {
                    console.log('GBP SerpAPI hours debug:', res.data.debug_hours);
                    console.log('Response keys:', res.data.debug_hours.keys);
                }
            } else {
                $btn.text('Error').addClass('gbp-error');
            }
        })
        .fail(function () {
            $btn.text('Failed').addClass('gbp-error');
        })
        .always(function () {
            setTimeout(function () {
                $btn.prop('disabled', false).text('Sync Now').removeClass('gbp-done gbp-error');
            }, 3000);
        });
    });

    // Search for missing locations.
    $('#gbp-search-btn').on('click', function () {
        var $btn     = $(this);
        var $spinner = $('#gbp-search-spinner');
        var $results = $('#gbp-import-results');

        $btn.prop('disabled', true);
        $spinner.addClass('is-active');
        $results.html('');

        $.post(gbpSync.ajaxUrl, {
            action: 'gbp_search_locations',
            nonce:  gbpSync.nonce,
        })
        .done(function (res) {
            if (!res.success) {
                $results.html('<div class="notice notice-error inline"><p>' + (res.data || 'Search failed.') + '</p></div>');
                return;
            }

            var d   = res.data;
            var html = '';

            if (d.new && d.new.length) {
                html += '<h3>' + d.new.length + ' location(s) not yet in WordPress</h3>';
                html += '<table class="wp-list-table widefat fixed striped gbp-locations-table"><thead><tr>' +
                        '<th>Name</th><th>Address</th><th>Place ID</th><th>Action</th>' +
                        '</tr></thead><tbody>';
                d.new.forEach(function (loc) {
                    html += '<tr id="import-row-' + loc.place_id + '">' +
                            '<td>' + loc.title + '</td>' +
                            '<td>' + loc.address + '</td>' +
                            '<td><code>' + loc.place_id.substring(0, 22) + '…</code></td>' +
                            '<td><button class="button button-primary gbp-import-one-btn"' +
                            ' data-place-id="' + loc.place_id + '"' +
                            ' data-title="' + loc.title.replace(/"/g, '&quot;') + '">Import &amp; Sync</button></td>' +
                            '</tr>';
                });
                html += '</tbody></table>';
            } else {
                html += '<div class="notice notice-success inline"><p>All search results already exist in WordPress.</p></div>';
            }

            if (d.already && d.already.length) {
                html += '<p class="description" style="margin-top:12px">' + d.already.length + ' location(s) already imported. ' + d.total + ' total found by SerpAPI.</p>';
            }

            $results.html(html);
        })
        .fail(function () {
            $results.html('<div class="notice notice-error inline"><p>Request failed. Check server logs.</p></div>');
        })
        .always(function () {
            $btn.prop('disabled', false);
            $spinner.removeClass('is-active');
        });
    });

    // Import a single location.
    $(document).on('click', '.gbp-import-one-btn', function () {
        var $btn     = $(this);
        var placeId  = $btn.data('place-id');
        var title    = $btn.data('title');

        $btn.prop('disabled', true).text('Importing…');

        $.post(gbpSync.ajaxUrl, {
            action:   'gbp_import_location',
            nonce:    gbpSync.nonce,
            place_id: placeId,
            title:    title,
        })
        .done(function (res) {
            var $cell = $('#import-row-' + placeId).find('td:last');
            if (res.success) {
                var editUrl = res.data.edit_url;
                $cell.html('<span class="gbp-badge gbp-badge-success">Imported</span> <a href="' + editUrl + '" target="_blank">Edit Post</a>');
            } else {
                $btn.prop('disabled', false).text('Import & Sync');
                $cell.append('<span style="color:#d63638;margin-left:8px">Error: ' + (res.data || 'Unknown') + '</span>');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('Import & Sync');
        });
    });

}(jQuery));
