// ============================================
// SHARED FOOTER TEXT ANIMATION
// Rotates footer messages across all pages
// ============================================
(function() {
  'use strict';

  function initFooterText() {
    const footerText = document.getElementById('footerText');
    if (!footerText) return;

    const messages = [
      'Making waste management smarter, one bin at a time.',
      'Powered by IoT technology and sustainable innovation.',
      'Join us in creating cleaner, greener communities.',
      'Real-time monitoring for a cleaner tomorrow.'
    ];

    let currentIndex = 0;

    function updateFooterText() {
      footerText.style.transition = 'opacity 0.5s ease-in-out';
      footerText.style.opacity = '0';

      setTimeout(() => {
        footerText.textContent = messages[currentIndex];
        footerText.style.opacity = '1';
        currentIndex = (currentIndex + 1) % messages.length;
      }, 250);
    }

    // Initial text with smooth fade-in
    footerText.style.transition = 'opacity 0.5s ease-in-out';
    footerText.textContent = messages[0];
    footerText.style.opacity = '1';

    // Rotate messages every 5 seconds
    setInterval(updateFooterText, 5000);
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFooterText);
  } else {
    initFooterText();
  }
})();
