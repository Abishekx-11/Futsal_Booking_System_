/**
 * main.js
 * -----------------------------------------------------------------
 * Vanilla-JS lightbox for user/gallery.php. No external library -
 * just toggling a fixed-position overlay div on and off.
 *
 * Clicking any .gallery-thumb button shows the overlay with that
 * photo's full-size image and caption. The overlay closes by
 * clicking its X button, or by clicking anywhere on the dark
 * background outside the enlarged image itself.
 * -----------------------------------------------------------------
 */

document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('lightboxOverlay');
    var overlayImage = document.getElementById('lightboxImage');
    var overlayCaption = document.getElementById('lightboxCaption');
    var closeButton = document.getElementById('lightboxClose');

    // Gallery page might not be the page main.js is loaded on in every
    // future part of the project, so bail out quietly if these elements
    // don't exist here.
    if (!overlay || !overlayImage || !closeButton) {
        return;
    }

    function openLightbox(imageSrc, caption) {
        overlayImage.setAttribute('src', imageSrc);
        overlayCaption.textContent = caption || '';
        overlay.classList.add('lightbox-open');
    }

    function closeLightbox() {
        overlay.classList.remove('lightbox-open');
        overlayImage.setAttribute('src', '');
    }

    document.querySelectorAll('.gallery-thumb').forEach(function (thumb) {
        thumb.addEventListener('click', function () {
            openLightbox(thumb.getAttribute('data-full'), thumb.getAttribute('data-caption'));
        });
    });

    closeButton.addEventListener('click', closeLightbox);

    // Clicking the dark background (but not the image or caption itself)
    // closes the lightbox too.
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) {
            closeLightbox();
        }
    });

    // Escape key closes it as well, for keyboard users.
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && overlay.classList.contains('lightbox-open')) {
            closeLightbox();
        }
    });
});

/**
 * Nav scrollspy for the homepage.
 * -----------------------------------------------------------------
 * Home, Courts, and About all live as sections on index.php rather
 * than separate pages, so the server-rendered "active" class (based
 * on which .php file is being viewed) can't tell them apart - it
 * only ever marks Home. This watches the #home / #courts / #about
 * sections as the user scrolls and moves the "active" class to
 * whichever nav link matches the section currently in view.
 * -----------------------------------------------------------------
 */
document.addEventListener('DOMContentLoaded', function () {
    var sections = ['home', 'courts', 'about']
        .map(function (id) { return document.getElementById(id); })
        .filter(Boolean);
    var navLinks = document.querySelectorAll('.nav-links a[data-nav]');

    // These sections only exist on index.php. Other pages just have the
    // nav links with no matching sections, so bail out quietly there.
    if (sections.length === 0 || navLinks.length === 0) {
        return;
    }

    function setActive(id) {
        navLinks.forEach(function (link) {
            link.classList.toggle('active', link.getAttribute('data-nav') === id);
        });
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                setActive(entry.target.id);
            }
        });
    }, {
        // A section counts as "current" once it crosses a line roughly
        // below the fixed header, rather than needing to fill the whole
        // viewport - this keeps the highlight in sync while scrolling
        // through short sections like Home.
        rootMargin: '-40% 0px -55% 0px',
        threshold: 0
    });

    sections.forEach(function (section) {
        observer.observe(section);
    });
});
