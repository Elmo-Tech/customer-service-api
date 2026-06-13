<!DOCTYPE html>
<html>
<head>
    <title></title>

    <!--
	You can put your custom CSS if you wish
    -->
</head>
<body>
    <p>{!! $content['body'] !!}</p>
    <br>
    <p>you can contact us here</p>
    <a href="http://tickets.testingelmo.com/review?ticketId={{ $content['ticketId'] }}&token={{ $content['token'] }}">
        Review Ticket
    </a>
</body>
</html>
