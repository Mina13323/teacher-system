<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generate a VAPID (RFC 8292) key pair for Web Push.
 *
 * Prints the values to paste into .env — the command never writes .env itself.
 */
class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'push:vapid-keys';

    protected $description = 'Generate a Web Push VAPID key pair (print values for .env)';

    public function handle(): int
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            $this->error('Unable to generate an EC key pair (openssl).');

            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($key);
        $rawPublic = "\x04" . $details['ec']['x'] . $details['ec']['y'];

        $this->info('Add these to your .env:');
        $this->line('VAPID_PUBLIC_KEY=' . self::b64url($rawPublic));
        $this->line('VAPID_PRIVATE_KEY=' . self::b64url($details['ec']['d']));
        $this->line('VAPID_SUBJECT=mailto:admin@example.com');
        $this->newLine();
        $this->comment('The public key is safe to expose to browsers; the private key never leaves the server.');

        return self::SUCCESS;
    }

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
