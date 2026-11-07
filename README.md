# maivio-imap

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maivio/maivio-imap.svg?style=flat-square)](https://packagist.org/packages/maivio/maivio-imap)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2%20%7C%7C%20%5E8.3%20%7C%7C%20%5E8.4%20%7C%7C%20%5E8.5-blue.svg?style=flat-square)](https://php.net)

> **Modern, stream-based, zero-dependency IMAP client and RFC 822 MIME parser for PHP and Laravel.**  
> Completely independent of `ext-imap` (which is deprecated in PHP 8.4 and removed in PHP 8.5+).

---

## Highlights

- **Zero C-Extension Dependencies:** Communicates directly over raw sockets (`stream_socket_client`) with SSL/TLS context and SNI support. No `ext-imap` required.
- **Stream-Based & Low RAM Usage:** Parses multi-megabyte emails and binary attachments chunk-by-chunk using `php://temp` streams without hitting `memory_limit`.
- **Database & Model Agnostic:** No forced Eloquent models or database tables. Use it in background workers, webhooks, console commands, or arbitrary persistence layers.
- **Modern Authentication:** Supports both standard `LOGIN` and **SASL XOAUTH2** (Google Workspace, Gmail, Microsoft 365, Outlook).
- **International Folder Support:** Automatic Modified UTF-7 encoding and decoding (RFC 3501 Section 5.1.3) for special characters and umlauts (`Entwürfe`, `Papierkorb`, etc.).
- **Smart Folder Detection:** Detects trash, sent, drafts, and archive folders using RFC 6154 SPECIAL-USE flags with localized fallbacks.
- **RFC 6851 `UID MOVE` Support:** Moves emails efficiently on modern servers with an automatic fallback to `UID COPY` + `UID STORE \Deleted` + `EXPUNGE`.
- **First-Class Laravel Integration:** Includes Service Provider, Facade (`Imap::...`), and config publishing.

---

## Requirements

- **PHP:** `^8.2`, `^8.3`, `^8.4` or `^8.5`
- **Extensions:** `openssl`, `mbstring`, `iconv` (standard in almost all PHP installations)

---

## Installation

Install the package via Composer:

```bash
composer require maivio/maivio-imap
```

### Laravel Setup

If you are using Laravel, the service provider and `Imap` facade are registered automatically via package discovery.

Publish the optional configuration file:

```bash
php artisan vendor:publish --tag=maivio-imap-config
```

---

## Quickstart

### 1. Pure PHP (Standalone)

```php
use Maivio\Imap\Client\ImapClient;

// Connect to IMAP server
$client = new ImapClient();
$client->connect('imap.example.com', 993, 'ssl');

// Standard Authentication
$client->login('user@example.com', 'secret-password');

// List Folders (UTF-7 names are automatically decoded to UTF-8)
$folders = $client->listFolders();
foreach ($folders as $folder) {
    echo $folder->getName() . ($folder->isTrash() ? ' [TRASH]' : '') . PHP_EOL;
}

// Select mailbox folder
$client->selectMailbox('INBOX');

// Search new/unseen email UIDs
$uids = $client->searchUnseenOrSinceUid();

foreach ($uids as $uid) {
    // Fetch and parse email into a clean DTO
    $message = $client->getMessage($uid);

    echo "Subject: " . $message->getSubject() . PHP_EOL;
    echo "From:    " . $message->getSenderEmail() . PHP_EOL;
    echo "Date:    " . $message->getDate()?->format('Y-m-d H:i:s') . PHP_EOL;
    echo "Body:    " . $message->getBodyText() . PHP_EOL;

    // Stream attachments without loading full bytes into PHP memory
    foreach ($message->getAttachments() as $attachment) {
        echo "Attachment: " . $attachment->getFilename() . " (" . $attachment->getSize() . " bytes)\n";
        
        // Save directly to disk
        $attachment->saveTo('/storage/attachments/' . $attachment->getFilename());
    }

    // Mark email as read
    $client->markAsSeen($uid);
}

// Disconnect
$client->disconnect();
```

---

### 2. Laravel Facade Usage

```php
use Maivio\Imap\Facades\Imap;

// Connect with runtime configuration
$client = Imap::connect([
    'host' => 'imap.mail.me.com',
    'port' => 993,
    'encryption' => 'ssl',
    'username' => 'user@icloud.com',
    'password' => config('services.icloud.app_password'),
]);

$client->selectMailbox('INBOX');
$uids = $client->searchSinceDays(7);

foreach ($uids as $uid) {
    $message = $client->getMessage($uid);
    // Process message...
}

$client->disconnect();
```

---

### 3. Microsoft 365 & Google Workspace (XOAUTH2)

For modern cloud providers where Basic Auth / Password login is disabled:

```php
use Maivio\Imap\Client\ImapClient;

$client = new ImapClient();
$client->connect('outlook.office365.com', 993, 'ssl');

// Authenticate using OAuth2 Bearer Access Token
$client->authenticateXOAuth2(
    username: 'user@company.com',
    accessToken: $oauthToken
);

$client->selectMailbox('INBOX');
```

---

### 4. Moving & Organizing Emails

```php
// Move message to Archive or Trash folder
$client->moveMessage('INBOX', $uid, 'Archive');

// Automatically detect the account's Trash folder
$trashFolder = $client->detectTrashFolder();
$client->moveMessage('INBOX', $uid, $trashFolder);
```

---

## Testing

Run tests with PHPUnit or Pest:

```bash
composer test
# or
vendor/bin/phpunit
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
