otrs
====
# OTRS/Znuny REST Library

## Requirements

- PHP 8.2 or later
- Symfony HttpClient 6.4 or 7.4 (`^6.4 || ^7.4`)

## Usage

```php
use SUA\Otrs\OtrsRestApi;

$api = new OtrsRestApi('https://znuny.example.com/otrs/nph-genericinterface.pl/Webservice/REST/', 'username', 'password');
$ticket = $api->getTicket(123);
```

An optional fourth constructor argument accepts a Symfony `HttpClientInterface`.
Configure injected clients with the appropriate base URI; the library sends
relative request paths. Without an injected client, the library creates its
own client using the supplied URL.
