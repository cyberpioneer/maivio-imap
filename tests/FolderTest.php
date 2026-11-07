<?php

declare(strict_types=1);

namespace Maivio\Imap\Tests;

use Maivio\Imap\DTO\Folder;

class FolderTest extends TestCase
{
    public function test_folder_detects_trash_flag_and_localized_names(): void
    {
        $flaggedTrash = new Folder('Special Trash', 'Special Trash', '/', ['\\HasNoChildren', '\\Trash']);
        $this->assertTrue($flaggedTrash->isTrash());

        $germanTrash = new Folder('Papierkorb', 'Papierkorb', '/', ['\\HasNoChildren']);
        $this->assertTrue($germanTrash->isTrash());

        $inbox = new Folder('INBOX', 'INBOX', '/', ['\\HasNoChildren']);
        $this->assertFalse($inbox->isTrash());
    }

    public function test_folder_detects_sent_and_drafts(): void
    {
        $sent = new Folder('Gesendete Elemente', 'Gesendete Elemente', '/', []);
        $this->assertTrue($sent->isSent());

        $drafts = new Folder('Entwürfe', 'Entw&APw-rfe', '/', ['\\Drafts']);
        $this->assertTrue($drafts->isDrafts());
    }
}
