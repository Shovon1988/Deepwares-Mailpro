jQuery(document).ready(function($) {
    // Confirm before sending a campaign
    $('a.button').on('click', function(e) {
        if ($(this).text().trim() === 'Send Now') {
            if (!confirm('Are you sure you want to send this campaign now?')) {
                e.preventDefault();
            }
        }
    });

    // Example: highlight rows on hover
    $('.widefat tr').hover(
        function() { $(this).css('background-color', '#f9f9f9'); },
        function() { $(this).css('background-color', ''); }
    );
});

