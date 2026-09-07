<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'rotana:create-admin';

    protected $description = 'Create a production administrator interactively';

    public function handle(): int
    {
        $data = ['name' => $this->ask('Name'), 'email' => $this->ask('Email'), 'password' => $this->secret('Password (at least 12 characters)')];
        $v = Validator::make($data, ['name' => 'required|string|max:190', 'email' => 'required|email|unique:users', 'password' => 'required|string|min:12|max:128']);
        if ($v->fails()) {
            $this->error($v->errors()->first());

            return 1;
        }
        DB::transaction(function () use ($data) {
            $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);
            $u = User::create($data + ['active' => true, 'all_branches' => true]);
            $u->assignRole('admin');
        });
        $this->info('Administrator created.');

        return 0;
    }
}
