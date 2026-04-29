(function () {
    document.addEventListener('DOMContentLoaded', function () {
        var tabs = document.querySelectorAll('.epc-settings-tabs[data-epc-prefetch="1"]');
        tabs.forEach(function (nav) {
            var anchors = nav.querySelectorAll('.epc-tab-link');
            var panelsWrap = nav.closest('.epc-settings-tabs-shell');
            if (!panelsWrap) {
                panelsWrap = document.querySelector('#epc-settings-form');
            }
            if (!panelsWrap) {
                return;
            }
            anchors.forEach(function (a) {
                a.addEventListener('click', function (ev) {
                    ev.preventDefault();
                    var slug = this.getAttribute('data-tab');
                    if (!slug) {
                        var u = '';
                        try {
                            u = new URL(this.href || '', window.location.origin);
                            slug = u.searchParams.get('tab') || 'general';
                        } catch (e) {
                            slug = 'general';
                        }
                    }
                    anchors.forEach(function (el) {
                        var on = el.getAttribute('data-tab') === slug;
                        el.classList.toggle('active', on);
                    });
                    panelsWrap.querySelectorAll('.epc-tab-panel').forEach(function (panel) {
                        var tab = panel.getAttribute('data-tab');
                        panel.classList.toggle('active', tab === slug);
                        panel.hidden = tab !== slug;
                        panel.style.display = tab === slug ? '' : 'none';
                    });
                    history.replaceState(null, '', addQueryTab(window.location.href, slug));

                    window.dispatchEvent(
                        new CustomEvent('epc_settings_tab', { detail: { tab: slug } })
                    );

                    window.requestAnimationFrame(function () {
                        try {
                            if (typeof jQuery !== 'undefined' && jQuery.fn.wpColorPicker) {
                                var $visible = panelsWrap.querySelector('.epc-tab-panel.active');
                                if ($visible) {
                                    jQuery($visible).find('.epc-color-picker').each(function () {
                                        if (!jQuery(this).data('wpWpColorPicker')) {
                                            jQuery(this).wpColorPicker({});
                                        }
                                    });
                                }
                            }
                        } catch (e) {
                            /* no-op */
                        }
                    });
                });
            });

            panelsWrap.querySelectorAll('.epc-tab-panel:not(.active)').forEach(function (panel) {
                panel.hidden = true;
                panel.style.display = 'none';
            });
        });
    });

    function addQueryTab(href, tab) {
        try {
            var u = new URL(href, window.location.origin);
            u.searchParams.set('tab', tab);
            return u.pathname + u.search + u.hash;
        } catch (e) {
            return href;
        }
    }
})();
