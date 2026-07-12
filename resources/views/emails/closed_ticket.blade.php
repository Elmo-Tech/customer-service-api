<!DOCTYPE html>
<html>
<body>
    <p>{!! $content['body'] !!}</p>
    <br>
    <p>you can contact us here</p>
    <a href="{{ rtrim(config('review_capabilities.frontend_url'), '/') }}/review?ticketId={{ $content['ticketId'] }}&token={{ $content['token'] }}">
        Review Ticket
    </a>
</body>
</html>
