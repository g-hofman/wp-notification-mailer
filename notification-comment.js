jQuery(document).ready(function($) {
    $('a[href*="send_notification"]').on('click', function(e) {
        e.preventDefault();
        
        // Show a prompt for the admin to add comments
        var comment = prompt("Enter your comment for the notification:");
        
        // If the admin cancels, abort the notification
        if (comment === null) {
            return false;
        }

        // If the comment is empty, alert the admin and don't send the email
        if (comment.trim() === '') {
            alert('You must enter a comment before sending the notification.');
            return false;
        }

        // Proceed with the notification
        window.location.href = $(this).attr('href') + '&comment=' + encodeURIComponent(comment);
    });
});
