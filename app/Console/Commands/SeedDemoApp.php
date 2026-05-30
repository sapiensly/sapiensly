<?php

namespace App\Console\Commands;

use Database\Seeders\DemoAppSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('apps:seed-demo')]
#[Description('Seed a "mini-crm" demo App with 20 sample records for the first user.')]
class SeedDemoApp extends Command
{
    public function handle(): int
    {
        $this->call('db:seed', ['--class' => DemoAppSeeder::class]);

        return self::SUCCESS;
    }
}
