	(function () {
	    'use strict';

	    function getAnimMode(gallery) {
	        if (!gallery) return 'slide';
	        var m = gallery.getAttribute('data-anim') || 'slide';
	        m = String(m).toLowerCase();
	        return (m === 'none') ? 'none' : 'slide';
	    }

	    function setHiddenHeightInstant(hidden, expanded) {
	        if (!hidden) return;
	        hidden.style.height = expanded ? 'auto' : '0px';
	    }

	    function animateHiddenHeight(hidden, expanded) {
	        if (!hidden) return;

	        var startHeight = hidden.getBoundingClientRect().height;
	        // If we're currently auto, read the full scrollHeight.
	        if (hidden.style.height === 'auto' || hidden.style.height === '') {
	            hidden.style.height = hidden.scrollHeight + 'px';
	            startHeight = hidden.scrollHeight;
	        }

	        if (expanded) {
	            // Expand from current height (likely 0) to full content height.
	            hidden.style.height = startHeight + 'px';
	            // Force reflow.
	            void hidden.offsetHeight;
	            var target = hidden.scrollHeight;
	            hidden.style.height = target + 'px';
	            hidden.addEventListener('transitionend', function (e) {
	                if (e && e.propertyName && e.propertyName !== 'height') return;
	                // Allow natural height after animation (images may load later).
	                hidden.style.height = 'auto';
	            }, { once: true });
	            return;
	        }

	        // Collapse from current height to 0.
	        hidden.style.height = startHeight + 'px';
	        void hidden.offsetHeight;
	        hidden.style.height = '0px';
	    }

	    function enhance(root) {
	        var scope = root && root.querySelectorAll ? root : document;
	        var galleries = scope.querySelectorAll('.estandar-gallery[data-enhanced="0"]');
	        if (!galleries.length) {
            return;
        }

	        for (var i = 0; i < galleries.length; i++) {
	            var gallery = galleries[i];
	            var button = gallery.querySelector('.estandar-gallery-toggle');
	            var hidden = gallery.querySelector('.estandar-gallery-hidden');
	            if (!button || !hidden) {
	                gallery.setAttribute('data-enhanced', '1');
	                continue;
	            }

	            gallery.setAttribute('data-enhanced', '1');
	            gallery.setAttribute('data-expanded', 'false');
	            button.setAttribute('aria-expanded', 'false');
	            var moreText = (button.dataset && button.dataset.more) ? String(button.dataset.more) : 'Ver más';
	            button.textContent = moreText || 'Ver más';

	            if (getAnimMode(gallery) === 'none') {
	                setHiddenHeightInstant(hidden, false);
	            } else {
	                hidden.style.height = '0px';
	            }
	        }
	    }

	    document.addEventListener('click', function (event) {
	        var target = event.target;
	        if (!target || !target.closest) {
	            return;
	        }

	        var button = target.closest('.estandar-gallery-toggle');
	        if (!button) {
	            return;
	        }

	        if (event && event.preventDefault) {
	            event.preventDefault();
	        }

	        var gallery = button.closest('.estandar-gallery');
	        if (!gallery) {
	            return;
	        }

	        var expanded = gallery.getAttribute('data-expanded') === 'true';
	        var next = !expanded;

	        gallery.setAttribute('data-expanded', next ? 'true' : 'false');
	        button.setAttribute('aria-expanded', next ? 'true' : 'false');
	        var more = (button.dataset && button.dataset.more) ? String(button.dataset.more) : 'Ver más';
	        var less = (button.dataset && button.dataset.less) ? String(button.dataset.less) : 'Ver menos';
	        button.textContent = next ? (less || 'Ver menos') : (more || 'Ver más');

	        var hidden = gallery.querySelector('.estandar-gallery-hidden');
	        if (!hidden) {
	            return;
	        }
	        if (getAnimMode(gallery) === 'none') {
	            setHiddenHeightInstant(hidden, next);
	            return;
	        }
	        animateHiddenHeight(hidden, next);
	    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            enhance(document);
        });
    } else {
        enhance(document);
    }

    if (window.MutationObserver) {
        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var mutation = mutations[i];
                for (var j = 0; j < mutation.addedNodes.length; j++) {
                    var node = mutation.addedNodes[j];
                    if (!node || node.nodeType !== 1) {
                        continue;
                    }
                    if (node.matches && node.matches('.estandar-gallery')) {
                        enhance(node.parentNode || document);
                        continue;
                    }
                    if (node.querySelector && node.querySelector('.estandar-gallery')) {
                        enhance(node);
                    }
                }
            }
        });

        observer.observe(document.documentElement, { childList: true, subtree: true });
    }
})();
