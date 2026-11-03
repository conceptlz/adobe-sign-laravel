<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Laravel\Console;

use Conceptlz\AdobeSign\Exception\AdobeSignException;
use Conceptlz\AdobeSign\Laravel\AdobeSignManager;
use Illuminate\Console\Command;

final class VerifyCommand extends Command
{
    protected $signature = 'adobe-sign:verify {--user= : Check this user\'s OAuth connection instead of the default client}';

    protected $description = 'Check Acrobat Sign credentials by fetching the authenticated user';

    public function handle(AdobeSignManager $manager): int
    {
        try {
            $user = $this->option('user');
            $client = is_string($user) && $user !== '' ? $manager->forUser($user) : $manager->client();
            $me = $client->users()->me();
        } catch (AdobeSignException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Connected to Acrobat Sign.');
        $this->components->twoColumnDetail('User', (string) $me->getEmail());
        $this->components->twoColumnDetail('Account admin', $me->getIsAccountAdmin() ? 'yes' : 'no');
        $this->components->twoColumnDetail('Account', (string) $me->getAccountId());

        return self::SUCCESS;
    }
}
