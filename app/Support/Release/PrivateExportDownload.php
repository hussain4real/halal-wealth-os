<?php

declare(strict_types=1);

namespace App\Support\Release;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PrivateExportDownload extends BinaryFileResponse
{
    public function __construct(private readonly string $directory)
    {
        parent::__construct($directory.'/owner.json', 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], false, null, false, false);
        $this->setContentDisposition('attachment', 'halalos-owner-v1.json');
    }

    public function sendContent(): static
    {
        try {
            return parent::sendContent();
        } finally {
            $this->cleanup();
        }
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function cleanup(): void
    {
        if (is_file($this->directory.'/owner.json')) {
            unlink($this->directory.'/owner.json');
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }
}
